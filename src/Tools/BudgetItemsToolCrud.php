<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Tools\Concerns\ResolvesDripTeam;

class BudgetItemsToolCrud implements ToolContract, ToolMetadataContract
{
    use ResolvesDripTeam;

    public function getName(): string
    {
        return 'drip.budget_items.CRUD';
    }

    public function getDescription(): string
    {
        return 'CRUD /drip/budgets - Verwaltet Budget-Items (Soll/Ist pro Kategorie). action=list (default, inkl. Ist-Werte), action=create (name + amount + direction + frequency required), action=update (budget_id + optionale Felder), action=delete (budget_id).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'create', 'update', 'delete'],
                    'description' => 'Aktion: list, create, update, delete. Default: list.',
                ],
                'budget_id' => [
                    'type' => 'integer',
                    'description' => 'Budget-Item-ID (fuer update/delete).',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Name des Budgets (fuer create/update).',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Kategorie-ID (fuer create/update, optional).',
                ],
                'direction' => [
                    'type' => 'string',
                    'enum' => ['debit', 'credit'],
                    'description' => 'Richtung: debit (Ausgabe) oder credit (Einnahme).',
                ],
                'amount' => [
                    'type' => 'number',
                    'description' => 'Betrag pro Periode.',
                ],
                'frequency' => [
                    'type' => 'string',
                    'enum' => ['weekly', 'monthly', 'quarterly', 'yearly'],
                    'description' => 'Frequenz des Budgets.',
                ],
                'day_of_month' => [
                    'type' => 'integer',
                    'description' => 'Tag im Monat (1-31, optional).',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Aktiv-Status (fuer create/update).',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Notizen (optional).',
                ],
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int) $resolved['team_id'];
            $action = $arguments['action'] ?? 'list';

            return match ($action) {
                'create' => $this->create($arguments, $teamId, $context),
                'update' => $this->update($arguments, $teamId),
                'delete' => $this->delete($arguments, $teamId),
                default => $this->list($teamId),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    protected function list(int $teamId): ToolResult
    {
        $items = BudgetItem::where('team_id', $teamId)
            ->with('category')
            ->orderBy('name')
            ->get();

        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $data = $items->map(function (BudgetItem $item) use ($teamId, $monthStart, $monthEnd) {
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
                'amount' => (float) $item->amount,
                'frequency' => $item->frequency,
                'monthly_budget' => $monthlyBudget,
                'actual_this_month' => $actual,
                'percent' => $percent,
                'day_of_month' => $item->day_of_month,
                'is_active' => $item->is_active,
                'category' => $item->category ? [
                    'id' => $item->category->id,
                    'name' => $item->category->name,
                    'color' => $item->category->color,
                ] : null,
                'notes' => $item->notes,
            ];
        })->toArray();

        return ToolResult::success([
            'data' => $data,
            'total' => count($data),
            'team_id' => $teamId,
        ]);
    }

    protected function create(array $arguments, int $teamId, ToolContext $context): ToolResult
    {
        $required = ['name', 'amount', 'direction', 'frequency'];
        foreach ($required as $field) {
            if (empty($arguments[$field])) {
                return ToolResult::error('VALIDATION_ERROR', "{$field} ist erforderlich.");
            }
        }

        if (!in_array($arguments['direction'], ['debit', 'credit'])) {
            return ToolResult::error('VALIDATION_ERROR', 'direction muss "debit" oder "credit" sein.');
        }

        if (!in_array($arguments['frequency'], ['weekly', 'monthly', 'quarterly', 'yearly'])) {
            return ToolResult::error('VALIDATION_ERROR', 'frequency muss weekly, monthly, quarterly oder yearly sein.');
        }

        $item = BudgetItem::create([
            'name' => $arguments['name'],
            'amount' => $arguments['amount'],
            'direction' => $arguments['direction'],
            'frequency' => $arguments['frequency'],
            'category_id' => $arguments['category_id'] ?? null,
            'day_of_month' => $arguments['day_of_month'] ?? null,
            'is_active' => $arguments['is_active'] ?? true,
            'notes' => $arguments['notes'] ?? null,
            'team_id' => $teamId,
            'user_id' => $context->user?->id,
        ]);

        return ToolResult::success([
            'message' => "Budget '{$arguments['name']}' erstellt.",
            'budget' => [
                'id' => $item->id,
                'name' => $item->name,
                'amount' => (float) $item->amount,
                'direction' => $item->direction,
                'frequency' => $item->frequency,
                'monthly_budget' => $item->monthlyAmount(),
            ],
        ]);
    }

    protected function update(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['budget_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'budget_id ist erforderlich.');
        }

        $item = BudgetItem::where('team_id', $teamId)->findOrFail($id);

        $data = array_filter([
            'name' => $arguments['name'] ?? null,
            'amount' => $arguments['amount'] ?? null,
            'direction' => $arguments['direction'] ?? null,
            'frequency' => $arguments['frequency'] ?? null,
            'day_of_month' => array_key_exists('day_of_month', $arguments) ? $arguments['day_of_month'] : null,
            'notes' => array_key_exists('notes', $arguments) ? $arguments['notes'] : null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('category_id', $arguments)) {
            $data['category_id'] = $arguments['category_id'];
        }
        if (array_key_exists('is_active', $arguments)) {
            $data['is_active'] = $arguments['is_active'];
        }

        $item->update($data);

        return ToolResult::success([
            'message' => "Budget '{$item->name}' aktualisiert.",
            'budget' => [
                'id' => $item->id,
                'name' => $item->name,
                'amount' => (float) $item->amount,
                'direction' => $item->direction,
                'frequency' => $item->frequency,
                'monthly_budget' => $item->monthlyAmount(),
            ],
        ]);
    }

    protected function delete(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['budget_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'budget_id ist erforderlich.');
        }

        $item = BudgetItem::where('team_id', $teamId)->findOrFail($id);
        $name = $item->name;
        $item->delete();

        return ToolResult::success(['message' => "Budget '{$name}' geloescht."]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'crud',
            'tags' => ['drip', 'budgets'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'low',
            'idempotent' => false,
        ];
    }
}
