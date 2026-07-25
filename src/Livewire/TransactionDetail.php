<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Services\CategorizationService;
use Illuminate\Support\Carbon;

class TransactionDetail extends Component
{
    public BankTransaction $transaction;
    public ?int $categoryId = null;

    /** Vorschlag nach manueller Zuordnung: gleiche Gegenpartei mitziehen. */
    public ?array $learnSuggestion = null;

    public function mount(BankTransaction $transaction)
    {
        abort_unless($transaction->team_id === auth()->user()->current_team_id, 403);

        $this->transaction = $transaction->load(['bankAccount.group', 'category']);
        $this->categoryId = $transaction->category_id;
    }

    public function updatedCategoryId($value): void
    {
        $categoryId = $value ?: null;
        $this->transaction->update(['category_id' => $categoryId]);
        $this->transaction->refresh()->load('category');

        $this->learnSuggestion = null;
        if ($categoryId && $this->transaction->counterparty_name) {
            $teamId = (int) auth()->user()->current_team_id;
            $count = app(CategorizationService::class)->countUncategorizedForCounterparty($this->transaction);
            if ($count > 0) {
                $category = BankTransactionCategory::forTeam($teamId)->find($categoryId);
                $this->learnSuggestion = [
                    'counterparty' => $this->transaction->counterparty_name,
                    'hash' => $this->transaction->counterparty_name_hash,
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
        app(CategorizationService::class)->applyToCounterparty($teamId, $s['hash'], $s['category_id']);
        $this->learnSuggestion = null;
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
        $service->applyToCounterparty($teamId, $s['hash'], $s['category_id']);
        $this->learnSuggestion = null;
    }

    public function dismissLearn(): void
    {
        $this->learnSuggestion = null;
    }

    // Budget eingemottet (2026-07): "Budget aus Transaktion anlegen" deaktiviert bis Forecast-Modul.
    // Reaktivieren, sobald die drip.budgets-Route (oder das Forecast-Modul) wieder existiert.
    // public function createBudgetFromTransaction(): void
    // {
    //     $teamId = (int) auth()->user()->current_team_id;
    //     $tx = $this->transaction;
    //
    //     $counterparty = $tx->counterparty_name;
    //     $direction = $tx->direction;
    //
    //     // Find all TXs with same counterparty + direction for avg calculation
    //     $similarTxs = BankTransaction::where('team_id', $teamId)
    //         ->where('direction', $direction)
    //         ->get()
    //         ->filter(fn ($t) => $t->counterparty_name === $counterparty);
    //
    //     $avgAmount = $similarTxs->count() > 0
    //         ? round($similarTxs->avg(fn ($t) => abs((float) $t->amount)), 2)
    //         : abs((float) $tx->amount);
    //
    //     // Calculate most common day of month
    //     $days = $similarTxs
    //         ->map(fn ($t) => $t->booked_at?->day)
    //         ->filter()
    //         ->countBy()
    //         ->sortDesc();
    //     $typicalDay = $days->keys()->first() ?? $tx->booked_at?->day;
    //
    //     $params = http_build_query(array_filter([
    //         'prefill' => 1,
    //         'name' => $counterparty ?: 'Budget',
    //         'amount' => $avgAmount,
    //         'direction' => $direction,
    //         'category_id' => $tx->category_id,
    //         'bank_account_id' => $tx->bank_account_id,
    //         'day_of_month' => $typicalDay,
    //     ]));
    //
    //     $this->redirect(route('drip.budgets') . '?' . $params);
    // }

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
