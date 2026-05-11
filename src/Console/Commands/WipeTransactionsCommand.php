<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankTransaction;

class WipeTransactionsCommand extends Command
{
    protected $signature = 'drip:wipe-transactions
        {--team= : Team ID (required)}
        {--force : Ohne Rückfrage ausführen}';

    protected $description = 'Löscht ALLE Transaktionen eines Teams und setzt Sync-Timestamps zurück (für sauberen Neustart)';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        if (!$teamId) {
            $this->error('--team ist erforderlich');
            return 1;
        }

        $txCount = BankTransaction::where('team_id', $teamId)->count();
        $accounts = BankAccount::where('team_id', $teamId)->get();

        $this->warn("Team {$teamId}: {$txCount} Transaktionen, {$accounts->count()} Bankkonten");

        if ($txCount === 0) {
            $this->info('Keine Transaktionen vorhanden.');
            return 0;
        }

        $this->table(
            ['Konto', 'IBAN', 'Transaktionen', 'Letzter Sync'],
            $accounts->map(fn ($a) => [
                $a->name,
                $a->iban ?? '-',
                BankTransaction::where('bank_account_id', $a->id)->count(),
                $a->last_transactions_synced_at?->format('d.m.Y H:i') ?? 'Nie',
            ])
        );

        if (!$this->option('force')) {
            if (!$this->confirm("ALLE {$txCount} Transaktionen für Team {$teamId} löschen und Sync-Timestamps zurücksetzen?")) {
                $this->info('Abgebrochen.');
                return 0;
            }
        }

        // Transaktionen löschen
        $deleted = BankTransaction::where('team_id', $teamId)->forceDelete();
        $this->info("Gelöscht: {$deleted} Transaktionen");

        // Sync-Timestamps zurücksetzen
        BankAccount::where('team_id', $teamId)->update([
            'last_transactions_synced_at' => null,
        ]);
        $this->info('Sync-Timestamps aller Konten zurückgesetzt.');

        $this->newLine();
        $this->info('Nächster Schritt: php artisan drip:update-bank-data --team=' . $teamId);
        $this->info('Damit werden alle Transaktionen mit deterministischen IDs neu geladen.');

        return 0;
    }
}
