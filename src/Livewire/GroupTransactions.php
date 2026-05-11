<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankAccountGroup;
use Platform\Drip\Models\BankTransaction;

class GroupTransactions extends Component
{
    public BankAccountGroup $group;
    public string $search = '';
    public string $direction = '';
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
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate($this->perPage);

        // Summary stats — amounts are encrypted, must compute in PHP
        $allTransactions = $this->group->transactions()->get(['id', 'amount', 'direction']);
        $totalIncome = $allTransactions->where('direction', 'credit')->sum(fn ($t) => (float) $t->amount);
        $totalExpenses = $allTransactions->where('direction', 'debit')->sum(fn ($t) => (float) $t->amount);
        $totalBalance = $totalIncome - $totalExpenses;

        return view('drip::livewire.group-transactions', [
            'transactions' => $transactions,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'totalBalance' => $totalBalance,
        ])->layout('platform::layouts.app');
    }
}
