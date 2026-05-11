<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Services\BudgetPeriodService;
use Platform\Drip\Services\LiquidityPlanningService;

class ComputeLiquidityCommand extends Command
{
    protected $signature = 'drip:compute-liquidity {--team= : Team ID} {--days=180 : Days ahead to forecast}';

    protected $description = 'Generate budget periods, update fulfillment, and compute daily liquidity forecast';

    public function handle(BudgetPeriodService $periodService, LiquidityPlanningService $liquidityService): int
    {
        $teamId = $this->option('team');
        $days = (int) $this->option('days');

        $teams = $teamId
            ? [[(int) $teamId]]
            : BudgetItem::whereIn('status', ['active', 'paused'])
                ->distinct()
                ->pluck('team_id')
                ->map(fn ($id) => [$id]);

        $teamIds = $teamId ? [(int) $teamId] : BudgetItem::whereIn('status', ['active', 'paused'])
            ->distinct()
            ->pluck('team_id')
            ->toArray();

        foreach ($teamIds as $tid) {
            // 1. Generate any missing periods
            $generated = $periodService->generatePeriodsForTeam($tid);
            if ($generated > 0) {
                $this->line("Team {$tid}: {$generated} neue Perioden generiert.");
            }

            // 2. Update fulfillment
            $updated = $periodService->updateFulfillmentForTeam($tid);
            $this->line("Team {$tid}: {$updated} Perioden-Fulfillments aktualisiert.");

            // 3. Compute daily liquidity forecast
            $written = $liquidityService->computeForTeam($tid, $days);
            $this->line("Team {$tid}: {$written} Tages-Prognosen geschrieben.");
        }

        $this->info('Liquiditaetsplanung abgeschlossen.');

        return self::SUCCESS;
    }
}
