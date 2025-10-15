<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankAccountGroup;
use Platform\Drip\Models\BankTransaction;
use Illuminate\Support\Carbon;

class Dashboard extends Component
{
    public int $groupsCount = 0;
    public int $accountsCount = 0;
    public int $transactions30d = 0;
    public $lastSyncAt = null; // string|\DateTimeInterface|null

    public $groups = [];
    public $recentTransactions = [];

    public function mount(): void
    {
        $user = auth()->user();
        $teamId = $user?->current_team_id;

        if (!$teamId) {
            $this->groupsCount = 0;
            $this->accountsCount = 0;
            $this->transactions30d = 0;
            $this->lastSyncAt = null;
            $this->groups = collect();
            $this->recentTransactions = collect();
            return;
        }

        $this->groupsCount = (int) BankAccountGroup::where('team_id', $teamId)->count();
        $this->accountsCount = (int) BankAccount::where('team_id', $teamId)->count();

        $this->transactions30d = (int) BankTransaction::where('team_id', $teamId)
            ->when(true, function ($q) {
                // bevorzugt booked_at, fallback auf created_at
                $q->where(function ($inner) {
                    $inner->whereNotNull('booked_at')
                        ->where('booked_at', '>=', now()->subDays(30))
                        ->orWhere(function ($or) {
                            $or->whereNull('booked_at')
                               ->where('created_at', '>=', now()->subDays(30));
                        });
                });
            })
            ->count();

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
            ->limit(8)
            ->get();
    }
    public function render()
    {
        return view('drip::livewire.dashboard', [
            'groupsCount' => $this->groupsCount,
            'accountsCount' => $this->accountsCount,
            'transactions30d' => $this->transactions30d,
            'lastSyncAt' => $this->lastSyncAt,
            'groups' => $this->groups,
            'recentTransactions' => $this->recentTransactions,
        ])->layout('platform::layouts.app');
    }
}