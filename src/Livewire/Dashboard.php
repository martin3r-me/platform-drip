<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankAccountBalance;
use Platform\Drip\Models\BankAccountGroup;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Models\CashflowSnapshot;
use Platform\Drip\Models\LiquidityForecast;
use Illuminate\Support\Carbon;

class Dashboard extends Component
{
    public int $groupsCount = 0;
    public int $accountsCount = 0;
    public int $transactions30d = 0;
    public $lastSyncAt = null;

    public float $totalBalance = 0;
    public float $income30d = 0;
    public float $expenses30d = 0;
    public float $incomePrev30d = 0;
    public float $expensesPrev30d = 0;

    public array $monthlyFlow = [];
    public array $categoryBreakdown = [];
    public array $topCounterparties = [];
    public array $budgetOverview = [];
    public int $budgetSuggestionsCount = 0;

    public array $budgetSummary = [];
    public array $alerts = [];

    public $groups = [];
    public $recentTransactions = [];

    public function mount(): void
    {
        $user = auth()->user();
        $teamId = $user?->current_team_id;

        if (!$teamId) {
            $this->groups = collect();
            $this->recentTransactions = collect();
            return;
        }

        $this->groupsCount = (int) BankAccountGroup::where('team_id', $teamId)->count();
        $this->accountsCount = (int) BankAccount::where('team_id', $teamId)->count();

        // Total balance: sum latest balance per account
        $this->totalBalance = BankAccountBalance::where('team_id', $teamId)
            ->get()
            ->groupBy('bank_account_id')
            ->map(fn ($balances) => $balances->sortByDesc('retrieved_at')->first())
            ->sum(fn ($b) => (float) ($b->amount ?? $b->balance ?? 0));

        // Stat cards: rolling 30-day windows (kept as live scan for precision)
        $now = now();
        $currentMonth = $now->format('Y-m');

        $this->loadStatCards($teamId, $now);

        // Monthly cashflow (6 months) — from snapshots
        $this->monthlyFlow = $this->loadMonthlyFlowFromSnapshots($teamId, $now);

        // Category breakdown (current month, debit) — from snapshots
        $this->categoryBreakdown = $this->loadCategoryBreakdownFromSnapshots($teamId, $currentMonth);

        // Top counterparties (current month, debit) — from snapshots
        $this->topCounterparties = $this->loadTopCounterpartiesFromSnapshots($teamId, $currentMonth);

        // Budget overview
        $budgetItems = BudgetItem::where('team_id', $teamId)->active()->with('category')->get();
        $budgetMonthStart = now()->startOfMonth();

        $this->budgetOverview = $budgetItems->map(function (BudgetItem $item) use ($teamId, $budgetMonthStart) {
            $fulfillment = $item->fulfillmentForMonth($budgetMonthStart, $teamId);
            return [
                'name' => $item->name,
                'category_color' => $item->category?->color ?? '#6B7280',
                'budget' => $fulfillment['budget'],
                'actual' => $fulfillment['actual'],
                'percent' => $fulfillment['percent'],
            ];
        })->toArray();

        $this->budgetSuggestionsCount = BudgetItem::where('team_id', $teamId)->suggested()->count();

        // Budget Summary
        $this->budgetSummary = $this->loadBudgetSummary($this->budgetOverview);

        // Alerts
        $this->alerts = $this->loadAlerts($teamId);

        $this->lastSyncAt = BankAccount::where('team_id', $teamId)
            ->max('last_transactions_synced_at');

        $this->groups = BankAccountGroup::withCount('bankAccounts')
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->limit(8)
            ->get();

        $this->recentTransactions = BankTransaction::with(['bankAccount'])
            ->where('team_id', $teamId)
            ->orderByDesc('booked_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    protected function loadStatCards(int $teamId, Carbon $now): void
    {
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $sixtyDaysAgo = $now->copy()->subDays(60);

        $transactions60d = BankTransaction::where('team_id', $teamId)
            ->where(function ($q) use ($sixtyDaysAgo) {
                $q->where(function ($inner) use ($sixtyDaysAgo) {
                    $inner->whereNotNull('booked_at')
                        ->where('booked_at', '>=', $sixtyDaysAgo);
                })->orWhere(function ($or) use ($sixtyDaysAgo) {
                    $or->whereNull('booked_at')
                        ->where('created_at', '>=', $sixtyDaysAgo);
                });
            })
            ->get(['id', 'amount', 'direction', 'booked_at', 'created_at']);

        $current30d = $transactions60d->filter(function ($t) use ($thirtyDaysAgo) {
            $date = $t->booked_at ?? $t->created_at;
            return $date >= $thirtyDaysAgo;
        });

        $prev30d = $transactions60d->filter(function ($t) use ($thirtyDaysAgo, $sixtyDaysAgo) {
            $date = $t->booked_at ?? $t->created_at;
            return $date >= $sixtyDaysAgo && $date < $thirtyDaysAgo;
        });

        $this->transactions30d = $current30d->count();
        $this->income30d = $current30d->where('direction', 'credit')->sum(fn ($t) => (float) $t->amount);
        $this->expenses30d = $current30d->where('direction', 'debit')->sum(fn ($t) => abs((float) $t->amount));
        $this->incomePrev30d = $prev30d->where('direction', 'credit')->sum(fn ($t) => (float) $t->amount);
        $this->expensesPrev30d = $prev30d->where('direction', 'debit')->sum(fn ($t) => abs((float) $t->amount));
    }

    protected function loadMonthlyFlowFromSnapshots(int $teamId, Carbon $now): array
    {
        $monthKeys = collect(range(0, 5))->map(fn ($i) => $now->copy()->subMonths(5 - $i)->format('Y-m'));

        $snapshots = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->teamWide()
            ->where('category_id', CashflowSnapshot::SENTINEL_ALL)
            ->where('counterparty_hash', CashflowSnapshot::SENTINEL_HASH_ALL)
            ->whereIn('period_key', $monthKeys->all())
            ->get();

        $indexed = $snapshots->groupBy(fn ($s) => $s->period_key . '|' . $s->direction);

        return $monthKeys->map(function (string $pk) use ($indexed) {
            $monthStart = Carbon::createFromFormat('Y-m', $pk)->startOfMonth();
            return [
                'month' => $monthStart->translatedFormat('M Y'),
                'month_short' => $monthStart->translatedFormat('M'),
                'income' => (float) ($indexed->get($pk . '|credit')?->first()?->total_amount ?? 0),
                'expenses' => (float) ($indexed->get($pk . '|debit')?->first()?->total_amount ?? 0),
            ];
        })->values()->toArray();
    }

    protected function loadCategoryBreakdownFromSnapshots(int $teamId, string $monthKey): array
    {
        $rows = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->teamWide()
            ->where('direction', 'debit')
            ->where('period_key', $monthKey)
            ->where('category_id', '!=', CashflowSnapshot::SENTINEL_ALL)
            ->where('counterparty_hash', CashflowSnapshot::SENTINEL_HASH_ALL)
            ->where('total_amount', '>', 0)
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $categoryIds = $rows->pluck('category_id')->filter()->unique();
        $categories = BankTransactionCategory::whereIn('id', $categoryIds)
            ->get(['id', 'name', 'color'])
            ->keyBy('id');

        return $rows->map(fn ($row) => [
            'name' => $categories[$row->category_id]?->name ?? 'Ohne Kategorie',
            'color' => $categories[$row->category_id]?->color ?? '#9CA3AF',
            'amount' => (float) $row->total_amount,
        ])->toArray();
    }

    protected function loadTopCounterpartiesFromSnapshots(int $teamId, string $monthKey): array
    {
        $rows = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->teamWide()
            ->where('direction', 'debit')
            ->where('period_key', $monthKey)
            ->where('counterparty_hash', '!=', CashflowSnapshot::SENTINEL_HASH_ALL)
            ->where('category_id', CashflowSnapshot::SENTINEL_ALL)
            ->where('total_amount', '>', 0)
            ->orderByDesc('total_amount')
            ->limit(8)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Resolve names via single TX lookup per hash
        $hashes = $rows->pluck('counterparty_hash')->unique()->all();
        $names = [];
        foreach ($hashes as $hash) {
            $tx = BankTransaction::where('team_id', $teamId)
                ->where('counterparty_name_hash', $hash)
                ->whereNotNull('counterparty_name')
                ->first(['counterparty_name']);
            $names[$hash] = $tx?->counterparty_name ?? '(unbekannt)';
        }

        return $rows->map(fn ($row) => [
            'name' => $names[$row->counterparty_hash] ?? '(unbekannt)',
            'amount' => (float) $row->total_amount,
            'count' => (int) $row->transaction_count,
        ])->toArray();
    }

    protected function loadBudgetSummary(array $budgetOverview): array
    {
        if (empty($budgetOverview)) {
            return [];
        }

        $total = array_sum(array_column($budgetOverview, 'budget'));
        $actual = array_sum(array_column($budgetOverview, 'actual'));
        $remaining = $total - $actual;
        $percent = $total > 0 ? round($actual / $total * 100, 1) : 0;
        $atRisk = count(array_filter($budgetOverview, fn ($b) => $b['percent'] >= 80 && $b['percent'] < 100));
        $exceeded = count(array_filter($budgetOverview, fn ($b) => $b['percent'] >= 100));

        return [
            'total' => $total,
            'actual' => $actual,
            'remaining' => $remaining,
            'percent' => $percent,
            'at_risk' => $atRisk,
            'exceeded' => $exceeded,
        ];
    }

    protected function loadAlerts(int $teamId): array
    {
        $alerts = [];

        // Budget alerts from overview
        $over80 = count(array_filter($this->budgetOverview, fn ($b) => $b['percent'] >= 80 && $b['percent'] < 100));
        $exceeded = count(array_filter($this->budgetOverview, fn ($b) => $b['percent'] >= 100));

        if ($exceeded > 0) {
            $alerts[] = [
                'type' => 'danger',
                'icon' => 'exclamation-triangle',
                'message' => $exceeded . ' ' . ($exceeded === 1 ? 'Budget ueberschritten' : 'Budgets ueberschritten'),
                'link' => route('drip.budgets'),
            ];
        }

        if ($over80 > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'exclamation-triangle',
                'message' => $over80 . ' ' . ($over80 === 1 ? 'Budget' : 'Budgets') . ' ueber 80%',
                'link' => route('drip.budgets'),
            ];
        }

        // Uncategorized transactions (last 30 days)
        $uncategorized = BankTransaction::where('team_id', $teamId)
            ->whereNull('category_id')
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('booked_at')
                        ->where('booked_at', '>=', now()->subDays(30));
                })->orWhere(function ($or) {
                    $or->whereNull('booked_at')
                        ->where('created_at', '>=', now()->subDays(30));
                });
            })
            ->count();

        if ($uncategorized > 0) {
            $alerts[] = [
                'type' => 'info',
                'icon' => 'tag',
                'message' => $uncategorized . ' unkategorisierte ' . ($uncategorized === 1 ? 'Transaktion' : 'Transaktionen'),
                'link' => route('drip.categories'),
            ];
        }

        // Unconfirmed suggestions
        if ($this->budgetSuggestionsCount > 0) {
            $alerts[] = [
                'type' => 'primary',
                'icon' => 'light-bulb',
                'message' => $this->budgetSuggestionsCount . ' unbestaetigte ' . ($this->budgetSuggestionsCount === 1 ? 'Vorschlag' : 'Vorschlaege'),
                'link' => route('drip.budgets'),
            ];
        }

        // Liquidity warning: projected balance < 0 in next 30 days
        $negativeDay = LiquidityForecast::where('team_id', $teamId)
            ->where('forecast_date', '>=', now()->startOfDay())
            ->where('forecast_date', '<=', now()->addDays(30))
            ->where('projected_balance', '<', 0)
            ->orderBy('forecast_date')
            ->first();

        if ($negativeDay) {
            $alerts[] = [
                'type' => 'danger',
                'icon' => 'arrow-trending-down',
                'message' => 'Negativsaldo am ' . $negativeDay->forecast_date->format('d.m.Y') . ' prognostiziert',
                'link' => route('drip.liquidity'),
            ];
        }

        return $alerts;
    }

    public function render()
    {
        return view('drip::livewire.dashboard')->layout('platform::layouts.app');
    }
}
