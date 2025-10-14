<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Services\GoCardlessService;
use Platform\Core\Models\Team;
use Platform\Drip\Models\Requisition;

class UpdateBankDataCommand extends Command
{
    protected $signature = 'drip:update-bank-data 
                                    {--team= : Specific team ID to update}
                                    {--dry-run : Show what would be updated without making changes}
                                    {--cleanup : Clean up expired requisitions for billing optimization}
                                    {--delete-all : Delete ALL requisitions (use with caution!)}
                                    {--billing : Show billing overview for teams}';

    protected $description = 'Update bank data for all teams or a specific team';

    public function handle(): int
    {
        $teamId = $this->option('team');
        $dryRun = $this->option('dry-run');
        $cleanup = $this->option('cleanup');
        $deleteAll = $this->option('delete-all');
        $billing = $this->option('billing');

        if ($billing) {
            return $this->showBillingOverview($teamId);
        }

        if ($deleteAll) {
            return $this->deleteAllRequisitions($teamId);
        }

        if ($cleanup) {
            return $this->cleanupExpiredRequisitions($teamId);
        }

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        if ($teamId) {
            return $this->updateSpecificTeam($teamId, $dryRun);
        }

        return $this->updateAllTeams($dryRun);
    }

    protected function updateSpecificTeam(int $teamId, bool $dryRun): int
    {
        $team = Team::find($teamId);
        if (!$team) {
            $this->error("❌ Team with ID {$teamId} not found");
            return 1;
        }

        $this->info("🏦 Updating bank data for team: {$team->name}");

        if ($dryRun) {
            $this->showTeamInfo($team);
            return 0;
        }

        return $this->processTeam($team);
    }

    protected function updateAllTeams(bool $dryRun): int
    {
        $teams = Team::whereHas('requisitions', function ($query) {
            $query->whereNotNull('linked_at')
                  ->where('access_expires_at', '>', now());
        })->get();

        if ($teams->isEmpty()) {
            $this->info('ℹ️  No teams with active bank connections found');
            return 0;
        }

        $this->info("🏦 Found {$teams->count()} teams with active bank connections");

        if ($dryRun) {
            foreach ($teams as $team) {
                $this->showTeamInfo($team);
            }
            return 0;
        }

        $successCount = 0;
        $errorCount = 0;

        foreach ($teams as $team) {
            $this->info("\n📊 Processing team: {$team->name}");
            
            if ($this->processTeam($team)) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        $this->info("\n✅ Summary:");
        $this->info("   Success: {$successCount} teams");
        $this->info("   Errors: {$errorCount} teams");

        return $errorCount > 0 ? 1 : 0;
    }

    protected function processTeam(Team $team): bool
    {
        try {
                    $gc = new GoCardlessService($team->id);
            $results = $gc->updateAllBankData();

            $this->info("   💰 Balances updated: {$results['balances_updated']}");
            $this->info("   📝 Transactions updated: {$results['transactions_updated']}");

            if (!empty($results['errors'])) {
                $this->warn("   ⚠️  Errors: " . count($results['errors']));
                foreach ($results['errors'] as $error) {
                    $this->error("      - {$error}");
                }
                return false;
            }

            return true;

        } catch (\Exception $e) {
            $this->error("   ❌ Failed to update team {$team->name}: " . $e->getMessage());
            return false;
        }
    }

    protected function showTeamInfo(Team $team): void
    {
        $requisitions = $team->requisitions()
            ->whereNotNull('linked_at')
            ->where('access_expires_at', '>', now())
            ->with('institution')
            ->get();

        $this->info("📊 Team: {$team->name}");
        $this->info("   Active connections: {$requisitions->count()}");

        foreach ($requisitions as $req) {
            $accounts = count($req->accounts ?? []);
            $expiresAt = $req->access_expires_at?->format('Y-m-d H:i:s') ?? 'Unknown';
            $this->info("   🏦 {$req->institution->name}: {$accounts} accounts (expires: {$expiresAt})");
        }
    }

    protected function showBillingOverview(?int $teamId): int
    {
        $this->info('💰 GoCardless Billing Overview');
        $this->info('================================');

        if ($teamId) {
            $team = Team::find($teamId);
            if (!$team) {
                $this->error("❌ Team with ID {$teamId} not found");
                return 1;
            }
            $this->showTeamBilling($team);
        } else {
            // Teams mit Requisitions finden
        $teamIds = Requisition::distinct()->pluck('team_id');
        $teams = Team::whereIn('id', $teamIds)->get();
            foreach ($teams as $team) {
                $this->showTeamBilling($team);
                $this->info('');
            }
        }

        return 0;
    }

    protected function showTeamBilling(Team $team): void
    {
                    $gc = new GoCardlessService($team->id);
        $billing = $gc->getBillingOverview();

        $this->info("📊 Team: {$team->name}");
        $this->info("   Active Requisitions: {$billing['active_count']}");
        $this->info("   Expired Requisitions: {$billing['expired_count']}");

        if ($billing['active_count'] > 0) {
            $this->info("   💳 Active Connections:");
            foreach ($billing['active_requisitions'] as $req) {
                $this->info("      - {$req['institution']}: {$req['accounts_count']} accounts (expires: {$req['expires_at']})");
            }
        }

        if ($billing['expired_count'] > 0) {
            $this->warn("   ⚠️  Expired Connections (can be cleaned up):");
            foreach ($billing['expired_requisitions'] as $req) {
                $this->warn("      - {$req['institution']}: {$req['accounts_count']} accounts (expired: {$req['expired_at']})");
            }
        }
    }

    protected function cleanupExpiredRequisitions(?int $teamId): int
    {
        $this->info('🧹 Cleaning up expired requisitions for billing optimization...');

        if ($teamId) {
            $team = Team::find($teamId);
            if (!$team) {
                $this->error("❌ Team with ID {$teamId} not found");
                return 1;
            }
            return $this->cleanupTeamRequisitions($team);
        }

        // Teams mit Requisitions finden
        $teamIds = Requisition::distinct()->pluck('team_id');
        $teams = Team::whereIn('id', $teamIds)->get();
        $totalDeleted = 0;
        $totalErrors = 0;

        foreach ($teams as $team) {
            $this->info("\n📊 Processing team: {$team->name}");
            $result = $this->cleanupTeamRequisitions($team);
            if ($result === 0) {
                $totalDeleted++;
            } else {
                $totalErrors++;
            }
        }

        $this->info("\n✅ Cleanup Summary:");
        $this->info("   Teams processed: {$teams->count()}");
        $this->info("   Successful: {$totalDeleted}");
        $this->info("   Errors: {$totalErrors}");

        return $totalErrors > 0 ? 1 : 0;
    }

    protected function cleanupTeamRequisitions(Team $team): int
    {
        try {
                    $gc = new GoCardlessService($team->id);
            $results = $gc->cleanupExpiredRequisitions();

            $this->info("   🗑️  Deleted: {$results['deleted']} expired requisitions");

            if (!empty($results['errors'])) {
                $this->warn("   ⚠️  Errors: " . count($results['errors']));
                foreach ($results['errors'] as $error) {
                    $this->error("      - {$error}");
                }
                return 1;
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("   ❌ Failed to cleanup team {$team->name}: " . $e->getMessage());
            return 1;
        }
    }

    protected function deleteAllRequisitions(?int $teamId): int
    {
        $this->warn('⚠️  WARNING: This will delete ALL requisitions!');
        $this->warn('   This action cannot be undone!');
        
        if (!$this->confirm('Are you sure you want to delete ALL requisitions?')) {
            $this->info('❌ Operation cancelled');
            return 0;
        }

        $this->info('🗑️  Deleting ALL requisitions...');

        if ($teamId) {
            $team = Team::find($teamId);
            if (!$team) {
                $this->error("❌ Team with ID {$teamId} not found");
                return 1;
            }
            return $this->deleteTeamRequisitions($team);
        }

        // Teams mit Requisitions finden
        $teamIds = Requisition::distinct()->pluck('team_id');
        $teams = Team::whereIn('id', $teamIds)->get();
        $totalDeleted = 0;
        $totalErrors = 0;

        foreach ($teams as $team) {
            $this->info("\n📊 Processing team: {$team->name}");
            $result = $this->deleteTeamRequisitions($team);
            if ($result === 0) {
                $totalDeleted++;
            } else {
                $totalErrors++;
            }
        }

        $this->info("\n✅ Delete All Summary:");
        $this->info("   Teams processed: {$teams->count()}");
        $this->info("   Successful: {$totalDeleted}");
        $this->info("   Errors: {$totalErrors}");

        return $totalErrors > 0 ? 1 : 0;
    }

    protected function deleteTeamRequisitions(Team $team): int
    {
        try {
            $gc = new GoCardlessService($team->id);
            
            // Alle Requisitions für das Team holen
            $requisitions = Requisition::where('team_id', $team->id)->get();
            
            if ($requisitions->isEmpty()) {
                $this->info("   ℹ️  No requisitions found for team {$team->name}");
                return 0;
            }

            $deletedCount = 0;
            $errorCount = 0;

            foreach ($requisitions as $requisition) {
                $this->info("   🗑️  Deleting requisition: {$requisition->external_id}");
                
                // Löschung über GoCardless API + lokal
                $success = $gc->deleteRequisition($requisition->external_id);
                
                if ($success) {
                    $deletedCount++;
                    $this->info("      ✅ Deleted successfully");
                } else {
                    $errorCount++;
                    $this->error("      ❌ Failed to delete");
                }
            }

            $this->info("   📊 Results: {$deletedCount} deleted, {$errorCount} errors");

            return $errorCount > 0 ? 1 : 0;

        } catch (\Exception $e) {
            $this->error("   ❌ Failed to delete requisitions for team {$team->name}: " . $e->getMessage());
            return 1;
        }
    }
}
