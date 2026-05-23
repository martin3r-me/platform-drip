<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Services\CashflowSignalSyncService;

class SyncCashflowSignalsCommand extends Command
{
    protected $signature = 'drip:sync-signals
                            {--team= : Team ID to sync (required)}
                            {--days=180 : Days ahead to look for signals}';

    protected $description = 'Sync external cashflow signals from all registered providers';

    public function handle(CashflowSignalSyncService $service): int
    {
        $teamId = (int) $this->option('team');
        $daysAhead = (int) $this->option('days');

        if (!$teamId) {
            $this->error('--team is required.');
            return self::FAILURE;
        }

        $this->info("Syncing cashflow signals for team {$teamId} ({$daysAhead} days ahead)...");

        $count = $service->syncAll($teamId, $daysAhead);

        $this->info("Done. {$count} signals synced.");

        return self::SUCCESS;
    }
}
