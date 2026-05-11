<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\Drip\Services\CashflowSnapshotService;

class BuildCashflowSnapshotsCommand extends Command
{
    protected $signature = 'drip:build-cashflow-snapshots {--team=} {--since=} {--until=} {--dry}';
    protected $description = 'Berechnet vorberechnete Cashflow-Snapshots (Ist-Aggregation pro Kategorie/Counterparty)';

    public function handle(): int
    {
        $teamId = $this->option('team');
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : null;
        $until = $this->option('until') ? Carbon::parse($this->option('until')) : null;
        $dry = (bool) $this->option('dry');

        $svc = app(CashflowSnapshotService::class);

        if ($teamId) {
            $team = Team::find($teamId);
            if (!$team) {
                $this->error("Team {$teamId} nicht gefunden");
                return 1;
            }
            if ($dry) {
                $this->info("[DRY] Wuerde Cashflow-Snapshots fuer Team {$team->name} bauen");
                return 0;
            }
            $rows = $svc->computeForTeam((int) $teamId, $since, $until);
            $this->info("Cashflow-Snapshots upserted: {$rows}");
            return 0;
        }

        $teamIds = \Platform\Drip\Models\BankAccount::query()->distinct()->pluck('team_id')->all();
        foreach ($teamIds as $tid) {
            if ($dry) {
                $this->info("[DRY] Wuerde Cashflow-Snapshots fuer Team {$tid} bauen");
                continue;
            }
            $rows = $svc->computeForTeam((int) $tid, $since, $until);
            $this->info("Team {$tid}: Snapshots {$rows}");
        }

        return 0;
    }
}
