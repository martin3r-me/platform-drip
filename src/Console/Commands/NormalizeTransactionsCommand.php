<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Platform\Drip\Services\TransactionService;
use Platform\Core\Models\Team;

class NormalizeTransactionsCommand extends Command
{
    protected $signature = 'drip:normalize-transactions {--team=} {--since=} {--dry}';
    protected $description = 'Normalisiert Transaktionen (Gruppen, Direction, booked_at, interne Transfers, Referenzen, Typen)';

    public function handle(): int
    {
        $teamId = $this->option('team');
        $sinceOpt = $this->option('since');
        $dry = (bool) $this->option('dry');

        $since = null;
        if ($sinceOpt) {
            $since = Carbon::parse($sinceOpt);
        }

        $svc = new TransactionService();

        if ($teamId) {
            $team = Team::find($teamId);
            if (!$team) {
                $this->error("Team {$teamId} nicht gefunden");
                return 1;
            }
            if ($dry) {
                $this->info("[DRY] Würde Team {$team->name} normalisieren seit: " . ($since?->toDateString() ?? '—'));
                return 0;
            }
            $updated = $svc->normalizeTeam((int) $teamId, $since);
            $this->info("Aktualisiert: {$updated}");
            return 0;
        }

        // Alle Teams mit Drip-Nutzung (heuristisch über BankAccounts)
        $teamIds = \Platform\Drip\Models\BankAccount::query()
            ->distinct()->pluck('team_id')->all();

        if (empty($teamIds)) {
            $this->info('Keine Teams gefunden.');
            return 0;
        }

        foreach ($teamIds as $tid) {
            if ($dry) {
                $this->info("[DRY] Würde Team {$tid} normalisieren seit: " . ($since?->toDateString() ?? '—'));
                continue;
            }
            $updated = $svc->normalizeTeam((int) $tid, $since);
            $this->info("Team {$tid}: aktualisiert {$updated}");
        }

        return 0;
    }
}


