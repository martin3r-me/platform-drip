<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Services\CategorizationService;
use Platform\Drip\Services\CategoryException;
use Platform\Drip\Services\CategoryReportService;
use Platform\Drip\Services\CategoryService;

class Categories extends Component
{
    /** Notion-artige Tone-Palette (aus ui-styles: --nx-tone-*). Wert = Tone-Name. */
    public const TONES = ['rose', 'amber', 'emerald', 'teal', 'sky', 'indigo', 'violet', 'pink', 'slate'];

    /** Auswertungs-Zeitraum für den Baum: total | year | month */
    public string $period = 'total';

    /** Ausgewählte Kategorie (Mitte: deren Transaktionen). */
    public ?int $selectedCategoryId = null;
    public int $perPage = 50;

    /** Abgelehnte/gelöschte Zahlungen einblenden (Standard: aus). */
    public bool $showDisregarded = false;

    /** Edit-Panel (rechts). */
    public ?int $editingId = null;
    public bool $panelOpen = false;
    public array $form = [
        'name' => '',
        'color' => null,
        'parent_id' => null,
        'direction' => 'debit',
        'default_tax_rate' => null,
    ];

    /** Lernen aus manueller Umkategorisierung. */
    public ?array $learnSuggestion = null;
    public ?string $learnResult = null;

    protected function emptyForm(): array
    {
        return [
            'name' => '',
            'color' => null,
            'parent_id' => null,
            'direction' => 'debit',
            'default_tax_rate' => null,
        ];
    }

    // ── Navigation (Baum links → Transaktionen Mitte) ──

    public function selectCategory(int $id): void
    {
        $this->selectedCategoryId = $id;
        $this->perPage = 50;
        $this->showDisregarded = false;
        $this->learnSuggestion = null;
        $this->learnResult = null;
    }

    public function loadMore(): void
    {
        $this->perPage += 50;
    }

    // ── Edit-Panel (rechts) ──

    public function create(): void
    {
        $this->editingId = null;
        $this->form = $this->emptyForm();
        $this->resetValidation();
        $this->panelOpen = true;
    }

    public function edit(int $id): void
    {
        $category = BankTransactionCategory::forTeam($this->teamId())->findOrFail($id);

        $this->editingId = $category->id;
        $this->form = [
            'name' => $category->name,
            'color' => $category->color,
            'parent_id' => $category->parent_id,
            'direction' => $category->direction ?: 'debit',
            'default_tax_rate' => $category->default_tax_rate !== null ? (string) (float) $category->default_tax_rate : null,
        ];
        $this->resetValidation();
        $this->panelOpen = true;
    }

    public function save(CategoryService $service): void
    {
        $this->resetValidation();

        try {
            if ($this->editingId) {
                $category = BankTransactionCategory::forTeam($this->teamId())->findOrFail($this->editingId);
                $service->update($category, $this->form);
            } else {
                $service->create($this->teamId(), $this->form);
            }
        } catch (CategoryException $e) {
            $this->addError('form.' . $e->field, $e->getMessage());
            return;
        }

        $this->closePanel();
    }

    public function closePanel(): void
    {
        $this->panelOpen = false;
        $this->editingId = null;
        $this->form = $this->emptyForm();
        $this->resetValidation();
    }

    public function delete(int $id, CategoryService $service): void
    {
        $category = BankTransactionCategory::forTeam($this->teamId())->findOrFail($id);
        $service->delete($category);

        if ($this->editingId === $id) {
            $this->closePanel();
        }
        if ($this->selectedCategoryId === $id) {
            $this->selectedCategoryId = null;
        }
    }

    // ── Inline-Umkategorisieren (Mitte) + Lernen ──

    public function updateTransactionCategory(int $transactionId, $categoryId): void
    {
        $teamId = $this->teamId();
        $transaction = BankTransaction::findOrFail($transactionId);
        abort_unless((int) $transaction->team_id === $teamId, 403);

        $categoryId = $categoryId ?: null;
        $transaction->update(['category_id' => $categoryId]);
        $transaction->refresh();

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
        $s = $this->learnSuggestion;
        $n = app(CategorizationService::class)->applyToCounterparty($this->teamId(), $s['hash'], $s['category_id']);
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
        $service->createCounterpartyRule($this->teamId(), $s['counterparty'], $s['category_id'], auth()->id());
        $n = $service->applyToCounterparty($this->teamId(), $s['hash'], $s['category_id']);
        $this->learnSuggestion = null;
        $this->learnResult = "Regel für „{$s['counterparty']}\" angelegt und {$n} Transaktion(en) zugeordnet.";
    }

    public function dismissLearn(): void
    {
        $this->learnSuggestion = null;
    }

    protected function teamId(): int
    {
        return (int) auth()->user()?->current_team_id;
    }

    public function render(CategoryReportService $report)
    {
        $teamId = $this->teamId();
        $overview = $report->overview($teamId, $this->period);

        // Flache Optionsliste für Inline-Select (Kinder mit "└ " eingerückt).
        $categoryOptions = [];
        foreach ($overview['roots'] as $root) {
            $categoryOptions[] = ['value' => $root->id, 'label' => $root->name];
            foreach ($root->children as $child) {
                $categoryOptions[] = ['value' => $child->id, 'label' => '└ ' . $child->name];
            }
        }

        // Ausgewählte Kategorie + ihre Transaktionen (Mitte).
        $selected = null;
        $transactions = collect();
        $selectedCount = 0;
        $disregardedCount = 0;
        if ($this->selectedCategoryId) {
            $selected = BankTransactionCategory::forTeam($teamId)->find($this->selectedCategoryId);
            if ($selected) {
                $selectedCount = BankTransaction::where('team_id', $teamId)
                    ->where('category_id', $selected->id)
                    ->counted()
                    ->count();
                $disregardedCount = BankTransaction::where('team_id', $teamId)
                    ->where('category_id', $selected->id)
                    ->disregarded()
                    ->count();
                $transactions = BankTransaction::where('team_id', $teamId)
                    ->where('category_id', $selected->id)
                    ->when(! $this->showDisregarded, fn ($q) => $q->counted())
                    ->with('bankAccount')
                    ->orderByDesc('booked_at')
                    ->orderByDesc('created_at')
                    ->limit($this->perPage)
                    ->get();
            } else {
                $this->selectedCategoryId = null;
            }
        }

        return view('drip::livewire.categories', [
            'groups' => $overview['groups'],
            'coverage' => $overview['coverage'],
            'rootCategories' => $overview['roots'],
            'categoryOptions' => $categoryOptions,
            'selected' => $selected,
            'transactions' => $transactions,
            'selectedCount' => $selectedCount,
            'disregardedCount' => $disregardedCount,
            'tones' => self::TONES,
            'directionOptions' => [
                ['value' => 'debit', 'label' => 'Ausgabe'],
                ['value' => 'credit', 'label' => 'Einnahme'],
                ['value' => 'both', 'label' => 'Beides'],
            ],
            'taxOptions' => [
                ['value' => '0', 'label' => '0 %'],
                ['value' => '7', 'label' => '7 %'],
                ['value' => '19', 'label' => '19 %'],
            ],
        ])->layout('platform::layouts.app');
    }
}
