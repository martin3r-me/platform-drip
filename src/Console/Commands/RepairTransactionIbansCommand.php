<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Models\BankTransaction;

class RepairTransactionIbansCommand extends Command
{
    protected $signature = 'drip:repair-ibans
                                    {--team= : Team ID (required)}
                                    {--dry : Show what would be fixed without saving}';

    protected $description = 'Repair counterparty_iban from additional_information for all transactions';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        $dry = (bool) $this->option('dry');

        if (!$teamId) {
            $this->error('--team is required');
            return 1;
        }

        // Finde Transaktionen ohne counterparty_iban aber mit additional_information
        $transactions = BankTransaction::where('team_id', $teamId)
            ->whereNull('counterparty_iban')
            ->whereNotNull('additional_information')
            ->get();

        $this->info("Found {$transactions->count()} transactions without counterparty_iban");

        if ($transactions->isEmpty()) {
            return 0;
        }

        // Ermittle die eigene Konto-IBAN (die häufigste in debtor/creditor_account_iban)
        $ownIban = BankTransaction::where('team_id', $teamId)
            ->whereNotNull('debtor_account_iban')
            ->groupBy('debtor_account_iban')
            ->orderByRaw('COUNT(*) DESC')
            ->value('debtor_account_iban');

        if ($ownIban) {
            $this->info("Detected own IBAN: {$ownIban}");
        }

        $fixed = 0;
        $skipped = 0;

        foreach ($transactions as $tx) {
            $parsed = $this->parseAdditionalInformation($tx->additional_information);

            if (!$parsed['iban']) {
                $skipped++;
                continue;
            }

            // Wenn geparste IBAN = eigene IBAN → keine Gegenpartei-IBAN verfügbar
            if ($parsed['iban'] === $ownIban) {
                $skipped++;
                continue;
            }

            $updates = ['counterparty_iban' => $parsed['iban']];

            // counterparty_name setzen wenn leer
            if (!$tx->counterparty_name) {
                $name = $tx->direction === 'debit'
                    ? ($tx->creditor_name ?? $parsed['name'])
                    : ($tx->debtor_name ?? $parsed['name']);
                if ($name) {
                    $updates['counterparty_name'] = $name;
                }
            }

            $this->line("  [{$tx->transaction_id}] {$tx->booked_at?->format('Y-m-d')} | {$tx->amount} {$tx->currency}");
            $this->line("    → counterparty_iban: {$parsed['iban']}" . (isset($updates['counterparty_name']) ? " | name: {$updates['counterparty_name']}" : ''));

            if (!$dry) {
                $tx->update($updates);
            }
            $fixed++;
        }

        $this->newLine();
        $this->info(($dry ? '[DRY RUN] Would fix' : 'Fixed') . ": {$fixed} transactions (skipped: {$skipped})");

        return 0;
    }

    protected function parseAdditionalInformation(?string $info): array
    {
        $result = ['name' => null, 'bic' => null, 'iban' => null, 'purpose' => null];

        if (!$info) {
            return $result;
        }

        $lines = preg_split('/\r?\n/', trim($info));
        $remaining = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;

            if (!$result['iban'] && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $line)) {
                $result['iban'] = $line;
                continue;
            }

            if (!$result['bic'] && preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $line)) {
                $result['bic'] = $line;
                continue;
            }

            $remaining[] = $line;
        }

        if (!empty($remaining)) {
            $result['name'] = array_shift($remaining);
        }
        if (!empty($remaining)) {
            $result['purpose'] = implode(' ', $remaining);
        }

        return $result;
    }
}
