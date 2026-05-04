<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Models\BankTransaction;

class RepairTransactionIbansCommand extends Command
{
    protected $signature = 'drip:repair-ibans
                                    {--team= : Team ID (required)}
                                    {--dry : Show what would be fixed without saving}';

    protected $description = 'Repair IBAN assignment: swap debtor/creditor IBAN based on direction';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        $dry = (bool) $this->option('dry');

        if (!$teamId) {
            $this->error('--team is required');
            return 1;
        }

        // Finde Transaktionen mit falscher IBAN-Zuordnung:
        // debit + debtor_account_iban gesetzt + creditor_account_iban leer
        $debitBroken = BankTransaction::where('team_id', $teamId)
            ->where('direction', 'debit')
            ->whereNotNull('debtor_account_iban')
            ->whereNull('creditor_account_iban')
            ->get();

        // credit + creditor_account_iban gesetzt + debtor_account_iban leer
        $creditBroken = BankTransaction::where('team_id', $teamId)
            ->where('direction', 'credit')
            ->whereNotNull('creditor_account_iban')
            ->whereNull('debtor_account_iban')
            ->get();

        $total = $debitBroken->count() + $creditBroken->count();
        $this->info("Found {$debitBroken->count()} debit + {$creditBroken->count()} credit transactions with misassigned IBANs");

        if ($total === 0) {
            $this->info('Nothing to repair.');
            return 0;
        }

        $fixed = 0;

        foreach ($debitBroken as $tx) {
            $parsed = $this->parseAdditionalInformation($tx->additional_information);

            $this->line("  [{$tx->transaction_id}] {$tx->booked_at?->format('Y-m-d')} | {$tx->amount} {$tx->currency}");
            $this->line("    debtor_account_iban: {$tx->debtor_account_iban} → creditor_account_iban");

            if (!$dry) {
                $updates = [
                    'creditor_account_iban' => $tx->debtor_account_iban,
                    'debtor_account_iban' => null,
                    'counterparty_iban' => $tx->debtor_account_iban,
                ];

                // BIC aus additional_information zuweisen wenn möglich
                if (!$tx->creditor_agent && $parsed['bic']) {
                    $updates['creditor_agent'] = $parsed['bic'];
                    $this->line("    creditor_agent: {$parsed['bic']}");
                }

                // counterparty_name setzen wenn leer
                if (!$tx->counterparty_name) {
                    $name = $tx->creditor_name ?? $parsed['name'] ?? null;
                    if ($name) {
                        $updates['counterparty_name'] = $name;
                        $this->line("    counterparty_name: {$name}");
                    }
                }

                $tx->update($updates);
            }
            $fixed++;
        }

        foreach ($creditBroken as $tx) {
            $parsed = $this->parseAdditionalInformation($tx->additional_information);

            $this->line("  [{$tx->transaction_id}] {$tx->booked_at?->format('Y-m-d')} | {$tx->amount} {$tx->currency}");
            $this->line("    creditor_account_iban: {$tx->creditor_account_iban} → debtor_account_iban");

            if (!$dry) {
                $updates = [
                    'debtor_account_iban' => $tx->creditor_account_iban,
                    'creditor_account_iban' => null,
                    'counterparty_iban' => $tx->creditor_account_iban,
                ];

                if (!$tx->debtor_agent && $parsed['bic']) {
                    $updates['debtor_agent'] = $parsed['bic'];
                    $this->line("    debtor_agent: {$parsed['bic']}");
                }

                if (!$tx->counterparty_name) {
                    $name = $tx->debtor_name ?? $parsed['name'] ?? null;
                    if ($name) {
                        $updates['counterparty_name'] = $name;
                        $this->line("    counterparty_name: {$name}");
                    }
                }

                $tx->update($updates);
            }
            $fixed++;
        }

        $this->newLine();
        $this->info(($dry ? '[DRY RUN] Would fix' : 'Fixed') . ": {$fixed} transactions");

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
