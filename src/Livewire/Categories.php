<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Services\CategoryException;
use Platform\Drip\Services\CategoryReportService;
use Platform\Drip\Services\CategoryService;

class Categories extends Component
{
    /** Notion-artige Tone-Palette (aus ui-styles: --nx-tone-*). Wert = Tone-Name. */
    public const TONES = ['rose', 'amber', 'emerald', 'teal', 'sky', 'indigo', 'violet', 'pink', 'slate'];

    public ?int $editingId = null;
    public bool $panelOpen = false;

    /** Auswertungs-Zeitraum: total | year | month */
    public string $period = 'total';

    public array $form = [
        'name' => '',
        'color' => null,
        'parent_id' => null,
        'direction' => 'debit',
        'default_tax_rate' => null,
    ];

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
    }

    protected function teamId(): int
    {
        return (int) auth()->user()?->current_team_id;
    }

    public function render(CategoryReportService $report)
    {
        $overview = $report->overview($this->teamId(), $this->period);

        return view('drip::livewire.categories', [
            'groups' => $overview['groups'],
            'coverage' => $overview['coverage'],
            'rootCategories' => $overview['roots'],
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
