<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Models\BudgetItemPeriod;
use Platform\Drip\Services\BudgetPeriodService;
use Platform\Drip\Services\LiquidityPlanningService;
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
        return 'CRUD /drip/budgets - Verwaltet Budget-Items (Soll/Ist pro Kategorie). action=list (default, inkl. Ist-Werte, optional month=YYYY-MM, status=active|suggested|paused|archived), action=create, action=update, action=delete, action=suggestions (offene Vorschlaege), action=confirm (budget_id), action=dismiss (budget_id), action=detect (erkennt wiederkehrende Muster), action=periods (budget_id, optional status/date_from/date_to), action=skip_period (period_id), action=adjust_period (period_id + planned_amount), action=liquidity (months_ahead).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'create', 'update', 'delete', 'suggestions', 'confirm', 'dismiss', 'detect', 'periods', 'skip_period', 'adjust_period', 'liquidity'],
                    'description' => 'Aktion: list, create, update, delete, suggestions, confirm, dismiss, detect, periods, skip_period, adjust_period, liquidity. Default: list.',
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
                'bank_account_id' => [
                    'type' => 'integer',
                    'description' => 'Bankkonto-ID (fuer create/update, optional). Scoped Fulfillment auf dieses Konto.',
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
                    'enum' => ['weekly', 'monthly', 'quarterly', 'yearly', 'once'],
                    'description' => 'Frequenz des Budgets.',
                ],
                'planned_date' => [
                    'type' => 'string',
                    'description' => 'Geplantes Datum fuer einmalige Budgets (YYYY-MM-DD).',
                ],
                'period_id' => [
                    'type' => 'integer',
                    'description' => 'Perioden-ID (fuer skip_period/adjust_period).',
                ],
                'planned_amount' => [
                    'type' => 'number',
                    'description' => 'Geplanter Betrag fuer adjust_period.',
                ],
                'months_ahead' => [
                    'type' => 'integer',
                    'description' => 'Monate voraus fuer Liquiditaetsplanung (default 6).',
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Start-Datum Filter fuer periods (YYYY-MM-DD).',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'End-Datum Filter fuer periods (YYYY-MM-DD).',
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
                'periods' => $this->periods($arguments, $teamId),
                'skip_period' => $this->skipPeriod($arguments, $teamId),
                'adjust_period' => $this->adjustPeriod($arguments, $teamId),
                'liquidity' => $this->liquidity($arguments, $teamId),
                default => $this->list($arguments, $teamId),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    protected function list(array $arguments, int $teamId): ToolResult
    {
        $query = BudgetItem::where('team_id', $teamId)->with(['category', 'bankAccount']);

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
                'bank_account' => $item->bankAccount ? [
                    'id' => $item->bankAccount->id,
                    'name' => $item->bankAccount->name,
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

        if (!in_array($arguments['frequency'], ['weekly', 'monthly', 'quarterly', 'yearly', 'once'])) {
            return ToolResult::error('VALIDATION_ERROR', 'frequency muss weekly, monthly, quarterly, yearly oder once sein.');
        }

        if ($arguments['frequency'] === 'once' && empty($arguments['planned_date'])) {
            return ToolResult::error('VALIDATION_ERROR', 'planned_date ist erforderlich fuer einmalige Budgets.');
        }

        $item = BudgetItem::create([
            'name' => $arguments['name'],
            'amount' => $arguments['amount'],
            'direction' => $arguments['direction'],
            'frequency' => $arguments['frequency'],
            'category_id' => $arguments['category_id'] ?? null,
            'bank_account_id' => $arguments['bank_account_id'] ?? null,
            'day_of_month' => $arguments['day_of_month'] ?? null,
            'planned_date' => $arguments['planned_date'] ?? null,
            'is_active' => $arguments['is_active'] ?? true,
            'notes' => $arguments['notes'] ?? null,
            'team_id' => $teamId,
            'user_id' => $context->user?->id,
            'status' => 'active',
            'source_type' => 'manual',
        ]);

        $periodsCreated = app(BudgetPeriodService::class)->generatePeriodsForItem($item);

        return ToolResult::success([
            'message' => "Budget '{$arguments['name']}' erstellt. {$periodsCreated} Perioden generiert.",
            'budget' => [
                'id' => $item->id,
                'name' => $item->name,
                'amount' => (float) $item->amount,
                'direction' => $item->direction,
                'frequency' => $item->frequency,
                'monthly_budget' => $item->monthlyAmount(),
                'periods_created' => $periodsCreated,
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
        if (array_key_exists('bank_account_id', $arguments)) {
            $data['bank_account_id'] = $arguments['bank_account_id'];
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

    protected function periods(array $arguments, int $teamId): ToolResult
    {
        $budgetId = $arguments['budget_id'] ?? null;
        if (!$budgetId) {
            return ToolResult::error('VALIDATION_ERROR', 'budget_id ist erforderlich.');
        }

        $query = BudgetItemPeriod::where('team_id', $teamId)
            ->where('budget_item_id', $budgetId);

        if (isset($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }
        if (isset($arguments['date_from'])) {
            $query->where('period_start', '>=', $arguments['date_from']);
        }
        if (isset($arguments['date_to'])) {
            $query->where('period_end', '<=', $arguments['date_to']);
        }

        $periods = $query->orderBy('period_start')->get();

        $data = $periods->map(fn (BudgetItemPeriod $p) => [
            'id' => $p->id,
            'period_start' => $p->period_start->format('Y-m-d'),
            'period_end' => $p->period_end->format('Y-m-d'),
            'expected_date' => $p->expected_date?->format('Y-m-d'),
            'planned_amount' => (float) $p->planned_amount,
            'actual_amount' => (float) $p->actual_amount,
            'percent' => (float) $p->percent,
            'status' => $p->status,
            'notes' => $p->notes,
        ])->toArray();

        return ToolResult::success([
            'data' => $data,
            'total' => count($data),
            'budget_id' => $budgetId,
            'team_id' => $teamId,
        ]);
    }

    protected function skipPeriod(array $arguments, int $teamId): ToolResult
    {
        $periodId = $arguments['period_id'] ?? null;
        if (!$periodId) {
            return ToolResult::error('VALIDATION_ERROR', 'period_id ist erforderlich.');
        }

        $period = BudgetItemPeriod::where('team_id', $teamId)->findOrFail($periodId);
        $period->skip();

        return ToolResult::success([
            'message' => "Periode {$period->period_start->format('Y-m-d')} uebersprungen.",
            'period' => [
                'id' => $period->id,
                'status' => 'skipped',
            ],
        ]);
    }

    protected function adjustPeriod(array $arguments, int $teamId): ToolResult
    {
        $periodId = $arguments['period_id'] ?? null;
        if (!$periodId) {
            return ToolResult::error('VALIDATION_ERROR', 'period_id ist erforderlich.');
        }

        if (!isset($arguments['planned_amount'])) {
            return ToolResult::error('VALIDATION_ERROR', 'planned_amount ist erforderlich.');
        }

        $period = BudgetItemPeriod::where('team_id', $teamId)->findOrFail($periodId);
        $period->update(['planned_amount' => $arguments['planned_amount']]);

        return ToolResult::success([
            'message' => "Periode {$period->period_start->format('Y-m-d')} angepasst auf {$arguments['planned_amount']}.",
            'period' => [
                'id' => $period->id,
                'planned_amount' => (float) $period->planned_amount,
            ],
        ]);
    }

    protected function liquidity(array $arguments, int $teamId): ToolResult
    {
        $monthsAhead = $arguments['months_ahead'] ?? 6;
        $service = app(LiquidityPlanningService::class);
        $plan = $service->buildPlan($teamId, (int) $monthsAhead);

        return ToolResult::success([
            'data' => $plan,
            'months_ahead' => $monthsAhead,
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
