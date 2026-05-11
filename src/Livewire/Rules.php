<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\RecurringPattern;
use Platform\Drip\Services\CategorizationService;

class Rules extends Component
{
    public ?int $editingId = null;

    public string $formName = '';
    public ?int $formCategoryId = null;
    public array $formMatchers = [];

    public ?string $testResult = null;

    protected function rules(): array
    {
        return [
            'formName' => ['required', 'string', 'max:255'],
            'formCategoryId' => ['required', 'integer', 'exists:drip_bank_transaction_categories,id'],
            'formMatchers' => ['required', 'array', 'min:1'],
            'formMatchers.*.field' => ['required', 'string', 'in:counterparty_name,reference,creditor_name,amount,counterparty_iban,remittance_information'],
            'formMatchers.*.op' => ['required', 'string', 'in:contains,starts_with,equals,gte,lte'],
            'formMatchers.*.value' => ['required', 'string', 'max:500'],
        ];
    }

    public function addMatcher(): void
    {
        $this->formMatchers[] = ['field' => 'counterparty_name', 'op' => 'contains', 'value' => ''];
    }

    public function removeMatcher(int $index): void
    {
        unset($this->formMatchers[$index]);
        $this->formMatchers = array_values($this->formMatchers);
    }

    public function save(): void
    {
        $this->validate();

        $teamId = (int) auth()->user()?->current_team_id;

        $data = [
            'name' => $this->formName,
            'matchers' => $this->formMatchers,
            'defaults' => ['category_id' => $this->formCategoryId],
            'bank_transaction_category_id' => $this->formCategoryId,
        ];

        if ($this->editingId) {
            $rule = RecurringPattern::where('team_id', $teamId)->findOrFail($this->editingId);
            $rule->update($data);
        } else {
            $data['team_id'] = $teamId;
            RecurringPattern::create($data);
        }

        $this->cancel();
    }

    public function edit(int $id): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $rule = RecurringPattern::where('team_id', $teamId)->findOrFail($id);

        $this->editingId = $rule->id;
        $this->formName = $rule->name ?? '';
        $this->formCategoryId = $rule->defaults['category_id'] ?? $rule->bank_transaction_category_id ?? null;
        $this->formMatchers = is_array($rule->matchers) ? $rule->matchers : [];

        $this->testResult = null;
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->formName = '';
        $this->formCategoryId = null;
        $this->formMatchers = [];
        $this->testResult = null;
        $this->resetValidation();
    }

    public function delete(int $id): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        RecurringPattern::where('team_id', $teamId)->findOrFail($id)->delete();

        if ($this->editingId === $id) {
            $this->cancel();
        }
    }

    public function testRule(int $id): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $rule = RecurringPattern::where('team_id', $teamId)->findOrFail($id);

        $service = app(CategorizationService::class);
        $count = $service->countMatches($rule, uncategorizedOnly: true);

        $this->testResult = "Regel \"{$rule->name}\" matcht {$count} unkategorisierte Transaktion(en).";
    }

    public function applyRule(int $id): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $rule = RecurringPattern::where('team_id', $teamId)->findOrFail($id);

        $service = app(CategorizationService::class);
        $count = $service->applyRule($rule);

        $this->testResult = "Regel \"{$rule->name}\" auf {$count} Transaktion(en) angewendet.";
    }

    public function render()
    {
        $teamId = (int) auth()->user()?->current_team_id;

        $rules = RecurringPattern::where('team_id', $teamId)
            ->whereNotNull('matchers')
            ->with('category')
            ->orderBy('name')
            ->get();

        $categories = BankTransactionCategory::where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return view('drip::livewire.rules', [
            'rules' => $rules,
            'categories' => $categories,
        ])->layout('platform::layouts.app');
    }
}
