<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;

class TransactionDetail extends Component
{
    public BankTransaction $transaction;
    public ?int $categoryId = null;

    public function mount(BankTransaction $transaction)
    {
        abort_unless($transaction->team_id === auth()->user()->current_team_id, 403);

        $this->transaction = $transaction->load(['bankAccount.group', 'category']);
        $this->categoryId = $transaction->category_id;
    }

    public function updatedCategoryId($value): void
    {
        $this->transaction->update([
            'category_id' => $value ?: null,
        ]);

        $this->transaction->refresh()->load('category');
    }

    public function render()
    {
        $teamId = (int) auth()->user()->current_team_id;

        $categories = BankTransactionCategory::where('team_id', $teamId)
            ->orderBy('name')
            ->get();

        return view('drip::livewire.transaction-detail', [
            'categories' => $categories,
        ])->layout('platform::layouts.app');
    }
}
