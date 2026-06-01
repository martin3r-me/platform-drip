<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Platform\Drip\Models\BankAccountBalance;
use Platform\Drip\Models\BudgetItemPeriod;
use Platform\Drip\Models\CashflowSignal;
use Platform\Drip\Models\DripTeamSettings;
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

        // Merge in active cashflow signals (not pinned — those are handled via BudgetItems)
        $signals = CashflowSignal::where('team_id', $teamId)
            ->where('status', 'active')
            ->get();

        foreach ($signals as $signal) {
            $date = $signal->effectiveDate()->format('Y-m-d');

            if (Carbon::parse($date)->lt($today)) {
                continue;
            }

            if (!isset($dailyMap[$date])) {
                $dailyMap[$date] = ['credits' => 0, 'debits' => 0];
            }

            $weightedAmount = $signal->weightedAmount();
            if ($signal->direction === 'credit') {
                $dailyMap[$date]['credits'] += $weightedAmount;
            } else {
                $dailyMap[$date]['debits'] += $weightedAmount;
            }
        }

        // Calculate VAT payments and inject into daily map
        $vatPayments = $this->calculateVatPayments($teamId, $today, $horizon);
        foreach ($vatPayments as $vp) {
            $dateKey = $vp['date'];
            if (!isset($dailyMap[$dateKey])) {
                $dailyMap[$dateKey] = ['credits' => 0, 'debits' => 0];
            }
            $dailyMap[$dateKey]['debits'] += $vp['amount'];
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

        // Historical daily balances for actual vs. forecast overlay
        $historicalBalances = $this->getHistoricalDailyBalances($teamId, 60);

        // If no forecasts computed yet, return empty structure
        if ($forecasts->isEmpty()) {
            return [
                'current_balance' => round($currentBalance, 2),
                'computed_at' => null,
                'daily_forecast' => [],
                'historical_balances' => $historicalBalances,
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
            'historical_balances' => $historicalBalances,
            'monthly_summary' => $monthlySummary,
            'upcoming_items' => $this->getUpcomingItems($teamId, $monthsAhead),
        ];
    }

    /**
     * Get monthly detail with individual line items (budget + signals merged).
     */
    public function getMonthlyDetail(int $teamId, int $monthsAhead = 6): array
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addMonths($monthsAhead)->endOfMonth();

        // Load budget item periods
        $periods = BudgetItemPeriod::where('team_id', $teamId)
            ->whereIn('status', ['pending', 'partial'])
            ->where('period_end', '>=', $today)
            ->where('period_start', '<=', $horizon)
            ->with('budgetItem.category')
            ->get()
            ->filter(fn ($p) => $p->budgetItem !== null);

        $items = [];

        foreach ($periods as $p) {
            $date = $p->expected_date ?? $p->period_start;
            if ($date->lt($today)) {
                continue;
            }

            $effectiveRate = $p->budgetItem->effectiveTaxRate();
            $plannedAmount = (float) $p->planned_amount;
            $vatAmount = ($effectiveRate !== null && $effectiveRate > 0)
                ? round($plannedAmount * $effectiveRate / (100 + $effectiveRate), 2)
                : null;

            $items[] = [
                'name' => $p->budgetItem->name,
                'amount' => $plannedAmount,
                'direction' => $p->budgetItem->direction,
                'date' => $date->format('Y-m-d'),
                'month' => $date->format('Y-m'),
                'source' => 'budget',
                'confidence_level' => null,
                'counterparty' => null,
                'url' => null,
                'category' => $p->budgetItem->category?->name,
                'signal_id' => null,
                'provider_key' => null,
                'has_override' => false,
                'tax_rate' => $effectiveRate,
                'vat_amount' => $vatAmount,
            ];
        }

        // Load active signals
        $signals = CashflowSignal::where('team_id', $teamId)
            ->where('status', 'active')
            ->get();

        foreach ($signals as $signal) {
            $date = $signal->effectiveDate();
            if ($date->lt($today) || $date->gt($horizon)) {
                continue;
            }

            $items[] = [
                'name' => $signal->label,
                'amount' => $signal->effectiveAmount(),
                'direction' => $signal->direction,
                'date' => $date->format('Y-m-d'),
                'month' => $date->format('Y-m'),
                'source' => 'signal',
                'confidence_level' => $signal->confidence_level,
                'counterparty' => $signal->counterparty,
                'url' => $signal->url,
                'category' => $signal->category,
                'signal_id' => $signal->id,
                'provider_key' => $signal->provider_key,
                'has_override' => $signal->override_amount !== null || $signal->override_date !== null,
                'tax_rate' => null,
                'vat_amount' => null,
            ];
        }

        // Inject VAT payment items
        $vatPayments = $this->calculateVatPayments($teamId, $today, $horizon);
        foreach ($vatPayments as $vp) {
            $items[] = [
                'name' => $vp['label'],
                'amount' => $vp['amount'],
                'direction' => 'debit',
                'date' => $vp['date'],
                'month' => Carbon::parse($vp['date'])->format('Y-m'),
                'source' => 'vat',
                'confidence_level' => null,
                'counterparty' => 'Finanzamt',
                'url' => null,
                'category' => 'USt-Vorauszahlung',
                'signal_id' => null,
                'provider_key' => null,
                'has_override' => false,
                'tax_rate' => null,
                'vat_amount' => null,
            ];
        }

        // Group by month
        $grouped = collect($items)->groupBy('month')->sortKeys();

        // Load end_balance from forecasts
        $forecasts = LiquidityForecast::where('team_id', $teamId)
            ->where('forecast_date', '>=', $today)
            ->where('forecast_date', '<=', $horizon)
            ->orderBy('forecast_date')
            ->get()
            ->groupBy(fn ($f) => $f->forecast_date->format('Y-m'));

        $result = [];

        foreach ($grouped as $monthKey => $monthItems) {
            $credits = $monthItems->where('direction', 'credit')->sum('amount');
            $debits = $monthItems->where('direction', 'debit')->sum('amount');
            $net = $credits - $debits;

            $monthForecasts = $forecasts->get($monthKey);
            $endBalance = $monthForecasts ? (float) $monthForecasts->last()->projected_balance : null;

            // Sort items by amount descending within each month
            $sorted = $monthItems
                ->sortByDesc('amount')
                ->values()
                ->map(fn ($item) => collect($item)->except('month')->toArray())
                ->toArray();

            $result[$monthKey] = [
                'label' => Carbon::createFromFormat('Y-m', $monthKey)->translatedFormat('F Y'),
                'credits' => round($credits, 2),
                'debits' => round($debits, 2),
                'net' => round($net, 2),
                'end_balance' => $endBalance !== null ? round($endBalance, 2) : null,
                'items' => $sorted,
            ];
        }

        return $result;
    }

    /**
     * Legacy method - redirects to getPlan for backward compat with MCP tool.
     */
    public function buildPlan(int $teamId, int $monthsAhead = 6): array
    {
        return $this->getPlan($teamId, $monthsAhead);
    }

    public function getHistoricalDailyBalances(int $teamId, int $days = 60): array
    {
        $since = now()->subDays($days)->startOfDay();

        $balances = BankAccountBalance::where('team_id', $teamId)
            ->where(function ($q) use ($since) {
                $q->where('as_of_date', '>=', $since)
                    ->orWhere(function ($inner) use ($since) {
                        $inner->whereNull('as_of_date')
                            ->where('retrieved_at', '>=', $since);
                    });
            })
            ->get();

        if ($balances->isEmpty()) {
            return [];
        }

        // Group by date, then by account — take latest per account per day
        $grouped = $balances->groupBy(function ($b) {
            return ($b->as_of_date ?? $b->retrieved_at->startOfDay())->format('Y-m-d');
        });

        $dailyTotals = [];
        foreach ($grouped as $date => $dayBalances) {
            $total = $dayBalances
                ->groupBy('bank_account_id')
                ->map(fn ($acctBalances) => $acctBalances->sortByDesc('retrieved_at')->first())
                ->sum(fn ($b) => (float) ($b->amount ?? $b->balance ?? 0));

            $dailyTotals[] = ['date' => $date, 'balance' => round($total, 2)];
        }

        // Sort by date
        usort($dailyTotals, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $dailyTotals;
    }

    /**
     * Calculate VAT advance payments based on budget items in the forecast window.
     * Returns array of synthetic debit entries with date, amount, label.
     */
    protected function calculateVatPayments(int $teamId, Carbon $today, Carbon $horizon): array
    {
        $settings = DripTeamSettings::getOrCreateForTeam($teamId);

        if (!$settings->getSetting('vat_enabled', true)) {
            return [];
        }

        $frequency = $settings->getSetting('vat_filing_frequency', 'monthly');
        $dueDay = (int) $settings->getSetting('vat_due_day', 10);

        // Load all pending/partial periods in the window with budget item + category
        $periods = BudgetItemPeriod::where('team_id', $teamId)
            ->whereIn('status', ['pending', 'partial'])
            ->where('period_end', '>=', $today)
            ->where('period_start', '<=', $horizon)
            ->with('budgetItem.category')
            ->get()
            ->filter(fn ($p) => $p->budgetItem !== null);

        // Accumulate VAT per filing period
        $vatByPeriod = [];

        foreach ($periods as $p) {
            $date = $p->expected_date ?? $p->period_start;
            if ($date->lt($today)) {
                continue;
            }

            $item = $p->budgetItem;
            $rate = $item->effectiveTaxRate();
            if ($rate === null || $rate <= 0) {
                continue;
            }

            $amount = (float) $p->planned_amount;
            $vat = round($amount * $rate / (100 + $rate), 2);

            // Determine filing period key
            if ($frequency === 'quarterly') {
                $quarter = (int) ceil($date->month / 3);
                $periodKey = $date->year . '-Q' . $quarter;
                // Due date = first day after quarter end + due_day days
                $quarterEndMonth = $quarter * 3;
                $periodEnd = Carbon::create($date->year, $quarterEndMonth, 1)->endOfMonth();
            } else {
                $periodKey = $date->format('Y-m');
                $periodEnd = $date->copy()->endOfMonth();
            }

            if (!isset($vatByPeriod[$periodKey])) {
                $vatByPeriod[$periodKey] = [
                    'credit_vat' => 0,
                    'debit_vat' => 0,
                    'period_end' => $periodEnd,
                    'period_key' => $periodKey,
                ];
            }

            // Credits = revenue → we owe VAT to Finanzamt (positive)
            // Debits = expenses → Vorsteuer we can deduct (negative)
            if ($item->direction === 'credit') {
                $vatByPeriod[$periodKey]['credit_vat'] += $vat;
            } else {
                $vatByPeriod[$periodKey]['debit_vat'] += $vat;
            }
        }

        // Build payment entries — only positive net VAT (we owe the Finanzamt)
        $payments = [];

        foreach ($vatByPeriod as $periodKey => $data) {
            $netVat = round($data['credit_vat'] - $data['debit_vat'], 2);
            if ($netVat <= 0) {
                continue;
            }

            $dueDate = $data['period_end']->copy()->addDays($dueDay);

            // Only include if due date is within the forecast window
            if ($dueDate->lt($today) || $dueDate->gt($horizon)) {
                continue;
            }

            // Build human-readable label
            if ($frequency === 'quarterly') {
                $label = 'USt-Vorauszahlung ' . str_replace('-', ' ', $periodKey);
            } else {
                $label = 'USt-Vorauszahlung ' . Carbon::createFromFormat('Y-m', $periodKey)->translatedFormat('F Y');
            }

            $payments[] = [
                'date' => $dueDate->format('Y-m-d'),
                'amount' => $netVat,
                'label' => $label,
            ];
        }

        return $payments;
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
        $budgetItems = BudgetItemPeriod::where('team_id', $teamId)
            ->where('status', 'pending')
            ->where('period_end', '>=', now()->startOfDay())
            ->where('period_start', '<=', now()->addMonths($monthsAhead)->endOfMonth())
            ->with('budgetItem.category')
            ->orderBy('expected_date')
            ->orderBy('period_start')
            ->limit(20)
            ->get()
            ->filter(fn ($p) => $p->budgetItem !== null)
            ->map(fn ($p) => [
                'name' => $p->budgetItem->name,
                'date' => ($p->expected_date ?? $p->period_start)->format('Y-m-d'),
                'direction' => $p->budgetItem->direction,
                'amount' => (float) $p->planned_amount,
                'category' => $p->budgetItem->category?->name,
                'source' => 'budget',
                'confidence_level' => null,
            ])
            ->values();

        // Merge active signals
        $signalItems = CashflowSignal::where('team_id', $teamId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('override_date')
                        ->where('override_date', '>=', now()->startOfDay());
                })->orWhere(function ($or) {
                    $or->whereNull('override_date')
                        ->where('expected_date', '>=', now()->startOfDay());
                });
            })
            ->orderBy('expected_date')
            ->limit(20)
            ->get()
            ->map(fn (CashflowSignal $s) => [
                'name' => $s->label,
                'date' => $s->effectiveDate()->format('Y-m-d'),
                'direction' => $s->direction,
                'amount' => $s->effectiveAmount(),
                'category' => $s->category,
                'source' => 'signal',
                'confidence_level' => $s->confidence_level,
                'signal_id' => $s->id,
                'provider_key' => $s->provider_key,
                'counterparty' => $s->counterparty,
                'url' => $s->url,
            ]);

        return $budgetItems->concat($signalItems)
            ->sortBy('date')
            ->take(30)
            ->values()
            ->toArray();
    }
}
