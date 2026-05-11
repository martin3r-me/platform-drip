<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankAccountBalance;
use Platform\Drip\Models\BankAccountGroup;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\BudgetItem;
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
    public array $budgetOverview = [];
    public int $budgetSuggestionsCount = 0;

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

        // Transactions last 30 days — amounts are encrypted, must sum in PHP
        $now = now();
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

        // Category breakdown (last 30 days, debit only)
        $debitTxIds = $current30d->where('direction', 'debit')->pluck('id')->all();
        if (!empty($debitTxIds)) {
            $debitWithCategory = BankTransaction::whereIn('id', $debitTxIds)
                ->get(['id', 'amount', 'category_id']);

            $categoriesById = BankTransactionCategory::where('team_id', $teamId)
                ->get(['id', 'name', 'color'])
                ->keyBy('id');

            $grouped = $debitWithCategory->groupBy('category_id');
            $breakdown = [];

            foreach ($grouped as $catId => $txs) {
                $sum = $txs->sum(fn ($t) => abs((float) $t->amount));
                if ($catId && $categoriesById->has($catId)) {
                    $cat = $categoriesById->get($catId);
                    $breakdown[] = [
                        'name' => $cat->name,
                        'color' => $cat->color ?? '#6B7280',
                        'amount' => $sum,
                    ];
                } else {
                    $breakdown[] = [
                        'name' => 'Ohne Kategorie',
                        'color' => '#9CA3AF',
                        'amount' => $sum,
                        '_uncategorized' => true,
                    ];
                }
            }

            usort($breakdown, function ($a, $b) {
                if (!empty($a['_uncategorized'])) return 1;
                if (!empty($b['_uncategorized'])) return -1;
                return $b['amount'] <=> $a['amount'];
            });

            // Clean up internal flag
            $this->categoryBreakdown = array_map(function ($item) {
                unset($item['_uncategorized']);
                return $item;
            }, array_slice($breakdown, 0, 10));
        }

        // Budget overview — uses fulfillmentForMonth()
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

        // Budget suggestions count
        $this->budgetSuggestionsCount = BudgetItem::where('team_id', $teamId)->suggested()->count();

        // Monthly cashflow (last 6 months)
        $sixMonthsAgo = $now->copy()->startOfMonth()->subMonths(5);
        $monthlyTransactions = BankTransaction::where('team_id', $teamId)
            ->where(function ($q) use ($sixMonthsAgo) {
                $q->where(function ($inner) use ($sixMonthsAgo) {
                    $inner->whereNotNull('booked_at')
                        ->where('booked_at', '>=', $sixMonthsAgo);
                })->orWhere(function ($or) use ($sixMonthsAgo) {
                    $or->whereNull('booked_at')
                        ->where('created_at', '>=', $sixMonthsAgo);
                });
            })
            ->get(['id', 'amount', 'direction', 'booked_at', 'created_at']);

        $this->monthlyFlow = collect(range(0, 5))
            ->map(function ($i) use ($now) {
                return $now->copy()->startOfMonth()->subMonths(5 - $i);
            })
            ->map(function ($monthStart) use ($monthlyTransactions) {
                $monthEnd = $monthStart->copy()->endOfMonth();
                $monthTx = $monthlyTransactions->filter(function ($t) use ($monthStart, $monthEnd) {
                    $date = $t->booked_at ?? $t->created_at;
                    return $date >= $monthStart && $date <= $monthEnd;
                });
                return [
                    'month' => $monthStart->translatedFormat('M Y'),
                    'month_short' => $monthStart->translatedFormat('M'),
                    'income' => $monthTx->where('direction', 'credit')->sum(fn ($t) => (float) $t->amount),
                    'expenses' => $monthTx->where('direction', 'debit')->sum(fn ($t) => abs((float) $t->amount)),
                ];
            })
            ->values()
            ->toArray();

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

    public function render()
    {
        return view('drip::livewire.dashboard')->layout('platform::layouts.app');
    }
}
