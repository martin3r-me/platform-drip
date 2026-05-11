<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Models\BankTransaction;

class DeduplicateTransactionsCommand extends Command
{
    protected $signature = 'drip:deduplicate-transactions
        {--team= : Team ID}
        {--dry : Nur anzeigen, nicht löschen}
        {--force : Ohne Rückfrage ausführen}';

    protected $description = 'Entfernt doppelte Transaktionen anhand inhaltlicher Übereinstimmung (Datum + Betrag + Gegenpartei + Verwendungszweck)';

    public function handle(): int
    {
        $teamId = $this->option('team');
        $dry = $this->option('dry');

        if (!$teamId) {
            $this->error('--team ist erforderlich');
            return 1;
        }

        $this->info("Lade Transaktionen für Team {$teamId}...");

        // Alle Transaktionen laden — Felder sind verschlüsselt, daher alles in PHP
        $transactions = BankTransaction::where('team_id', $teamId)
            ->orderBy('updated_at', 'desc')
            ->get();

        $count = $transactions->count();
        $this->info("Gefunden: {$count} Transaktionen");

        if ($count === 0) {
            return 0;
        }

        // Inhaltlicher Fingerprint: Datum + Betrag + Richtung + Konto + Verwendungszweck
        $this->info('Gruppiere nach inhaltlichem Fingerprint...');

        $grouped = $transactions->groupBy(function ($t) {
            return implode('|', [
                $t->bank_account_id,
                $t->booked_at?->format('Y-m-d') ?? '',
                (string) $t->amount,
                $t->direction ?? '',
                $t->reference ?? $t->remittance_information ?? '',
            ]);
        });

        $duplicateGroups = $grouped->filter(fn ($group) => $group->count() > 1);

        if ($duplicateGroups->isEmpty()) {
            $this->info('Keine Duplikate gefunden.');
            return 0;
        }

        $totalDuplicates = $duplicateGroups->sum(fn ($group) => $group->count() - 1);
        $uniqueKeep = $duplicateGroups->count();

        $this->warn("Gefunden: {$uniqueKeep} Gruppen mit insgesamt {$totalDuplicates} Duplikaten");
        $this->line("(Von {$count} Transaktionen bleiben " . ($count - $totalDuplicates) . " übrig)");
        $this->line('');

        // Tabelle mit Beispielen
        $this->table(
            ['Datum', 'Betrag', 'Richtung', 'Gegenpartei', 'Anzahl', 'Duplikate'],
            $duplicateGroups->take(25)->map(function ($group) {
                $sample = $group->first();
                return [
                    $sample->booked_at?->format('d.m.Y') ?? '-',
                    number_format((float) $sample->amount, 2, ',', '.') . ' ' . ($sample->currency ?? 'EUR'),
                    $sample->direction === 'credit' ? 'Einnahme' : 'Ausgabe',
                    \Illuminate\Support\Str::limit($sample->counterparty_name ?? $sample->creditor_name ?? $sample->debtor_name ?? '-', 30),
                    $group->count(),
                    $group->count() - 1,
                ];
            })
        );

        if ($duplicateGroups->count() > 25) {
            $this->line("... und " . ($duplicateGroups->count() - 25) . " weitere Gruppen");
        }

        if ($dry) {
            $this->info("[DRY RUN] Würde {$totalDuplicates} Duplikate löschen, {$uniqueKeep} Originale behalten.");
            return 0;
        }

        if (!$this->option('force') && !$this->confirm("Sollen {$totalDuplicates} Duplikate gelöscht werden? (Neueste Version je Gruppe wird behalten)")) {
            $this->info('Abgebrochen.');
            return 0;
        }

        $deleted = 0;
        $bar = $this->output->createProgressBar($duplicateGroups->count());
        $bar->start();

        foreach ($duplicateGroups as $group) {
            // Neueste behalten (bereits nach updated_at desc sortiert)
            $removeIds = $group->skip(1)->pluck('id');
            BankTransaction::whereIn('id', $removeIds)->forceDelete();
            $deleted += $removeIds->count();
            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->info("Erledigt: {$deleted} Duplikate gelöscht.");

        // Verbleibende Transaktionen zählen
        $remaining = BankTransaction::where('team_id', $teamId)->count();
        $this->info("Verbleibende Transaktionen: {$remaining}");

        return 0;
    }
}
