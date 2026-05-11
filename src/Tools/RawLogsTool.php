<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

class RawLogsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'drip.raw_logs.GET';
    }

    public function getDescription(): string
    {
        return 'GET /drip/raw_logs - Listet und liest GoCardless Raw-Log-Dateien (JSON). action=list zeigt verfügbare Dateien, action=read liest eine Datei (file=Dateiname). action=read_transactions liest nur Transaktionen eines Accounts (file + account_id).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'read', 'read_transactions'],
                    'description' => 'list = verfügbare Log-Dateien auflisten, read = Datei komplett lesen, read_transactions = Transaktionen eines Accounts lesen',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Dateiname (nur Filename, kein Pfad). Erforderlich bei action=read und action=read_transactions.',
                ],
                'account_id' => [
                    'type' => 'string',
                    'description' => 'GoCardless Account-ID. Optional bei action=read_transactions, filtert auf einen Account.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max. Anzahl Transaktionen bei read_transactions. Standard: 50.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Offset für Pagination bei read_transactions. Standard: 0.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $action = $arguments['action'] ?? 'list';
            $logDir = storage_path('logs');

            return match ($action) {
                'list' => $this->listFiles($logDir),
                'read' => $this->readFile($logDir, $arguments['file'] ?? null),
                'read_transactions' => $this->readTransactions(
                    $logDir,
                    $arguments['file'] ?? null,
                    $arguments['account_id'] ?? null,
                    (int) ($arguments['limit'] ?? 50),
                    (int) ($arguments['offset'] ?? 0),
                ),
                default => ToolResult::error('INVALID_ACTION', "Unbekannte action: {$action}"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    protected function listFiles(string $logDir): ToolResult
    {
        $files = glob($logDir . '/gocardless-raw-*.json');

        if (empty($files)) {
            return ToolResult::success([
                'files' => [],
                'hint' => 'Keine Raw-Log-Dateien vorhanden. Erstelle eine mit: php artisan drip:debug-transaction --raw --team=9',
            ]);
        }

        rsort($files);
        $result = [];

        foreach ($files as $f) {
            $data = json_decode(file_get_contents($f), true);
            $txCount = 0;
            $accounts = [];
            if (is_array($data)) {
                foreach ($data as $accountId => $transactions) {
                    $count = is_array($transactions) ? count($transactions) : 0;
                    $txCount += $count;
                    $accounts[] = ['account_id' => $accountId, 'transaction_count' => $count];
                }
            }

            $result[] = [
                'filename' => basename($f),
                'size_kb' => round(filesize($f) / 1024, 1),
                'created_at' => date('Y-m-d H:i:s', filemtime($f)),
                'total_transactions' => $txCount,
                'accounts' => $accounts,
            ];
        }

        return ToolResult::success(['files' => $result]);
    }

    protected function readFile(string $logDir, ?string $filename): ToolResult
    {
        if (!$filename) {
            return ToolResult::error('MISSING_PARAM', 'file ist erforderlich bei action=read');
        }

        // Security: nur Dateiname, kein Pfad
        $filename = basename($filename);
        $path = $logDir . '/' . $filename;

        if (!file_exists($path)) {
            return ToolResult::error('NOT_FOUND', "Datei nicht gefunden: {$filename}");
        }

        $data = json_decode(file_get_contents($path), true);
        if (!$data) {
            return ToolResult::error('PARSE_ERROR', 'JSON konnte nicht geparst werden');
        }

        // Zusammenfassung + Feldanalyse
        $summary = [];
        $allFields = [];

        foreach ($data as $accountId => $transactions) {
            $accountSummary = [
                'account_id' => $accountId,
                'transaction_count' => count($transactions),
                'fields_present' => [],
            ];

            foreach ($transactions as $tx) {
                foreach (array_keys($tx) as $key) {
                    $allFields[$key] = ($allFields[$key] ?? 0) + 1;
                }
            }

            $accountSummary['fields_present'] = array_keys($allFields);
            $accountSummary['sample_transaction'] = $transactions[0] ?? null;
            $summary[] = $accountSummary;
        }

        $totalTx = array_sum(array_map(fn ($s) => $s['transaction_count'], $summary));

        // Feldabdeckung berechnen
        $fieldCoverage = [];
        foreach ($allFields as $field => $count) {
            $fieldCoverage[$field] = [
                'count' => $count,
                'percentage' => round($count / $totalTx * 100),
            ];
        }
        arsort($fieldCoverage);

        return ToolResult::success([
            'filename' => $filename,
            'total_transactions' => $totalTx,
            'accounts' => $summary,
            'field_coverage' => $fieldCoverage,
        ]);
    }

    protected function readTransactions(string $logDir, ?string $filename, ?string $accountId, int $limit, int $offset): ToolResult
    {
        if (!$filename) {
            return ToolResult::error('MISSING_PARAM', 'file ist erforderlich bei action=read_transactions');
        }

        $filename = basename($filename);
        $path = $logDir . '/' . $filename;

        if (!file_exists($path)) {
            return ToolResult::error('NOT_FOUND', "Datei nicht gefunden: {$filename}");
        }

        $data = json_decode(file_get_contents($path), true);
        if (!$data) {
            return ToolResult::error('PARSE_ERROR', 'JSON konnte nicht geparst werden');
        }

        $transactions = [];

        if ($accountId && isset($data[$accountId])) {
            foreach ($data[$accountId] as $tx) {
                $tx['_account_id'] = $accountId;
                $transactions[] = $tx;
            }
        } else {
            foreach ($data as $accId => $txList) {
                foreach ($txList as $tx) {
                    $tx['_account_id'] = $accId;
                    $transactions[] = $tx;
                }
            }
        }

        $total = count($transactions);
        $slice = array_slice($transactions, $offset, $limit);

        return ToolResult::success([
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'returned' => count($slice),
            'has_more' => ($offset + $limit) < $total,
            'transactions' => $slice,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'debug',
            'tags' => ['drip', 'gocardless', 'raw', 'logs', 'debug'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
