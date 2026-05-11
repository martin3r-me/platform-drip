<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Models\BankTransaction;
use Illuminate\Support\Facades\DB;

class DeduplicateTransactionsCommand extends Command
{
    protected $signature = 'drip:deduplicate-transactions
        {--team= : Team ID}
        {--dry : Nur anzeigen, nicht löschen}';

    protected $description = 'Entfernt doppelte Transaktionen (gleiche transaction_id + bank_account_id)';

    public function handle(): int
    {
        $teamId = $this->option('team');
        $dry = $this->option('dry');

        if (!$teamId) {
            $this->error('--team ist erforderlich');
            return 1;
        }

        $this->info("Suche Duplikate für Team {$teamId}...");

        // Alle Transaktionen des Teams laden
        $transactions = BankTransaction::where('team_id', $teamId)
            ->orderBy('bank_account_id')
            ->orderBy('transaction_id')
            ->orderBy('updated_at', 'desc') // Neueste zuerst
            ->get(['id', 'transaction_id', 'bank_account_id', 'amount', 'booked_at', 'counterparty_name', 'created_at', 'updated_at']);

        $this->info("Gefunden: {$transactions->count()} Transaktionen");

        // Gruppieren nach transaction_id + bank_account_id
        $grouped = $transactions->groupBy(function ($t) {
            return $t->bank_account_id . '|' . $t->transaction_id;
        });

        $duplicateGroups = $grouped->filter(fn ($group) => $group->count() > 1);

        if ($duplicateGroups->isEmpty()) {
            $this->info('Keine Duplikate gefunden.');
            return 0;
        }

        $totalDuplicates = $duplicateGroups->sum(fn ($group) => $group->count() - 1);
        $this->warn("Gefunden: {$duplicateGroups->count()} Gruppen mit {$totalDuplicates} Duplikaten");

        // Auch nach Hash-Duplikaten suchen (gleiche Daten, verschiedene tx_ IDs)
        $txPrefixed = $transactions->filter(fn ($t) => str_starts_with($t->transaction_id, 'tx_'));
        if ($txPrefixed->isNotEmpty()) {
            $this->warn("Davon {$txPrefixed->count()} mit generiertem tx_* ID (potenzielle Duplikate durch fehlende transactionId)");
        }

        $this->table(
            ['Gruppe', 'Transaction ID', 'Account', 'Anzahl', 'Behalten (neueste)'],
            $duplicateGroups->take(20)->map(function ($group, $key) {
                $keep = $group->first(); // neueste (sortiert nach updated_at desc)
                return [
                    $key,
                    \Illuminate\Support\Str::limit($keep->transaction_id, 30),
                    $keep->bank_account_id,
                    $group->count(),
                    $keep->id . ' (' . ($keep->updated_at?->format('d.m.Y') ?? '-') . ')',
                ];
            })
        );

        if ($duplicateGroups->count() > 20) {
            $this->line("... und " . ($duplicateGroups->count() - 20) . " weitere Gruppen");
        }

        if ($dry) {
            $this->info("[DRY RUN] Würde {$totalDuplicates} Duplikate löschen.");
            return 0;
        }

        if (!$this->confirm("Sollen {$totalDuplicates} Duplikate gelöscht werden? (Neueste Einträge werden behalten)")) {
            $this->info('Abgebrochen.');
            return 0;
        }

        $deleted = 0;
        foreach ($duplicateGroups as $group) {
            $keep = $group->first(); // neueste behalten
            $removeIds = $group->skip(1)->pluck('id');
            BankTransaction::whereIn('id', $removeIds)->forceDelete();
            $deleted += $removeIds->count();
        }

        $this->info("Erledigt: {$deleted} Duplikate gelöscht.");

        // Zusätzlich: tx_* Duplikate finden (verschiedene IDs, aber gleiche Daten)
        $this->info('');
        $this->info('Suche nach tx_*-Duplikaten (generierte IDs mit gleichen Daten)...');

        $txGenerated = BankTransaction::where('team_id', $teamId)
            ->where('transaction_id', 'like', 'tx_%')
            ->orderBy('bank_account_id')
            ->orderBy('booked_at')
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'transaction_id', 'bank_account_id', 'amount', 'direction', 'booked_at', 'counterparty_name', 'updated_at']);

        if ($txGenerated->isEmpty()) {
            $this->info('Keine tx_*-Transaktionen gefunden.');
            return 0;
        }

        // Gruppieren nach inhaltlichem Fingerprint
        $contentGroups = $txGenerated->groupBy(function ($t) {
            return $t->bank_account_id . '|' . ($t->booked_at?->format('Y-m-d') ?? '') . '|' . $t->amount . '|' . $t->direction . '|' . ($t->counterparty_name ?? '');
        });

        $contentDuplicates = $contentGroups->filter(fn ($group) => $group->count() > 1);

        if ($contentDuplicates->isEmpty()) {
            $this->info('Keine inhaltlichen tx_*-Duplikate gefunden.');
            return 0;
        }

        $totalContentDups = $contentDuplicates->sum(fn ($group) => $group->count() - 1);
        $this->warn("Gefunden: {$contentDuplicates->count()} Gruppen mit {$totalContentDups} inhaltlichen Duplikaten (tx_* IDs)");

        $this->table(
            ['Fingerprint', 'Anzahl', 'Datum', 'Betrag', 'Gegenpartei'],
            $contentDuplicates->take(20)->map(function ($group, $key) {
                $sample = $group->first();
                return [
                    \Illuminate\Support\Str::limit($key, 50),
                    $group->count(),
                    $sample->booked_at?->format('d.m.Y') ?? '-',
                    $sample->amount,
                    \Illuminate\Support\Str::limit($sample->counterparty_name ?? '-', 25),
                ];
            })
        );

        if (!$this->confirm("Sollen {$totalContentDups} inhaltliche tx_*-Duplikate gelöscht werden?")) {
            return 0;
        }

        $deleted2 = 0;
        foreach ($contentDuplicates as $group) {
            $keep = $group->first();
            $removeIds = $group->skip(1)->pluck('id');
            BankTransaction::whereIn('id', $removeIds)->forceDelete();
            $deleted2 += $removeIds->count();
        }

        $this->info("Erledigt: {$deleted2} inhaltliche Duplikate gelöscht.");

        return 0;
    }
}
