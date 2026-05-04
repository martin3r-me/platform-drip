<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\Requisition;
use Platform\Drip\Services\GoCardlessService;

class DebugTransactionCommand extends Command
{
    protected $signature = 'drip:debug-transaction
                                    {--team= : Team ID (required)}
                                    {--tx= : Transaction ID to inspect stored data}
                                    {--raw : Fetch and dump raw API response (last 7 days)}
                                    {--days=7 : Number of days to fetch for --raw}';

    protected $description = 'Debug transactions - inspect stored data or raw GoCardless API response';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        if (!$teamId) {
            $this->error('--team is required');
            return 1;
        }

        if ($this->option('tx')) {
            return $this->showStoredTransaction($teamId, $this->option('tx'));
        }

        if ($this->option('raw')) {
            return $this->showRawApiData($teamId);
        }

        $this->error('Specify --tx=<id> to inspect a stored transaction, or --raw to dump API response');
        return 1;
    }

    protected function showStoredTransaction(int $teamId, string $txId): int
    {
        $tx = BankTransaction::where('team_id', $teamId)
            ->where('transaction_id', $txId)
            ->first();

        if (!$tx) {
            $tx = BankTransaction::where('team_id', $teamId)
                ->where('id', $txId)
                ->first();
        }

        if (!$tx) {
            $this->error("Transaction not found: {$txId}");
            return 1;
        }

        $this->info("=== Stored Transaction: {$tx->transaction_id} ===");
        $this->table(['Field', 'Value'], [
            ['id', $tx->id],
            ['direction', $tx->direction],
            ['amount', $tx->amount . ' ' . $tx->currency],
            ['booked_at', $tx->booked_at?->format('Y-m-d')],
            ['debtor_name', $tx->debtor_name ?? 'NULL'],
            ['debtor_account_iban', $tx->debtor_account_iban ?? 'NULL'],
            ['debtor_agent', $tx->debtor_agent ?? 'NULL'],
            ['creditor_name', $tx->creditor_name ?? 'NULL'],
            ['creditor_account_iban', $tx->creditor_account_iban ?? 'NULL'],
            ['creditor_agent', $tx->creditor_agent ?? 'NULL'],
            ['counterparty_name', $tx->counterparty_name ?? 'NULL'],
            ['counterparty_iban', $tx->counterparty_iban ?? 'NULL'],
            ['remittance_information', $tx->remittance_information ?? 'NULL'],
            ['additional_information', $tx->additional_information ?? 'NULL'],
            ['reference', $tx->reference ?? 'NULL'],
        ]);

        return 0;
    }

    protected function showRawApiData(int $teamId): int
    {
        $gc = new GoCardlessService($teamId);
        $token = $gc->getAccessToken();

        if (!$token) {
            $this->error('Could not get access token');
            return 1;
        }

        $days = (int) $this->option('days');
        $requisitions = Requisition::where('team_id', $teamId)
            ->whereNotNull('linked_at')
            ->where('access_expires_at', '>', now())
            ->get();

        if ($requisitions->isEmpty()) {
            $this->error('No active requisitions found');
            return 1;
        }

        $allRawData = [];

        foreach ($requisitions as $requisition) {
            $accounts = $requisition->accounts ?? [];

            foreach ($accounts as $accountId) {
                $this->info("Fetching account: {$accountId}");

                $response = Http::withToken($token)
                    ->get("https://bankaccountdata.gocardless.com/api/v2/accounts/{$accountId}/transactions/", [
                        'date_from' => now()->subDays($days)->format('Y-m-d'),
                    ]);

                if (!$response->successful()) {
                    $this->error("  API Error {$response->status()}: " . $response->body());
                    continue;
                }

                $data = $response->json();
                $booked = $data['transactions']['booked'] ?? [];
                $this->info("  Got " . count($booked) . " booked transactions");

                $allRawData[$accountId] = $booked;

                // Zeige erste 3 Transaktionen
                foreach (array_slice($booked, 0, 3) as $i => $tx) {
                    $this->newLine();
                    $txIdLabel = $tx['transactionId'] ?? 'no-id';
                    $this->info("  --- TX #{$i}: {$txIdLabel} ---");
                    $this->line(json_encode($tx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }

                if (count($booked) > 3) {
                    $this->info("  ... and " . (count($booked) - 3) . " more");
                }
            }
        }

        // Vollständige Raw-Response in Storage schreiben
        $path = storage_path('logs/gocardless-raw-' . now()->format('Y-m-d_His') . '.json');
        file_put_contents($path, json_encode($allRawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();
        $this->info("Full raw response saved to: {$path}");

        return 0;
    }
}
