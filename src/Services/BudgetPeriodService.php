<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Models\BudgetItemPeriod;

class BudgetPeriodService
{
    public function generatePeriodsForItem(BudgetItem $item, int $monthsAhead = 12): int
    {
        $created = 0;
        $amount = (float) $item->amount;
        $teamId = $item->team_id;
        $startDate = $item->start_date ?? now()->startOfMonth();
        $endDate = $item->end_date;
        $horizon = now()->addMonths($monthsAhead)->endOfMonth();

        if ($endDate && $endDate->lt($horizon)) {
            $horizon = $endDate;
        }

        $periods = match ($item->frequency) {
            'once' => $this->buildOncePeriods($item, $amount),
            'weekly' => $this->buildWeeklyPeriods($startDate, $horizon, $amount, $item->day_of_month),
            'monthly' => $this->buildMonthlyPeriods($startDate, $horizon, $amount, $item->day_of_month),
            'quarterly' => $this->buildQuarterlyPeriods($startDate, $horizon, $amount, $item->day_of_month),
            'yearly' => $this->buildYearlyPeriods($startDate, $horizon, $amount, $item->day_of_month),
            default => [],
        };

        foreach ($periods as $period) {
            $exists = BudgetItemPeriod::where('budget_item_id', $item->id)
                ->where('period_start', $period['period_start'])
                ->exists();

            if ($exists) {
                continue;
            }

            BudgetItemPeriod::create([
                'team_id' => $teamId,
                'budget_item_id' => $item->id,
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'expected_date' => $period['expected_date'],
                'planned_amount' => $period['planned_amount'],
                'status' => 'pending',
            ]);

            $created++;
        }

        return $created;
    }

    public function generatePeriodsForTeam(int $teamId, int $monthsAhead = 12): int
    {
        $total = 0;

        BudgetItem::where('team_id', $teamId)
            ->whereIn('status', ['active', 'paused'])
            ->each(function (BudgetItem $item) use ($monthsAhead, &$total) {
                $total += $this->generatePeriodsForItem($item, $monthsAhead);
            });

        return $total;
    }

    public function updateFulfillmentForTeam(int $teamId, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $query = BudgetItemPeriod::where('team_id', $teamId)
            ->whereIn('status', ['pending', 'partial', 'fulfilled', 'missed']);

        if ($from) {
            $query->where('period_start', '>=', $from);
        }
        if ($to) {
            $query->where('period_end', '<=', $to);
        }

        $updated = 0;

        $query->with('budgetItem')->each(function (BudgetItemPeriod $period) use ($teamId, &$updated) {
            $period->updateFulfillment($teamId);
            $updated++;
        });

        return $updated;
    }

    protected function buildOncePeriods(BudgetItem $item, float $amount): array
    {
        $date = $item->planned_date ?? $item->start_date ?? now();

        return [[
            'period_start' => $date->copy()->startOfDay(),
            'period_end' => $date->copy()->endOfDay(),
            'expected_date' => $date->copy(),
            'planned_amount' => $amount,
        ]];
    }

    protected function buildWeeklyPeriods(Carbon $start, Carbon $horizon, float $amount, ?int $dayOfMonth): array
    {
        $periods = [];
        $cursor = $start->copy()->startOfWeek();

        while ($cursor->lte($horizon)) {
            $periodStart = $cursor->copy();
            $periodEnd = $cursor->copy()->endOfWeek();

            if ($periodEnd->gte($start)) {
                $periods[] = [
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'expected_date' => null,
                    'planned_amount' => $amount,
                ];
            }

            $cursor->addWeek();
        }

        return $periods;
    }

    protected function buildMonthlyPeriods(Carbon $start, Carbon $horizon, float $amount, ?int $dayOfMonth): array
    {
        $periods = [];
        $cursor = $start->copy()->startOfMonth();

        while ($cursor->lte($horizon)) {
            $periodStart = $cursor->copy()->startOfMonth();
            $periodEnd = $cursor->copy()->endOfMonth();
            $expectedDate = null;

            if ($dayOfMonth) {
                $day = min($dayOfMonth, $periodEnd->day);
                $expectedDate = $periodStart->copy()->day($day);
            }

            if ($periodEnd->gte($start)) {
                $periods[] = [
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'expected_date' => $expectedDate,
                    'planned_amount' => $amount,
                ];
            }

            $cursor->addMonth();
        }

        return $periods;
    }

    protected function buildQuarterlyPeriods(Carbon $start, Carbon $horizon, float $amount, ?int $dayOfMonth): array
    {
        $periods = [];
        $cursor = $start->copy()->startOfQuarter();

        while ($cursor->lte($horizon)) {
            $periodStart = $cursor->copy()->startOfQuarter();
            $periodEnd = $cursor->copy()->endOfQuarter();
            $expectedDate = null;

            if ($dayOfMonth) {
                $day = min($dayOfMonth, $periodStart->copy()->endOfMonth()->day);
                $expectedDate = $periodStart->copy()->day($day);
            }

            if ($periodEnd->gte($start)) {
                $periods[] = [
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'expected_date' => $expectedDate,
                    'planned_amount' => $amount,
                ];
            }

            $cursor->addQuarter();
        }

        return $periods;
    }

    protected function buildYearlyPeriods(Carbon $start, Carbon $horizon, float $amount, ?int $dayOfMonth): array
    {
        $periods = [];
        $cursor = $start->copy()->startOfYear();

        while ($cursor->lte($horizon)) {
            $periodStart = $cursor->copy()->startOfYear();
            $periodEnd = $cursor->copy()->endOfYear();
            $expectedDate = null;

            if ($dayOfMonth && $start->month) {
                $expectedMonth = $start->copy()->startOfYear()->month($start->month);
                $day = min($dayOfMonth, $expectedMonth->endOfMonth()->day);
                $expectedDate = $periodStart->copy()->month($start->month)->day($day);
            }

            if ($periodEnd->gte($start)) {
                $periods[] = [
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'expected_date' => $expectedDate,
                    'planned_amount' => $amount,
                ];
            }

            $cursor->addYear();
        }

        return $periods;
    }
}
