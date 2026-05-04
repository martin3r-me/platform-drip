<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransaction;

class TransactionDetail extends Component
{
    public BankTransaction $transaction;

    public function mount(BankTransaction $transaction)
    {
        abort_unless($transaction->team_id === auth()->user()->current_team_id, 403);

        $this->transaction = $transaction->load(['bankAccount.group', 'category']);
    }

    public function render()
    {
        return view('drip::livewire.transaction-detail')
            ->layout('platform::layouts.app');
    }
}
