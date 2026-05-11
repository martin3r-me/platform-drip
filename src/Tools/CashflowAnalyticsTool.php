<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Drip\Services\CashflowSnapshotService;
use Platform\Drip\Tools\Concerns\ResolvesDripTeam;

class CashflowAnalyticsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesDripTeam;

    public function getName(): string
    {
        return 'drip.cashflow_analytics';
    }

    public function getDescription(): string
    {
        return 'Cashflow-Ist-Analyse aus vorberechneten Snapshots. action=compare (zwei Monate vergleichen), action=trend (Zeitreihe N Monate), action=top (Top N Kategorien/Counterparties), action=weekly (Wochen-Drill-Down), action=resolve_counterparty (Name → Hash fuer weitere Abfragen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['compare', 'trend', 'top', 'weekly', 'resolve_counterparty'],
                    'description' => 'Aktion: compare, trend, top, weekly, resolve_counterparty.',
                ],
                'dimension' => [
                    'type' => 'string',
                    'enum' => ['category', 'counterparty'],
                    'description' => 'Dimension: category oder counterparty.',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Kategorie-ID (fuer category-Dimension).',
                ],
                'counterparty_hash' => [
                    'type' => 'string',
                    'description' => 'Counterparty-Hash (fuer counterparty-Dimension). Nutze resolve_counterparty um den Hash zu ermitteln.',
                ],
                'counterparty_name' => [
                    'type' => 'string',
                    'description' => 'Counterparty-Name (fuer resolve_counterparty).',
                ],
                'period_a' => [
                    'type' => 'string',
                    'description' => 'Erste Periode (YYYY-MM, fuer compare).',
                ],
                'period_b' => [
                    'type' => 'string',
                    'description' => 'Zweite Periode (YYYY-MM, fuer compare).',
                ],
                'period_key' => [
                    'type' => 'string',
                    'description' => 'Periode (YYYY-MM, fuer top/weekly).',
                ],
                'direction' => [
                    'type' => 'string',
                    'enum' => ['debit', 'credit'],
                    'description' => 'Richtung: debit (Ausgaben) oder credit (Einnahmen). Default: debit.',
                ],
                'months' => [
                    'type' => 'integer',
                    'description' => 'Anzahl Monate fuer trend (default 6).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Anzahl Ergebnisse fuer top (default 10).',
                ],
                'bank_account_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Bankkonto-ID. Ohne = teamweit.',
                ],
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int) $resolved['team_id'];
            $action = $arguments['action'] ?? null;

            if (!$action) {
                return ToolResult::error('VALIDATION_ERROR', 'action ist erforderlich.');
            }

            $svc = app(CashflowSnapshotService::class);

            return match ($action) {
                'compare' => $this->compare($arguments, $teamId, $svc),
                'trend' => $this->trend($arguments, $teamId, $svc),
                'top' => $this->top($arguments, $teamId, $svc),
                'weekly' => $this->weekly($arguments, $teamId, $svc),
                'resolve_counterparty' => $this->resolveCounterparty($arguments, $teamId, $svc),
                default => ToolResult::error('VALIDATION_ERROR', "Unbekannte Aktion: {$action}"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    protected function compare(array $arguments, int $teamId, CashflowSnapshotService $svc): ToolResult
    {
        $dimension = $arguments['dimension'] ?? null;
        if (!$dimension) {
            return ToolResult::error('VALIDATION_ERROR', 'dimension ist erforderlich.');
        }

        $periodA = $arguments['period_a'] ?? null;
        $periodB = $arguments['period_b'] ?? null;
        if (!$periodA || !$periodB) {
            return ToolResult::error('VALIDATION_ERROR', 'period_a und period_b sind erforderlich.');
        }

        $dimensionId = $this->extractDimensionId($arguments, $dimension);
        if ($dimensionId === null) {
            return $this->missingDimensionIdError($dimension);
        }

        $result = $svc->compare(
            $teamId,
            $periodA,
            $periodB,
            $dimension,
            $dimensionId,
            $arguments['direction'] ?? 'debit',
            $arguments['bank_account_id'] ?? null,
        );

        return ToolResult::success($result);
    }

    protected function trend(array $arguments, int $teamId, CashflowSnapshotService $svc): ToolResult
    {
        $dimension = $arguments['dimension'] ?? null;
        if (!$dimension) {
            return ToolResult::error('VALIDATION_ERROR', 'dimension ist erforderlich.');
        }

        $dimensionId = $this->extractDimensionId($arguments, $dimension);
        if ($dimensionId === null) {
            return $this->missingDimensionIdError($dimension);
        }

        $data = $svc->trend(
            $teamId,
            $dimension,
            $dimensionId,
            $arguments['months'] ?? 6,
            $arguments['direction'] ?? 'debit',
            $arguments['bank_account_id'] ?? null,
        );

        return ToolResult::success([
            'data' => $data,
            'dimension' => $dimension,
            'months' => $arguments['months'] ?? 6,
            'team_id' => $teamId,
        ]);
    }

    protected function top(array $arguments, int $teamId, CashflowSnapshotService $svc): ToolResult
    {
        $dimension = $arguments['dimension'] ?? null;
        if (!$dimension) {
            return ToolResult::error('VALIDATION_ERROR', 'dimension ist erforderlich.');
        }

        $periodKey = $arguments['period_key'] ?? now()->format('Y-m');

        $data = $svc->top(
            $teamId,
            $dimension,
            $periodKey,
            $arguments['direction'] ?? 'debit',
            $arguments['limit'] ?? 10,
            $arguments['bank_account_id'] ?? null,
        );

        return ToolResult::success([
            'data' => $data,
            'dimension' => $dimension,
            'period_key' => $periodKey,
            'total' => count($data),
            'team_id' => $teamId,
        ]);
    }

    protected function weekly(array $arguments, int $teamId, CashflowSnapshotService $svc): ToolResult
    {
        $dimension = $arguments['dimension'] ?? null;
        if (!$dimension) {
            return ToolResult::error('VALIDATION_ERROR', 'dimension ist erforderlich.');
        }

        $periodKey = $arguments['period_key'] ?? null;
        if (!$periodKey) {
            return ToolResult::error('VALIDATION_ERROR', 'period_key ist erforderlich (YYYY-MM).');
        }

        $dimensionId = $this->extractDimensionId($arguments, $dimension);
        if ($dimensionId === null) {
            return $this->missingDimensionIdError($dimension);
        }

        $data = $svc->weeklyBreakdown(
            $teamId,
            $dimension,
            $dimensionId,
            $periodKey,
            $arguments['direction'] ?? 'debit',
            $arguments['bank_account_id'] ?? null,
        );

        return ToolResult::success([
            'data' => $data,
            'dimension' => $dimension,
            'period_key' => $periodKey,
            'team_id' => $teamId,
        ]);
    }

    protected function resolveCounterparty(array $arguments, int $teamId, CashflowSnapshotService $svc): ToolResult
    {
        $name = $arguments['counterparty_name'] ?? null;
        if (!$name) {
            return ToolResult::error('VALIDATION_ERROR', 'counterparty_name ist erforderlich.');
        }

        $hash = $svc->findCounterpartyHash($teamId, $name);

        if (!$hash) {
            return ToolResult::error('NOT_FOUND', "Kein Counterparty mit Name '{$name}' gefunden.");
        }

        return ToolResult::success([
            'counterparty_name' => $name,
            'counterparty_hash' => $hash,
            'team_id' => $teamId,
        ]);
    }

    // ── Helpers ──

    private function extractDimensionId(array $arguments, string $dimension): int|string|null
    {
        if ($dimension === 'category') {
            return isset($arguments['category_id']) ? (int) $arguments['category_id'] : null;
        }

        return $arguments['counterparty_hash'] ?? null;
    }

    private function missingDimensionIdError(string $dimension): ToolResult
    {
        if ($dimension === 'category') {
            return ToolResult::error('VALIDATION_ERROR', 'category_id ist erforderlich fuer dimension=category.');
        }

        return ToolResult::error('VALIDATION_ERROR', 'counterparty_hash ist erforderlich fuer dimension=counterparty. Nutze action=resolve_counterparty um den Hash zu ermitteln.');
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'analytics',
            'tags' => ['drip', 'cashflow', 'analytics'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'none',
            'idempotent' => true,
        ];
    }
}
