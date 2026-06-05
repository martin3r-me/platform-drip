<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Services\CashflowSnapshotService;
use Platform\Drip\Services\FinanceMetricsService;
use Platform\Drip\Services\GroupMetricsService;
use Platform\Drip\Services\MossSyncService;
use Platform\Drip\Services\TransactionService;
use Platform\Integrations\Models\Integration;
use Platform\Integrations\Models\IntegrationConnection;

class SyncMossDataCommand extends Command
{
    protected $signature = 'drip:sync-moss
                                    {--team= : Specific team ID}
                                    {--dry-run : Show what would be synced}';

    protected $description = 'Sync MOSS bank accounts and card transactions into Drip';

    public function handle(MossSyncService $syncService): int
    {
        $teamId = $this->option('team');
        $dryRun = $this->option('dry-run');

        $teams = $this->resolveTeams($teamId);

        if ($teams->isEmpty()) {
            $this->info('No teams with active MOSS connections found.');
            return self::SUCCESS;
        }

        $this->info("Found {$teams->count()} team(s) with MOSS connections.");

        if ($dryRun) {
            foreach ($teams as $team) {
                $this->info("  [{$team->id}] {$team->name}");
            }
            $this->info('Dry run — no changes made.');
            return self::SUCCESS;
        }

        $successCount = 0;
        $errorCount = 0;

        foreach ($teams as $team) {
            $this->info("Processing team: {$team->name}");

            try {
                $results = $syncService->syncForTeam($team);

                $this->info("   Accounts synced: {$results['accounts_synced']}");
                $this->info("   Transactions synced: {$results['transactions_synced']}");

                $this->postProcess($team);
                $successCount++;
            } catch (\Exception $e) {
                $this->error("   Failed: {$e->getMessage()}");
                $errorCount++;
            }
        }

        $this->info("Done. Success: {$successCount}, Errors: {$errorCount}");

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function resolveTeams(?string $teamId)
    {
        if ($teamId) {
            $team = Team::find((int) $teamId);
            return $team ? collect([$team]) : collect();
        }

        // All teams with an active MOSS connection
        $integration = Integration::where('key', 'moss')->first();

        if (!$integration) {
            return collect();
        }

        $teamMemberUserIds = IntegrationConnection::where('integration_id', $integration->id)
            ->where('status', 'active')
            ->pluck('owner_user_id');

        if ($teamMemberUserIds->isEmpty()) {
            return collect();
        }

        return Team::whereHas('users', function ($query) use ($teamMemberUserIds) {
            $query->whereIn('users.id', $teamMemberUserIds);
        })->get();
    }

    protected function postProcess(Team $team): void
    {
        $teamId = (int) $team->id;

        // Normalize recently synced MOSS accounts
        $recentAccounts = BankAccount::where('team_id', $teamId)
            ->where('provider', 'moss')
            ->whereNotNull('last_transactions_synced_at')
            ->where('last_transactions_synced_at', '>=', now()->subDay())
            ->get(['id', 'team_id', 'last_transactions_synced_at']);

        if ($recentAccounts->isNotEmpty()) {
            $svc = app(TransactionService::class);
            $normalized = 0;

            foreach ($recentAccounts as $acc) {
                $since = $acc->last_transactions_synced_at
                    ? $acc->last_transactions_synced_at->copy()->subDay()
                    : now()->subDays(90);
                $normalized += $svc->normalizeAccounts($teamId, [$acc->id], $since);
            }

            $this->info("   Normalized transactions: {$normalized}");
        }

        // Group metrics
        $gm = app(GroupMetricsService::class);
        $rows = $gm->buildForTeam($teamId, now()->startOfMonth()->subMonth(), now());
        $this->info("   Group KPIs updated: {$rows}");

        // Finance metrics
        $fm = app(FinanceMetricsService::class);
        $frows = $fm->buildFromGroupMetrics($teamId, now()->startOfMonth()->subMonth(), now());
        $this->info("   Finance metrics: {$frows}");

        // Cashflow snapshots
        $cs = app(CashflowSnapshotService::class);
        $csRows = $cs->computeForTeam($teamId, now()->startOfMonth()->subMonth(), now());
        $this->info("   Cashflow snapshots: {$csRows}");
    }
}
