<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\Drip\Services\RecurringDetectionService;

class DetectRecurringBudgetsCommand extends Command
{
    protected $signature = 'drip:detect-budgets
                                    {--team= : Specific team ID}
                                    {--dry-run : Show detected patterns without creating suggestions}
                                    {--months=6 : Lookback period in months}
                                    {--min=3 : Minimum months a pattern must appear}';

    protected $description = 'Detect recurring transactions and create budget suggestions';

    public function handle(RecurringDetectionService $service): int
    {
        $teamId = $this->option('team');
        $dryRun = $this->option('dry-run');
        $months = (int) $this->option('months');
        $min = (int) $this->option('min');

        if ($teamId) {
            $teams = Team::where('id', $teamId)->get();
        } else {
            $teams = Team::all();
        }

        if ($teams->isEmpty()) {
            $this->error('No teams found.');
            return 1;
        }

        foreach ($teams as $team) {
            $this->info("Team: {$team->name} (ID: {$team->id})");

            $candidates = $service->detect($team->id, $months, $min);

            if ($candidates->isEmpty()) {
                $this->line('  No recurring patterns detected.');
                continue;
            }

            $this->table(
                ['Counterparty', 'Direction', 'Avg Amount', 'Months', 'CV', 'Day', 'TXs'],
                $candidates->map(fn ($c) => [
                    $c['counterparty_name'],
                    $c['direction'],
                    number_format($c['avg_amount'], 2),
                    $c['month_count'],
                    $c['cv'],
                    $c['typical_day'] ?? '-',
                    $c['tx_count'],
                ])->toArray()
            );

            if ($dryRun) {
                $this->line("  {$candidates->count()} candidates found (dry-run, no suggestions created).");
            } else {
                $created = $service->createSuggestions($team->id, $months, $min);
                $this->info("  {$created} suggestions created.");
            }

            $this->newLine();
        }

        return 0;
    }
}
