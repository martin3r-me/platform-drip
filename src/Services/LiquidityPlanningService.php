<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Platform\Drip\Models\BankAccountBalance;
use Platform\Drip\Models\BudgetItemPeriod;

class LiquidityPlanningService
{
    public function buildPlan(int $teamId, int $monthsAhead = 6): array
    {
        // 1. Current balance: sum latest balance per account
        $currentBalance = BankAccountBalance::where('team_id', $teamId)
            ->get()
            ->groupBy('bank_account_id')
            ->map(fn ($balances) => $balances->sortByDesc('retrieved_at')->first())
            ->sum(fn ($b) => (float) ($b->amount ?? $b->balance ?? 0));

        // 2. Load all pending periods with expected_date >= today
        $periods = BudgetItemPeriod::where('team_id', $teamId)
            ->where('status', 'pending')
            ->where('period_end', '>=', now()->startOfDay())
            ->where('period_start', '<=', now()->addMonths($monthsAhead)->endOfMonth())
            ->with('budgetItem.category')
            ->orderBy('expected_date')
            ->orderBy('period_start')
            ->get();

        // 3. Group by month, sum credits/debits
        $monthlySummary = [];
        $runningBalance = $currentBalance;

        for ($i = 0; $i < $monthsAhead; $i++) {
            $monthStart = now()->addMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $monthKey = $monthStart->format('M Y');

            $monthPeriods = $periods->filter(function ($p) use ($monthStart, $monthEnd) {
                $date = $p->expected_date ?? $p->period_start;
                return $date->gte($monthStart) && $date->lte($monthEnd);
            });

            $credits = 0;
            $debits = 0;

            foreach ($monthPeriods as $p) {
                $amount = (float) $p->planned_amount;
                if ($p->budgetItem->direction === 'credit') {
                    $credits += $amount;
                } else {
                    $debits += $amount;
                }
            }

            $net = $credits - $debits;
            $runningBalance += $net;

            $monthlySummary[] = [
                'month' => $monthKey,
                'credits' => round($credits, 2),
                'debits' => round($debits, 2),
                'net' => round($net, 2),
                'end_balance' => round($runningBalance, 2),
            ];
        }

        // 4. Upcoming items: next 20 periods sorted by expected_date
        $upcomingItems = $periods->take(20)->map(function ($p) {
            return [
                'name' => $p->budgetItem->name,
                'date' => ($p->expected_date ?? $p->period_start)->format('Y-m-d'),
                'direction' => $p->budgetItem->direction,
                'amount' => (float) $p->planned_amount,
                'category' => $p->budgetItem->category?->name,
            ];
        })->values()->toArray();

        return [
            'current_balance' => round($currentBalance, 2),
            'monthly_summary' => $monthlySummary,
            'upcoming_items' => $upcomingItems,
        ];
    }
}
