<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Services\RecurringDetectionService;
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
        return 'CRUD /drip/budgets - Verwaltet Budget-Items (Soll/Ist pro Kategorie). action=list (default, inkl. Ist-Werte, optional month=YYYY-MM, status=active|suggested|paused|archived), action=create, action=update, action=delete, action=suggestions (offene Vorschlaege), action=confirm (budget_id), action=dismiss (budget_id), action=detect (erkennt wiederkehrende Muster).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'create', 'update', 'delete', 'suggestions', 'confirm', 'dismiss', 'detect'],
                    'description' => 'Aktion: list, create, update, delete, suggestions, confirm, dismiss, detect. Default: list.',
                ],
                'budget_id' => [
                    'type' => 'integer',
                    'description' => 'Budget-Item-ID (fuer update/delete/confirm/dismiss).',
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
                'month' => [
                    'type' => 'string',
                    'description' => 'Monat fuer historische Fulfillment-Daten (YYYY-MM, fuer list).',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'suggested', 'paused', 'archived'],
                    'description' => 'Status-Filter (fuer list).',
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
                'suggestions' => $this->suggestions($teamId),
                'confirm' => $this->confirm($arguments, $teamId),
                'dismiss' => $this->dismiss($arguments, $teamId),
                'detect' => $this->detect($teamId),
                default => $this->list($arguments, $teamId),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    protected function list(array $arguments, int $teamId): ToolResult
    {
        $query = BudgetItem::where('team_id', $teamId)->with('category');

        if (isset($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        $items = $query->orderBy('name')->get();

        // Determine which month to use for fulfillment
        if (isset($arguments['month'])) {
            $monthStart = \Illuminate\Support\Carbon::createFromFormat('Y-m', $arguments['month'])->startOfMonth();
        } else {
            $monthStart = now()->startOfMonth();
        }

        $data = $items->map(function (BudgetItem $item) use ($teamId, $monthStart) {
            $fulfillment = $item->fulfillmentForMonth($monthStart, $teamId);

            return [
                'id' => $item->id,
                'name' => $item->name,
                'direction' => $item->direction,
                'amount' => (float) $item->amount,
                'frequency' => $item->frequency,
                'monthly_budget' => $fulfillment['budget'],
                'actual_this_month' => $fulfillment['actual'],
                'percent' => $fulfillment['percent'],
                'day_of_month' => $item->day_of_month,
                'is_active' => $item->is_active,
                'status' => $item->status,
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
            'month' => $monthStart->format('Y-m'),
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
            'status' => 'active',
            'source_type' => 'manual',
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

    protected function suggestions(int $teamId): ToolResult
    {
        $items = BudgetItem::where('team_id', $teamId)
            ->suggested()
            ->with('category')
            ->orderByDesc('source_avg_amount')
            ->get();

        $data = $items->map(fn (BudgetItem $item) => [
            'id' => $item->id,
            'name' => $item->name,
            'direction' => $item->direction,
            'source_counterparty' => $item->source_counterparty,
            'source_iban' => $item->source_iban,
            'source_avg_amount' => (float) $item->source_avg_amount,
            'source_month_count' => $item->source_month_count,
            'amount' => (float) $item->amount,
            'frequency' => $item->frequency,
            'category' => $item->category ? [
                'id' => $item->category->id,
                'name' => $item->category->name,
            ] : null,
            'suggested_at' => $item->suggested_at?->toIso8601String(),
        ])->toArray();

        return ToolResult::success([
            'data' => $data,
            'total' => count($data),
            'team_id' => $teamId,
        ]);
    }

    protected function confirm(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['budget_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'budget_id ist erforderlich.');
        }

        $item = BudgetItem::where('team_id', $teamId)->where('status', 'suggested')->findOrFail($id);
        $item->confirm();

        return ToolResult::success([
            'message' => "Vorschlag '{$item->name}' bestaetigt und aktiviert.",
            'budget' => [
                'id' => $item->id,
                'name' => $item->name,
                'status' => $item->status,
            ],
        ]);
    }

    protected function dismiss(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['budget_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'budget_id ist erforderlich.');
        }

        $item = BudgetItem::where('team_id', $teamId)->where('status', 'suggested')->findOrFail($id);
        $item->dismiss();

        return ToolResult::success([
            'message' => "Vorschlag '{$item->name}' abgelehnt.",
        ]);
    }

    protected function detect(int $teamId): ToolResult
    {
        $service = app(RecurringDetectionService::class);
        $created = $service->createSuggestions($teamId);

        return ToolResult::success([
            'message' => "{$created} neue Vorschlaege erstellt.",
            'suggestions_created' => $created,
            'team_id' => $teamId,
        ]);
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
