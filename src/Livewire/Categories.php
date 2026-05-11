<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankTransactionCategory;

class Categories extends Component
{
    public ?int $editingId = null;

    public array $form = [
        'name' => '',
        'color' => null,
        'parent_id' => null,
    ];

    public function save(): void
    {
        $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.color' => ['nullable', 'string', 'max:20'],
            'form.parent_id' => ['nullable', 'integer', 'exists:drip_bank_transaction_categories,id'],
        ]);

        $data = [
            'name' => $this->form['name'],
            'color' => $this->form['color'],
            'parent_id' => $this->form['parent_id'] ?: null,
        ];

        if ($this->editingId) {
            $category = BankTransactionCategory::findOrFail($this->editingId);
            $category->update($data);
        } else {
            BankTransactionCategory::create($data);
        }

        $this->cancel();
    }

    public function edit(int $id): void
    {
        $category = BankTransactionCategory::findOrFail($id);

        $this->editingId = $category->id;
        $this->form = [
            'name' => $category->name,
            'color' => $category->color,
            'parent_id' => $category->parent_id,
        ];
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'color' => null, 'parent_id' => null];
        $this->resetValidation();
    }

    public function delete(int $id): void
    {
        $category = BankTransactionCategory::findOrFail($id);

        // Move children to root level
        BankTransactionCategory::where('parent_id', $category->id)
            ->update(['parent_id' => null]);

        $category->delete();

        if ($this->editingId === $id) {
            $this->cancel();
        }
    }

    public function render()
    {
        $user = auth()->user();
        $teamId = (int) $user?->current_team_id;

        $categories = BankTransactionCategory::where('team_id', $teamId)
            ->whereNull('parent_id')
            ->withCount('transactions')
            ->with(['children' => fn ($q) => $q->withCount('transactions')])
            ->orderBy('name')
            ->get();

        $rootCategories = BankTransactionCategory::where('team_id', $teamId)
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get();

        return view('drip::livewire.categories', [
            'categories' => $categories,
            'rootCategories' => $rootCategories,
        ])->layout('platform::layouts.app');
    }
}
