<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Platform\Drip\Services\GroupMetricsService;
use Platform\Core\Models\Team;

class BuildGroupMetricsCommand extends Command
{
    protected $signature = 'drip:build-group-metrics {--team=} {--since=} {--until=} {--dry}';
    protected $description = 'Aggregiert Gruppen-KPIs (Cashflow, Burn, Runway) für einen Zeitraum';

    public function handle(): int
    {
        $teamId = $this->option('team');
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : null;
        $until = $this->option('until') ? Carbon::parse($this->option('until')) : null;
        $dry = (bool) $this->option('dry');

        $svc = new GroupMetricsService();

        if ($teamId) {
            $team = Team::find($teamId);
            if (!$team) {
                $this->error("Team {$teamId} nicht gefunden");
                return 1;
            }
            if ($dry) {
                $this->info("[DRY] Würde KPIs für Team {$team->name} bauen");
                return 0;
            }
            $rows = $svc->buildForTeam((int) $teamId, $since, $until);
            $this->info("Gruppen-KPIs Zeilen upserted: {$rows}");
            return 0;
        }

        $teamIds = \Platform\Drip\Models\BankAccount::query()->distinct()->pluck('team_id')->all();
        foreach ($teamIds as $tid) {
            if ($dry) {
                $this->info("[DRY] Würde KPIs für Team {$tid} bauen");
                continue;
            }
            $rows = $svc->buildForTeam((int) $tid, $since, $until);
            $this->info("Team {$tid}: Zeilen {$rows}");
        }

        return 0;
    }
}


