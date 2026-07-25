<?php

namespace Platform\Drip\Livewire;

use Illuminate\Support\Carbon;
use Livewire\Component;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;

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
        $category = BankTransactionCategory::where('team_id', $this->teamId())->findOrFail($id);

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

    public function save(): void
    {
        $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.color' => ['nullable', 'string', 'max:20'],
            'form.parent_id' => ['nullable', 'integer', 'exists:drip_bank_transaction_categories,id'],
            'form.direction' => ['required', 'in:debit,credit,both'],
            'form.default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $taxRaw = $this->form['default_tax_rate'];

        $data = [
            'name' => $this->form['name'],
            'color' => $this->form['color'] ?: null,
            'parent_id' => $this->form['parent_id'] ?: null,
            'direction' => $this->form['direction'] ?: 'debit',
            'default_tax_rate' => ($taxRaw === '' || $taxRaw === null) ? null : (float) $taxRaw,
        ];

        if ($this->editingId) {
            $category = BankTransactionCategory::where('team_id', $this->teamId())->findOrFail($this->editingId);
            // Verhindere, dass eine Kategorie ihr eigenes Kind als Parent bekommt (einfacher Zyklus-Schutz)
            if ((int) $data['parent_id'] === (int) $this->editingId) {
                $data['parent_id'] = null;
            }
            $category->update($data);
        } else {
            BankTransactionCategory::create($data);
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

    public function delete(int $id): void
    {
        $category = BankTransactionCategory::where('team_id', $this->teamId())->findOrFail($id);

        // Unterkategorien auf Root-Ebene heben (statt mitzulöschen)
        BankTransactionCategory::where('parent_id', $category->id)->update(['parent_id' => null]);

        $category->delete();

        if ($this->editingId === $id) {
            $this->closePanel();
        }
    }

    protected function teamId(): int
    {
        return (int) auth()->user()?->current_team_id;
    }

    /** [von, bis] für den gewählten Zeitraum, oder null für "total". */
    protected function periodRange(): ?array
    {
        return match ($this->period) {
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => null,
        };
    }

    protected function applyPeriod($query, ?array $range)
    {
        if (!$range) {
            return $query;
        }
        [$from, $to] = $range;

        return $query->where(function ($w) use ($from, $to) {
            $w->where(function ($i) use ($from, $to) {
                $i->whereNotNull('booked_at')->whereBetween('booked_at', [$from, $to]);
            })->orWhere(function ($o) use ($from, $to) {
                $o->whereNull('booked_at')->whereBetween('created_at', [$from, $to]);
            });
        });
    }

    public function render()
    {
        $teamId = $this->teamId();
        $range = $this->periodRange();

        // Volumen pro Kategorie – amount ist verschlüsselt → Aggregation in PHP.
        $txs = $this->applyPeriod(
            BankTransaction::where('team_id', $teamId)->whereNotNull('category_id'),
            $range
        )->get(['category_id', 'amount']);

        $volumes = [];
        foreach ($txs as $t) {
            $cid = (int) $t->category_id;
            $volumes[$cid] ??= ['vol' => 0.0, 'cnt' => 0];
            $volumes[$cid]['vol'] += abs((float) $t->amount);
            $volumes[$cid]['cnt']++;
        }

        // Deckungsgrad (Counts auf unverschlüsselten Spalten → SQL).
        $coverageTotal = $this->applyPeriod(BankTransaction::where('team_id', $teamId), $range)->count();
        $coverageCategorized = $this->applyPeriod(
            BankTransaction::where('team_id', $teamId)->whereNotNull('category_id'),
            $range
        )->count();

        $coverage = [
            'total' => $coverageTotal,
            'categorized' => $coverageCategorized,
            'uncategorized' => max(0, $coverageTotal - $coverageCategorized),
            'pct' => $coverageTotal > 0 ? round($coverageCategorized / $coverageTotal * 100) : 0,
        ];

        // Kategorie-Baum, gruppiert nach Richtung, mit gerollten Volumen (eigen + Kinder).
        $roots = BankTransactionCategory::where('team_id', $teamId)
            ->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        $groups = ['credit' => [], 'debit' => [], 'both' => []];

        foreach ($roots as $root) {
            $childrenData = $root->children->map(fn ($c) => [
                'cat' => $c,
                'vol' => $volumes[$c->id]['vol'] ?? 0.0,
                'cnt' => $volumes[$c->id]['cnt'] ?? 0,
            ]);

            $ownVol = $volumes[$root->id]['vol'] ?? 0.0;
            $ownCnt = $volumes[$root->id]['cnt'] ?? 0;

            $node = [
                'cat' => $root,
                'own_vol' => $ownVol,
                'own_cnt' => $ownCnt,
                'total_vol' => $ownVol + $childrenData->sum('vol'),
                'total_cnt' => $ownCnt + $childrenData->sum('cnt'),
                'children' => $childrenData,
            ];

            $dir = in_array($root->direction, ['credit', 'debit', 'both'], true) ? $root->direction : 'debit';
            $groups[$dir][] = $node;
        }

        return view('drip::livewire.categories', [
            'groups' => $groups,
            'coverage' => $coverage,
            'rootCategories' => $roots,
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
