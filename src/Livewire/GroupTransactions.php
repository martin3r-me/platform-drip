<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankAccountGroup;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;

class GroupTransactions extends Component
{
    public BankAccountGroup $group;
    public string $search = '';
    public string $direction = '';
    public string $categoryFilter = '';
    public string $sortBy = 'booked_at';
    public string $sortDirection = 'desc';
    public int $perPage = 25;

    public function mount(BankAccountGroup $group)
    {
        $this->group = $group;
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedDirection()
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter()
    {
        $this->resetPage();
    }

    public function updateCategory(int $transactionId, $categoryId): void
    {
        $transaction = BankTransaction::findOrFail($transactionId);
        abort_unless($transaction->team_id === auth()->user()->current_team_id, 403);

        $transaction->update([
            'category_id' => $categoryId ?: null,
        ]);
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
        $transactions = $this->group->transactions()
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
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate($this->perPage);

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
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'totalBalance' => $totalBalance,
            'categories' => $categories,
        ])->layout('platform::layouts.app');
    }
}
