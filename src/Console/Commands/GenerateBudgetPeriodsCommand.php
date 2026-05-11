<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Services\BudgetPeriodService;

class GenerateBudgetPeriodsCommand extends Command
{
    protected $signature = 'drip:generate-budget-periods {--team= : Team ID} {--months=12 : Months ahead to generate}';

    protected $description = 'Generate budget periods and update fulfillment for all active budgets';

    public function handle(BudgetPeriodService $service): int
    {
        $teamId = $this->option('team');
        $months = (int) $this->option('months');

        if ($teamId) {
            $teamId = (int) $teamId;
            $generated = $service->generatePeriodsForTeam($teamId, $months);
            $this->info("Generated {$generated} periods for team {$teamId}.");

            $updated = $service->updateFulfillmentForTeam($teamId);
            $this->info("Updated fulfillment for {$updated} periods.");
        } else {
            $teams = \Platform\Drip\Models\BudgetItem::distinct('team_id')
                ->whereIn('status', ['active', 'paused'])
                ->pluck('team_id');

            $totalGenerated = 0;
            $totalUpdated = 0;

            foreach ($teams as $tid) {
                $generated = $service->generatePeriodsForTeam($tid, $months);
                $totalGenerated += $generated;

                $updated = $service->updateFulfillmentForTeam($tid);
                $totalUpdated += $updated;

                $this->line("Team {$tid}: {$generated} periods generated, {$updated} fulfillments updated.");
            }

            $this->info("Total: {$totalGenerated} periods generated, {$totalUpdated} fulfillments updated.");
        }

        return self::SUCCESS;
    }
}
