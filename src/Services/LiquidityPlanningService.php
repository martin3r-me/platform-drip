<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Platform\Drip\Models\BankAccountBalance;
use Platform\Drip\Models\BudgetItemPeriod;
use Platform\Drip\Models\LiquidityForecast;

class LiquidityPlanningService
{
    /**
     * Compute daily liquidity forecast and persist to drip_liquidity_forecasts.
     * Intended to run via command (daily or on-demand).
     */
    public function computeForTeam(int $teamId, int $daysAhead = 180): int
    {
        $currentBalance = $this->getCurrentBalance($teamId);
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays($daysAhead);

        // Load all pending/partial periods in the forecast window
        $periods = BudgetItemPeriod::where('team_id', $teamId)
            ->whereIn('status', ['pending', 'partial'])
            ->where('period_end', '>=', $today)
            ->where('period_start', '<=', $horizon)
            ->with('budgetItem')
            ->get();

        // Build a map: date => [credits, debits]
        $dailyMap = [];
        foreach ($periods as $p) {
            $date = ($p->expected_date ?? $p->period_start)->format('Y-m-d');

            // If expected_date is in the past, skip (already booked or missed)
            if (Carbon::parse($date)->lt($today)) {
                continue;
            }

            if (!isset($dailyMap[$date])) {
                $dailyMap[$date] = ['credits' => 0, 'debits' => 0];
            }

            $amount = (float) $p->planned_amount;
            if ($p->budgetItem->direction === 'credit') {
                $dailyMap[$date]['credits'] += $amount;
            } else {
                $dailyMap[$date]['debits'] += $amount;
            }
        }

        // Delete old forecasts for this team, then insert day by day
        LiquidityForecast::where('team_id', $teamId)->delete();

        $runningBalance = $currentBalance;
        $now = now();
        $written = 0;

        for ($i = 0; $i <= $daysAhead; $i++) {
            $date = $today->copy()->addDays($i);
            $dateKey = $date->format('Y-m-d');

            $credits = $dailyMap[$dateKey]['credits'] ?? 0;
            $debits = $dailyMap[$dateKey]['debits'] ?? 0;
            $runningBalance += ($credits - $debits);

            LiquidityForecast::create([
                'team_id' => $teamId,
                'forecast_date' => $date,
                'projected_balance' => round($runningBalance, 2),
                'planned_credits' => round($credits, 2),
                'planned_debits' => round($debits, 2),
                'computed_at' => $now,
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * Read pre-computed forecast from table. No heavy calculation.
     */
    public function getPlan(int $teamId, int $monthsAhead = 6): array
    {
        $currentBalance = $this->getCurrentBalance($teamId);
        $today = now()->startOfDay();
        $horizon = $today->copy()->addMonths($monthsAhead)->endOfMonth();

        $forecasts = LiquidityForecast::where('team_id', $teamId)
            ->where('forecast_date', '>=', $today)
            ->where('forecast_date', '<=', $horizon)
            ->orderBy('forecast_date')
            ->get();

        // If no forecasts computed yet, return empty structure
        if ($forecasts->isEmpty()) {
            return [
                'current_balance' => round($currentBalance, 2),
                'computed_at' => null,
                'daily_forecast' => [],
                'monthly_summary' => [],
                'upcoming_items' => $this->getUpcomingItems($teamId, $monthsAhead),
            ];
        }

        // Daily forecast for chart
        $dailyForecast = $forecasts->map(fn ($f) => [
            'date' => $f->forecast_date->format('Y-m-d'),
            'balance' => (float) $f->projected_balance,
            'credits' => (float) $f->planned_credits,
            'debits' => (float) $f->planned_debits,
        ])->values()->toArray();

        // Monthly summary aggregated from daily data
        $monthlySummary = [];
        $grouped = $forecasts->groupBy(fn ($f) => $f->forecast_date->format('Y-m'));

        foreach ($grouped as $monthKey => $monthForecasts) {
            $credits = $monthForecasts->sum(fn ($f) => (float) $f->planned_credits);
            $debits = $monthForecasts->sum(fn ($f) => (float) $f->planned_debits);
            $net = $credits - $debits;
            $endBalance = (float) $monthForecasts->last()->projected_balance;

            $monthlySummary[] = [
                'month' => Carbon::createFromFormat('Y-m', $monthKey)->translatedFormat('M Y'),
                'credits' => round($credits, 2),
                'debits' => round($debits, 2),
                'net' => round($net, 2),
                'end_balance' => round($endBalance, 2),
            ];
        }

        return [
            'current_balance' => round($currentBalance, 2),
            'computed_at' => $forecasts->first()->computed_at?->toIso8601String(),
            'daily_forecast' => $dailyForecast,
            'monthly_summary' => $monthlySummary,
            'upcoming_items' => $this->getUpcomingItems($teamId, $monthsAhead),
        ];
    }

    /**
     * Legacy method - redirects to getPlan for backward compat with MCP tool.
     */
    public function buildPlan(int $teamId, int $monthsAhead = 6): array
    {
        return $this->getPlan($teamId, $monthsAhead);
    }

    protected function getCurrentBalance(int $teamId): float
    {
        return BankAccountBalance::where('team_id', $teamId)
            ->get()
            ->groupBy('bank_account_id')
            ->map(fn ($balances) => $balances->sortByDesc('retrieved_at')->first())
            ->sum(fn ($b) => (float) ($b->amount ?? $b->balance ?? 0));
    }

    protected function getUpcomingItems(int $teamId, int $monthsAhead): array
    {
        return BudgetItemPeriod::where('team_id', $teamId)
            ->where('status', 'pending')
            ->where('period_end', '>=', now()->startOfDay())
            ->where('period_start', '<=', now()->addMonths($monthsAhead)->endOfMonth())
            ->with('budgetItem.category')
            ->orderBy('expected_date')
            ->orderBy('period_start')
            ->limit(20)
            ->get()
            ->map(fn ($p) => [
                'name' => $p->budgetItem->name,
                'date' => ($p->expected_date ?? $p->period_start)->format('Y-m-d'),
                'direction' => $p->budgetItem->direction,
                'amount' => (float) $p->planned_amount,
                'category' => $p->budgetItem->category?->name,
            ])
            ->values()
            ->toArray();
    }
}
