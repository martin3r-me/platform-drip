<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Models\BudgetItemPeriod;
use Platform\Drip\Services\BudgetPeriodService;
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
    public ?string $formPlannedDate = null;
    public ?int $formBankAccountId = null;

    public string $historyMonth = '';

    public ?int $showPeriodsFor = null;
    public ?int $editingPeriodId = null;
    public string $editingPeriodAmount = '';

    public function mount(): void
    {
        $this->historyMonth = now()->format('Y-m');

        // Prefill from query parameters (e.g. from TransactionDetail)
        if (request()->query('prefill')) {
            $this->formName = request()->query('name', '');
            $this->formAmount = request()->query('amount', '');
            $this->formDirection = request()->query('direction', 'debit');
            $this->formCategoryId = request()->query('category_id') ? (int) request()->query('category_id') : null;
            $this->formDayOfMonth = request()->query('day_of_month') ? (int) request()->query('day_of_month') : null;
            $this->formBankAccountId = request()->query('bank_account_id') ? (int) request()->query('bank_account_id') : null;
        }
    }

    protected function rules(): array
    {
        return [
            'formName' => ['required', 'string', 'max:255'],
            'formCategoryId' => ['nullable', 'integer', 'exists:drip_bank_transaction_categories,id'],
            'formDirection' => ['required', 'string', 'in:debit,credit'],
            'formAmount' => ['required', 'numeric', 'min:0.01'],
            'formFrequency' => ['required', 'string', 'in:monthly,quarterly,yearly,weekly,once'],
            'formDayOfMonth' => ['nullable', 'integer', 'min:1', 'max:31'],
            'formIsActive' => ['boolean'],
            'formStartDate' => ['nullable', 'date'],
            'formEndDate' => ['nullable', 'date'],
            'formNotes' => ['nullable', 'string', 'max:1000'],
            'formPlannedDate' => ['nullable', 'date', 'required_if:formFrequency,once'],
            'formBankAccountId' => ['nullable', 'integer', 'exists:drip_bank_accounts,id'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $teamId = (int) auth()->user()?->current_team_id;

        $data = [
            'name' => $this->formName,
            'category_id' => $this->formCategoryId ?: null,
            'bank_account_id' => $this->formBankAccountId ?: null,
            'direction' => $this->formDirection,
            'amount' => $this->formAmount,
            'frequency' => $this->formFrequency,
            'day_of_month' => $this->formDayOfMonth,
            'is_active' => $this->formIsActive,
            'start_date' => $this->formStartDate ?: null,
            'end_date' => $this->formEndDate ?: null,
            'planned_date' => $this->formPlannedDate ?: null,
            'notes' => $this->formNotes ?: null,
        ];

        $service = app(BudgetPeriodService::class);

        if ($this->editingId) {
            $budget = BudgetItem::where('team_id', $teamId)->findOrFail($this->editingId);
            $budget->update($data);
            $service->generatePeriodsForItem($budget);
        } else {
            $data['team_id'] = $teamId;
            $data['status'] = 'active';
            $data['source_type'] = 'manual';
            $budget = BudgetItem::create($data);
            $service->generatePeriodsForItem($budget);
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
        $this->formBankAccountId = $budget->bank_account_id;
        $this->formDirection = $budget->direction;
        $this->formAmount = (string) $budget->amount;
        $this->formFrequency = $budget->frequency;
        $this->formDayOfMonth = $budget->day_of_month;
        $this->formIsActive = $budget->is_active;
        $this->formStartDate = $budget->start_date?->format('Y-m-d');
        $this->formEndDate = $budget->end_date?->format('Y-m-d');
        $this->formPlannedDate = $budget->planned_date?->format('Y-m-d');
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
        $this->formPlannedDate = null;
        $this->formBankAccountId = null;
        $this->formNotes = null;
        $this->showPeriodsFor = null;
        $this->editingPeriodId = null;
        $this->editingPeriodAmount = '';
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
        app(BudgetPeriodService::class)->generatePeriodsForItem($budget);
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

    public function togglePeriods(int $budgetId): void
    {
        $this->showPeriodsFor = $this->showPeriodsFor === $budgetId ? null : $budgetId;
        $this->editingPeriodId = null;
        $this->editingPeriodAmount = '';
    }

    public function startEditPeriod(int $periodId): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $period = BudgetItemPeriod::where('team_id', $teamId)->findOrFail($periodId);
        $this->editingPeriodId = $periodId;
        $this->editingPeriodAmount = (string) $period->planned_amount;
    }

    public function adjustPeriod(): void
    {
        $this->validate([
            'editingPeriodAmount' => ['required', 'numeric', 'min:0'],
        ]);

        $teamId = (int) auth()->user()?->current_team_id;
        $period = BudgetItemPeriod::where('team_id', $teamId)->findOrFail($this->editingPeriodId);
        $period->update(['planned_amount' => $this->editingPeriodAmount]);
        $this->editingPeriodId = null;
        $this->editingPeriodAmount = '';
    }

    public function skipPeriod(int $periodId): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $period = BudgetItemPeriod::where('team_id', $teamId)->findOrFail($periodId);
        $period->skip();
    }

    public function deletePeriod(int $periodId): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $period = BudgetItemPeriod::where('team_id', $teamId)->findOrFail($periodId);
        $period->delete();
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
            ->with(['category', 'bankAccount'])
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
                    'bank_account_name' => $item->bankAccount?->name,
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

        // Periods for expanded budget
        $periods = [];
        if ($this->showPeriodsFor) {
            $periods = BudgetItemPeriod::where('team_id', $teamId)
                ->where('budget_item_id', $this->showPeriodsFor)
                ->orderBy('period_start')
                ->get()
                ->map(fn (BudgetItemPeriod $p) => [
                    'id' => $p->id,
                    'period_start' => $p->period_start->format('Y-m-d'),
                    'period_end' => $p->period_end->format('Y-m-d'),
                    'period_label' => $p->period_start->translatedFormat('M Y'),
                    'expected_date' => $p->expected_date?->format('d.m.Y'),
                    'planned_amount' => (float) $p->planned_amount,
                    'actual_amount' => (float) $p->actual_amount,
                    'percent' => (float) $p->percent,
                    'status' => $p->status,
                ])
                ->toArray();
        }

        $categories = BankTransactionCategory::where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        $bankAccounts = BankAccount::where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('drip::livewire.budgets', [
            'suggestions' => $suggestions,
            'budgets' => $activeBudgets,
            'historyBudgets' => $historyBudgets,
            'historyTotalBudget' => $historyTotalBudget,
            'historyTotalActual' => $historyTotalActual,
            'historyMonthLabel' => $historyMonthStart->translatedFormat('F Y'),
            'periods' => $periods,
            'categories' => $categories,
            'bankAccounts' => $bankAccounts,
        ])->layout('platform::layouts.app');
    }
}
