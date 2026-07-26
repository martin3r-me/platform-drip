<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;

/**
 * Posteingang: Triage der unkategorisierten Transaktionen. Jede TX wird
 * entweder kategorisiert ODER bewusst geparkt („offen lassen") — so kann der
 * Posteingang echt auf 0. Geparkte gelten als entschieden (raus aus dem Backlog).
 */
class Posteingang extends Component
{
    public int $perPage = 50;

    /** false = Posteingang (Backlog), true = bewusst geparkte TX. */
    public bool $showSkipped = false;

    public ?string $result = null;

    public function updatedShowSkipped(): void
    {
        $this->perPage = 50;
    }

    public function loadMore(): void
    {
        $this->perPage += 50;
    }

    private function teamId(): int
    {
        return (int) auth()->user()?->current_team_id;
    }

    private function find(int $transactionId): BankTransaction
    {
        $tx = BankTransaction::where('team_id', $this->teamId())->findOrFail($transactionId);
        abort_unless((int) $tx->team_id === $this->teamId(), 403);

        return $tx;
    }

    /** Kategorie zuweisen (räumt ein evtl. gesetztes Park-Flag ab). */
    public function assign(int $transactionId, $categoryId): void
    {
        $this->find($transactionId)->update([
            'category_id' => $categoryId ?: null,
            'category_skipped' => false,
        ]);
    }

    /** Bewusst offen lassen → raus aus dem Posteingang. */
    public function skip(int $transactionId): void
    {
        $this->find($transactionId)->update(['category_skipped' => true, 'category_id' => null]);
        $this->result = 'Transaktion bewusst offen gelassen.';
    }

    /** Doch wieder in den Posteingang holen. */
    public function unskip(int $transactionId): void
    {
        $this->find($transactionId)->update(['category_skipped' => false]);
        $this->result = 'Zurück in den Posteingang.';
    }

    public function render()
    {
        $teamId = $this->teamId();

        $query = BankTransaction::where('team_id', $teamId)->with('bankAccount');
        $query = $this->showSkipped ? $query->categorySkipped() : $query->needsCategory();

        $transactions = $query
            ->orderByDesc('booked_at')
            ->orderByDesc('created_at')
            ->limit($this->perPage)
            ->get();

        $inboxCount = BankTransaction::where('team_id', $teamId)->needsCategory()->count();
        $skippedCount = BankTransaction::where('team_id', $teamId)->categorySkipped()->count();

        // Flache Optionsliste (Kinder unter dem Parent eingerückt).
        $cats = BankTransactionCategory::where('team_id', $teamId)->orderBy('name')->get();
        $categoryOptions = [];
        foreach ($cats->whereNull('parent_id') as $root) {
            $categoryOptions[] = ['value' => $root->id, 'label' => $root->name];
            foreach ($cats->where('parent_id', $root->id) as $child) {
                $categoryOptions[] = ['value' => $child->id, 'label' => '└ ' . $child->name];
            }
        }

        return view('drip::livewire.posteingang', [
            'transactions' => $transactions,
            'inboxCount' => $inboxCount,
            'skippedCount' => $skippedCount,
            'categoryOptions' => $categoryOptions,
        ])->layout('platform::layouts.app');
    }
}
