<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Services\CategorizationService;

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

    /** Vorschlag nach manueller Zuordnung: gleiche Gegenpartei mitziehen. */
    public ?array $learnSuggestion = null;
    public ?string $learnResult = null;

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
        $tx = $this->find($transactionId);
        $categoryId = $categoryId ?: null;
        $tx->update(['category_id' => $categoryId, 'category_skipped' => false]);
        $tx->refresh();

        // Mitlernen: gleiche Gegenpartei + Richtung noch unkategorisiert? → anbieten.
        $this->learnSuggestion = null;
        $this->learnResult = null;
        if ($categoryId && $tx->counterparty_name) {
            $count = app(CategorizationService::class)->countUncategorizedForCounterparty($tx);
            if ($count > 0) {
                $category = BankTransactionCategory::forTeam($this->teamId())->find($categoryId);
                $this->learnSuggestion = [
                    'counterparty' => $tx->counterparty_name,
                    'hash' => $tx->counterparty_name_hash,
                    'direction' => $tx->direction,
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
        $s = $this->learnSuggestion;
        $n = app(CategorizationService::class)->applyToCounterparty($this->teamId(), $s['hash'], $s['category_id'], true, $s['direction'] ?? null);
        $this->learnSuggestion = null;
        $this->learnResult = "{$n} weitere Transaktion(en) von „{$s['counterparty']}\" zugeordnet.";
    }

    public function applyLearnAndRemember(): void
    {
        if (!$this->learnSuggestion) {
            return;
        }
        $s = $this->learnSuggestion;
        $service = app(CategorizationService::class);
        $service->createCounterpartyRule($this->teamId(), $s['counterparty'], $s['category_id'], auth()->id(), $s['direction'] ?? null);
        $n = $service->applyToCounterparty($this->teamId(), $s['hash'], $s['category_id'], true, $s['direction'] ?? null);
        $this->learnSuggestion = null;
        $this->learnResult = "Regel für „{$s['counterparty']}\" angelegt und {$n} Transaktion(en) zugeordnet.";
    }

    public function dismissLearn(): void
    {
        $this->learnSuggestion = null;
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
