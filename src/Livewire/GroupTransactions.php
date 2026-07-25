<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankAccountGroup;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Services\CategorizationService;

class GroupTransactions extends Component
{
    public BankAccountGroup $group;
    public string $search = '';
    public string $direction = '';
    public string $categoryFilter = '';
    public string $sortBy = 'booked_at';
    public string $sortDirection = 'desc';
    public int $perPage = 50;

    /** Vorschlag nach manueller Zuordnung: gleiche Gegenpartei mitziehen. */
    public ?array $learnSuggestion = null;
    public ?string $learnResult = null;

    public function mount(BankAccountGroup $group)
    {
        $this->group = $group;
    }

    public function updatedSearch()
    {
        $this->perPage = 50;
    }

    public function updatedDirection()
    {
        $this->perPage = 50;
    }

    public function updatedCategoryFilter()
    {
        $this->perPage = 50;
    }

    public function loadMore(): void
    {
        $this->perPage += 50;
    }

    public function updateCategory(int $transactionId, $categoryId): void
    {
        $teamId = (int) auth()->user()->current_team_id;
        $transaction = BankTransaction::findOrFail($transactionId);
        abort_unless($transaction->team_id === $teamId, 403);

        $categoryId = $categoryId ?: null;
        $transaction->update(['category_id' => $categoryId]);
        $transaction->refresh();

        // Lernen: gibt es weitere unkategorisierte Transaktionen derselben Gegenpartei?
        $this->learnSuggestion = null;
        $this->learnResult = null;
        if ($categoryId && $transaction->counterparty_name) {
            $count = app(CategorizationService::class)->countUncategorizedForCounterparty($transaction);
            if ($count > 0) {
                $category = BankTransactionCategory::forTeam($teamId)->find($categoryId);
                $this->learnSuggestion = [
                    'counterparty' => $transaction->counterparty_name,
                    'hash' => $transaction->counterparty_name_hash,
                    'category_id' => (int) $categoryId,
                    'category_name' => $category?->name ?? '',
                    'count' => $count,
                ];
            }
        }
    }

    public function applyLearnToAll(): void
    {
        if (!$this->learnSuggestion) {
            return;
        }

        $teamId = (int) auth()->user()->current_team_id;
        $s = $this->learnSuggestion;
        $n = app(CategorizationService::class)->applyToCounterparty($teamId, $s['hash'], $s['category_id']);

        $this->learnSuggestion = null;
        $this->learnResult = "{$n} weitere Transaktion(en) von „{$s['counterparty']}\" zugeordnet.";
    }

    public function applyLearnAndRemember(): void
    {
        if (!$this->learnSuggestion) {
            return;
        }

        $teamId = (int) auth()->user()->current_team_id;
        $s = $this->learnSuggestion;
        $service = app(CategorizationService::class);

        $service->createCounterpartyRule($teamId, $s['counterparty'], $s['category_id'], auth()->id());
        $n = $service->applyToCounterparty($teamId, $s['hash'], $s['category_id']);

        $this->learnSuggestion = null;
        $this->learnResult = "Regel für „{$s['counterparty']}\" angelegt und {$n} Transaktion(en) zugeordnet.";
    }

    public function dismissLearn(): void
    {
        $this->learnSuggestion = null;
    }

    public function sortBy($field)
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function render()
    {
        $query = $this->group->transactions()
            ->with(['bankAccount', 'category'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('remittance_information', 'like', '%' . $this->search . '%')
                      ->orWhere('debtor_name', 'like', '%' . $this->search . '%')
                      ->orWhere('creditor_name', 'like', '%' . $this->search . '%')
                      ->orWhere('counterparty_name', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->direction, function ($query) {
                $query->where('direction', $this->direction);
            })
            ->when($this->categoryFilter !== '', function ($query) {
                if ($this->categoryFilter === 'none') {
                    $query->whereNull('category_id');
                } else {
                    $query->where('category_id', $this->categoryFilter);
                }
            })
            ->orderBy($this->sortBy, $this->sortDirection);

        $totalCount = $query->count();
        $transactions = $query->limit($this->perPage)->get();
        $hasMore = $totalCount > $this->perPage;

        // Summary stats — amounts are encrypted, must compute in PHP
        $allTransactions = $this->group->transactions()->get(['drip_bank_transactions.id', 'amount', 'direction']);
        $totalIncome = $allTransactions->where('direction', 'credit')->sum(fn ($t) => (float) $t->amount);
        $totalExpenses = $allTransactions->where('direction', 'debit')->sum(fn ($t) => abs((float) $t->amount));
        $totalBalance = $totalIncome - $totalExpenses;

        $teamId = (int) auth()->user()->current_team_id;
        $categories = BankTransactionCategory::where('team_id', $teamId)
            ->orderBy('name')
            ->get();

        return view('drip::livewire.group-transactions', [
            'transactions' => $transactions,
            'totalCount' => $totalCount,
            'hasMore' => $hasMore,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'totalBalance' => $totalBalance,
            'categories' => $categories,
        ])->layout('platform::layouts.app');
    }
}
