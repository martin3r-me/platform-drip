<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Services\CategorizationService;
use Platform\Drip\Services\GegenparteiService;
use Platform\Drip\Services\KontierungService;
use Illuminate\Support\Carbon;

class TransactionDetail extends Component
{
    public BankTransaction $transaction;
    public ?int $categoryId = null;

    /** Vorschlag nach manueller Zuordnung: gleiche Gegenpartei mitziehen. */
    public ?array $learnSuggestion = null;

    /** Kontierung: Leistungsempfänger (Org-Entities) mit %-Anteil. */
    public bool $kontierungAvailable = false;
    public array $kontierung = [];
    public ?string $kontierungResult = null;

    /** Gegenpartei (Umwelt): entity-Link an der Transaktion; IBAN nur Auflöser. */
    public bool $gegenparteiAvailable = false;
    public ?string $counterpartyIban = null;
    public ?array $resolvedGegenpartei = null;   // aktueller entity-Link (die Gegenpartei)
    public ?array $gegenparteiSuggestion = null; // Vorschlag aus IBAN, noch nicht gesetzt
    public ?int $gegenparteiEntityId = null;
    public ?string $gegenparteiResult = null;

    public function mount(BankTransaction $transaction, KontierungService $kontierung, GegenparteiService $gegenpartei)
    {
        abort_unless($transaction->team_id === auth()->user()->current_team_id, 403);

        $this->transaction = $transaction->load(['bankAccount.group', 'category']);
        $this->categoryId = $transaction->category_id;

        $this->kontierungAvailable = $kontierung->available();
        $this->kontierung = $kontierung->forTransaction($transaction->id);

        // Gegenpartei (Umwelt-Seite): aktueller entity-Link. Fehlt er, aber die
        // Transaktion trägt eine (richtungsaufgelöste) IBAN, schlagen wir die
        // per IBAN aufgelöste Entity als Ein-Klick-Zuordnung vor.
        $this->gegenparteiAvailable = $gegenpartei->available();
        $this->counterpartyIban = $transaction->counterparty_iban
            ?: ($transaction->direction === 'debit' ? $transaction->creditor_account_iban : $transaction->debtor_account_iban);
        $this->resolvedGegenpartei = $gegenpartei->forTransaction((int) $transaction->id);
        if (!$this->resolvedGegenpartei && $this->counterpartyIban) {
            $this->gegenparteiSuggestion = $gegenpartei->resolveIban($this->counterpartyIban, (int) $transaction->team_id);
        }
    }

    public function saveGegenpartei(GegenparteiService $gegenpartei): void
    {
        if (!$this->gegenparteiEntityId) {
            return;
        }

        // Manuelle Zuordnung: entity-Link setzen. Falls eine IBAN vorliegt,
        // gleich als Identifier lernen → gleichartige Transaktionen lösen sich
        // künftig automatisch auf.
        $gegenpartei->setForTransaction(
            (int) $this->transaction->id,
            (int) $this->gegenparteiEntityId,
            (int) $this->transaction->team_id,
            $this->counterpartyIban ?: null,
        );

        $this->resolvedGegenpartei = $gegenpartei->forTransaction((int) $this->transaction->id);
        $this->gegenparteiSuggestion = null;
        $this->gegenparteiEntityId = null;
        $this->gegenparteiResult = $this->resolvedGegenpartei
            ? 'Gegenpartei „' . $this->resolvedGegenpartei['name'] . '" gesetzt.'
            : null;
    }

    public function applyGegenparteiSuggestion(GegenparteiService $gegenpartei): void
    {
        if (!$this->gegenparteiSuggestion) {
            return;
        }

        $gegenpartei->setForTransaction(
            (int) $this->transaction->id,
            (int) $this->gegenparteiSuggestion['id'],
            (int) $this->transaction->team_id,
            $this->counterpartyIban ?: null,
        );

        $this->resolvedGegenpartei = $gegenpartei->forTransaction((int) $this->transaction->id);
        $this->gegenparteiResult = $this->resolvedGegenpartei
            ? 'Gegenpartei „' . $this->resolvedGegenpartei['name'] . '" aus IBAN übernommen.'
            : null;
        $this->gegenparteiSuggestion = null;
    }

    public function clearGegenpartei(GegenparteiService $gegenpartei): void
    {
        $gegenpartei->clearForTransaction((int) $this->transaction->id);
        $this->resolvedGegenpartei = null;

        // Nach dem Lösen wieder einen IBAN-Vorschlag anbieten, falls vorhanden.
        if ($this->counterpartyIban) {
            $this->gegenparteiSuggestion = $gegenpartei->resolveIban($this->counterpartyIban, (int) $this->transaction->team_id);
        }
        $this->gegenparteiResult = 'Gegenpartei-Zuordnung entfernt.';
    }

    public function addKontierung(): void
    {
        $this->kontierung[] = ['dimension_value_id' => null, 'percentage' => null];
    }

    public function removeKontierung(int $index): void
    {
        unset($this->kontierung[$index]);
        $this->kontierung = array_values($this->kontierung);
    }

    public function saveKontierung(KontierungService $kontierung): void
    {
        $this->resetErrorBag('kontierung');

        $rows = [];
        $sum = 0.0;
        foreach ($this->kontierung as $row) {
            $valueId = (int) ($row['dimension_value_id'] ?? 0);
            $pct = (float) ($row['percentage'] ?? 0);
            if ($valueId <= 0 || $pct <= 0) {
                continue;
            }
            $rows[] = ['dimension_value_id' => $valueId, 'percentage' => $pct];
            $sum += $pct;
        }

        if ($sum > 100.0) {
            $this->addError('kontierung', 'Die Summe der Anteile darf 100 % nicht überschreiten (aktuell ' . rtrim(rtrim(number_format($sum, 1, ',', '.'), '0'), ',') . ' %).');
            return;
        }

        $kontierung->syncForTransaction($this->transaction->id, (int) $this->transaction->team_id, $rows);

        $this->kontierung = $kontierung->forTransaction($this->transaction->id);
        $this->kontierungResult = $rows === []
            ? 'Kontierung entfernt.'
            : count($rows) . ' Empfänger kontiert (' . rtrim(rtrim(number_format($sum, 1, ',', '.'), '0'), ',') . ' %).';
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

    public function render(KontierungService $kontierung, GegenparteiService $gegenpartei)
    {
        $teamId = (int) auth()->user()->current_team_id;

        $categories = BankTransactionCategory::where('team_id', $teamId)
            ->orderBy('name')
            ->get();

        $kontierungSum = array_sum(array_map(fn ($r) => (float) ($r['percentage'] ?? 0), $this->kontierung));

        // Entity-Optionen laden, solange noch keine Gegenpartei gesetzt ist
        // (manuelle Auswahl geht auch ohne IBAN, z. B. Kartenzahlung).
        $needsGegenparteiPicker = $this->gegenparteiAvailable && !$this->resolvedGegenpartei;

        return view('drip::livewire.transaction-detail', [
            'categories' => $categories,
            'kontierungOptions' => $this->kontierungAvailable ? $kontierung->recipientOptions() : [],
            'kontierungSum' => $kontierungSum,
            'gegenparteiOptions' => $needsGegenparteiPicker ? $gegenpartei->entityOptions($teamId) : [],
        ])->layout('platform::layouts.app');
    }
}
