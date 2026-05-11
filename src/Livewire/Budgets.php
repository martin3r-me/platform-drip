<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Services\RecurringDetectionService;

class Budgets extends Component
{
    public ?int $editingId = null;
    public string $activeTab = 'active';

    public string $formName = '';
    public ?int $formCategoryId = null;
    public string $formDirection = 'debit';
    public string $formAmount = '';
    public string $formFrequency = 'monthly';
    public ?int $formDayOfMonth = null;
    public bool $formIsActive = true;
    public ?string $formStartDate = null;
    public ?string $formEndDate = null;
    public ?string $formNotes = null;

    public string $historyMonth = '';

    public function mount(): void
    {
        $this->historyMonth = now()->format('Y-m');
    }

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
            'formStartDate' => ['nullable', 'date'],
            'formEndDate' => ['nullable', 'date'],
            'formNotes' => ['nullable', 'string', 'max:1000'],
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
            'start_date' => $this->formStartDate ?: null,
            'end_date' => $this->formEndDate ?: null,
            'notes' => $this->formNotes ?: null,
        ];

        if ($this->editingId) {
            $budget = BudgetItem::where('team_id', $teamId)->findOrFail($this->editingId);
            $budget->update($data);
        } else {
            $data['team_id'] = $teamId;
            $data['status'] = 'active';
            $data['source_type'] = 'manual';
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
        $this->formStartDate = $budget->start_date?->format('Y-m-d');
        $this->formEndDate = $budget->end_date?->format('Y-m-d');
        $this->formNotes = $budget->notes;
        $this->activeTab = 'active';
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
        $this->formStartDate = null;
        $this->formEndDate = null;
        $this->formNotes = null;
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

    public function confirmSuggestion(int $id): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $budget = BudgetItem::where('team_id', $teamId)->suggested()->findOrFail($id);
        $budget->confirm();
        $this->activeTab = 'active';
    }

    public function dismissSuggestion(int $id): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $budget = BudgetItem::where('team_id', $teamId)->where('status', 'suggested')->findOrFail($id);
        $budget->dismiss();
    }

    public function confirmAllSuggestions(): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        BudgetItem::where('team_id', $teamId)->suggested()->each(fn ($b) => $b->confirm());
        $this->activeTab = 'active';
    }

    public function togglePause(int $id): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $budget = BudgetItem::where('team_id', $teamId)->findOrFail($id);

        if ($budget->status === 'paused') {
            $budget->resume();
        } else {
            $budget->pause();
        }
    }

    public function archive(int $id): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $budget = BudgetItem::where('team_id', $teamId)->findOrFail($id);
        $budget->archive();
    }

    public function runDetection(): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $service = app(RecurringDetectionService::class);
        $service->createSuggestions($teamId);
        $this->activeTab = 'suggestions';
    }

    public function previousMonth(): void
    {
        $this->historyMonth = \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->historyMonth)
            ->subMonth()
            ->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->historyMonth = \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->historyMonth)
            ->addMonth()
            ->format('Y-m');
    }

    public function render()
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $monthStart = now()->startOfMonth();

        // Suggestions
        $suggestions = BudgetItem::where('team_id', $teamId)
            ->suggested()
            ->with('category')
            ->orderByDesc('source_avg_amount')
            ->get()
            ->map(fn (BudgetItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'direction' => $item->direction,
                'source_counterparty' => $item->source_counterparty,
                'source_avg_amount' => (float) $item->source_avg_amount,
                'source_month_count' => $item->source_month_count,
                'category_name' => $item->category?->name,
                'category_color' => $item->category?->color ?? '#6B7280',
                'suggested_at' => $item->suggested_at,
            ])
            ->toArray();

        // Active budgets with fulfillment for current month
        $activeBudgets = BudgetItem::where('team_id', $teamId)
            ->whereIn('status', ['active', 'paused'])
            ->with('category')
            ->orderBy('name')
            ->get()
            ->map(function (BudgetItem $item) use ($teamId, $monthStart) {
                $fulfillment = $item->fulfillmentForMonth($monthStart, $teamId);
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'direction' => $item->direction,
                    'frequency' => $item->frequency,
                    'day_of_month' => $item->day_of_month,
                    'is_active' => $item->is_active,
                    'status' => $item->status,
                    'category_name' => $item->category?->name,
                    'category_color' => $item->category?->color ?? '#6B7280',
                    'budget' => $fulfillment['budget'],
                    'actual' => $fulfillment['actual'],
                    'percent' => $fulfillment['percent'],
                ];
            })
            ->toArray();

        // History: fulfillment for selected month
        $historyMonthStart = \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->historyMonth)->startOfMonth();
        $historyBudgets = BudgetItem::where('team_id', $teamId)
            ->whereIn('status', ['active', 'paused', 'archived'])
            ->with('category')
            ->orderBy('name')
            ->get()
            ->map(function (BudgetItem $item) use ($teamId, $historyMonthStart) {
                $fulfillment = $item->fulfillmentForMonth($historyMonthStart, $teamId);
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'direction' => $item->direction,
                    'status' => $item->status,
                    'category_name' => $item->category?->name,
                    'category_color' => $item->category?->color ?? '#6B7280',
                    'budget' => $fulfillment['budget'],
                    'actual' => $fulfillment['actual'],
                    'percent' => $fulfillment['percent'],
                ];
            })
            ->toArray();

        $historyTotalBudget = array_sum(array_column($historyBudgets, 'budget'));
        $historyTotalActual = array_sum(array_column($historyBudgets, 'actual'));

        $categories = BankTransactionCategory::where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return view('drip::livewire.budgets', [
            'suggestions' => $suggestions,
            'budgets' => $activeBudgets,
            'historyBudgets' => $historyBudgets,
            'historyTotalBudget' => $historyTotalBudget,
            'historyTotalActual' => $historyTotalActual,
            'historyMonthLabel' => $historyMonthStart->translatedFormat('F Y'),
            'categories' => $categories,
        ])->layout('platform::layouts.app');
    }
}
