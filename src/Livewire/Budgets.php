<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\BudgetItem;

class Budgets extends Component
{
    public ?int $editingId = null;

    public string $formName = '';
    public ?int $formCategoryId = null;
    public string $formDirection = 'debit';
    public string $formAmount = '';
    public string $formFrequency = 'monthly';
    public ?int $formDayOfMonth = null;
    public bool $formIsActive = true;

    protected function rules(): array
    {
        return [
            'formName' => ['required', 'string', 'max:255'],
            'formCategoryId' => ['nullable', 'integer', 'exists:drip_bank_transaction_categories,id'],
            'formDirection' => ['required', 'string', 'in:debit,credit'],
            'formAmount' => ['required', 'numeric', 'min:0.01'],
            'formFrequency' => ['required', 'string', 'in:monthly,quarterly,yearly,weekly'],
            'formDayOfMonth' => ['nullable', 'integer', 'min:1', 'max:31'],
            'formIsActive' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $teamId = (int) auth()->user()?->current_team_id;

        $data = [
            'name' => $this->formName,
            'category_id' => $this->formCategoryId ?: null,
            'direction' => $this->formDirection,
            'amount' => $this->formAmount,
            'frequency' => $this->formFrequency,
            'day_of_month' => $this->formDayOfMonth,
            'is_active' => $this->formIsActive,
        ];

        if ($this->editingId) {
            $budget = BudgetItem::where('team_id', $teamId)->findOrFail($this->editingId);
            $budget->update($data);
        } else {
            $data['team_id'] = $teamId;
            BudgetItem::create($data);
        }

        $this->cancel();
    }

    public function edit(int $id): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $budget = BudgetItem::where('team_id', $teamId)->findOrFail($id);

        $this->editingId = $budget->id;
        $this->formName = $budget->name;
        $this->formCategoryId = $budget->category_id;
        $this->formDirection = $budget->direction;
        $this->formAmount = (string) $budget->amount;
        $this->formFrequency = $budget->frequency;
        $this->formDayOfMonth = $budget->day_of_month;
        $this->formIsActive = $budget->is_active;
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->formName = '';
        $this->formCategoryId = null;
        $this->formDirection = 'debit';
        $this->formAmount = '';
        $this->formFrequency = 'monthly';
        $this->formDayOfMonth = null;
        $this->formIsActive = true;
        $this->resetValidation();
    }

    public function delete(int $id): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        BudgetItem::where('team_id', $teamId)->findOrFail($id)->delete();

        if ($this->editingId === $id) {
            $this->cancel();
        }
    }

    public function render()
    {
        $teamId = (int) auth()->user()?->current_team_id;

        $budgetItems = BudgetItem::where('team_id', $teamId)
            ->with('category')
            ->orderBy('name')
            ->get();

        // Calculate actual amounts for current month
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $budgets = $budgetItems->map(function (BudgetItem $item) use ($teamId, $monthStart, $monthEnd) {
            $monthlyBudget = $item->monthlyAmount();

            $actual = 0;
            if ($item->category_id) {
                $actual = BankTransaction::where('team_id', $teamId)
                    ->where('category_id', $item->category_id)
                    ->where('direction', $item->direction)
                    ->where(function ($q) use ($monthStart, $monthEnd) {
                        $q->where(function ($inner) use ($monthStart, $monthEnd) {
                            $inner->whereNotNull('booked_at')
                                ->whereBetween('booked_at', [$monthStart, $monthEnd]);
                        })->orWhere(function ($or) use ($monthStart, $monthEnd) {
                            $or->whereNull('booked_at')
                                ->whereBetween('created_at', [$monthStart, $monthEnd]);
                        });
                    })
                    ->get(['amount'])
                    ->sum(fn ($t) => abs((float) $t->amount));
            }

            $percent = $monthlyBudget > 0 ? round($actual / $monthlyBudget * 100, 1) : 0;

            return [
                'id' => $item->id,
                'name' => $item->name,
                'direction' => $item->direction,
                'frequency' => $item->frequency,
                'day_of_month' => $item->day_of_month,
                'is_active' => $item->is_active,
                'category_name' => $item->category?->name,
                'category_color' => $item->category?->color ?? '#6B7280',
                'budget' => $monthlyBudget,
                'actual' => $actual,
                'percent' => $percent,
            ];
        })->toArray();

        $categories = BankTransactionCategory::where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return view('drip::livewire.budgets', [
            'budgets' => $budgets,
            'categories' => $categories,
        ])->layout('platform::layouts.app');
    }
}
