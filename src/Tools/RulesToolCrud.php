<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\RecurringPattern;
use Platform\Drip\Services\CategorizationService;
use Platform\Drip\Tools\Concerns\ResolvesDripTeam;

class RulesToolCrud implements ToolContract, ToolMetadataContract
{
    use ResolvesDripTeam;

    public function getName(): string
    {
        return 'drip.rules.CRUD';
    }

    public function getDescription(): string
    {
        return 'CRUD /drip/rules - Verwaltet Auto-Kategorisierungsregeln. action=list (default), action=create (name + category_id + matchers required), action=update (rule_id + Felder), action=delete (rule_id), action=test (rule_id — zeigt Match-Count), action=apply (rule_id — wendet Regel auf unkategorisierte TXs an), action=apply_all (alle aktiven Regeln nach Priorität). Optional bei create/update: priority (höher gewinnt), is_active. Matcher-Format: [{"field":"counterparty_name","op":"contains","value":"DKV"}]. Felder: counterparty_name, reference, creditor_name, amount, counterparty_iban, remittance_information. Ops: contains, starts_with, equals, gte, lte.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'create', 'update', 'delete', 'test', 'apply', 'apply_all'],
                    'description' => 'Aktion: list, create, update, delete, test, apply, apply_all (alle aktiven Regeln nach Priorität auf unkategorisierte TXs). Default: list.',
                ],
                'rule_id' => [
                    'type' => 'integer',
                    'description' => 'Regel-ID (für update/delete/test/apply).',
                ],
                'priority' => [
                    'type' => 'integer',
                    'description' => 'Priorität der Regel (höher gewinnt bei mehreren Treffern, Default 0). Für create/update.',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Ob die Regel aktiv ausgewertet wird (Default true). Für create/update.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Name der Regel (für create/update).',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Ziel-Kategorie-ID (für create/update).',
                ],
                'matchers' => [
                    'type' => 'array',
                    'description' => 'Array von Matcher-Objekten: [{field, op, value}]. Für create/update.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string', 'enum' => ['counterparty_name', 'reference', 'creditor_name', 'amount', 'counterparty_iban', 'remittance_information']],
                            'op' => ['type' => 'string', 'enum' => ['contains', 'starts_with', 'equals', 'gte', 'lte']],
                            'value' => ['type' => 'string'],
                        ],
                    ],
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
                'test' => $this->test($arguments, $teamId),
                'apply' => $this->apply($arguments, $teamId),
                'apply_all' => $this->applyAll($teamId),
                default => $this->list($teamId),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    protected function list(int $teamId): ToolResult
    {
        $rules = RecurringPattern::where('team_id', $teamId)
            ->whereNotNull('matchers')
            ->with('category')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        $data = $rules->map(function ($rule) {
            $cat = $rule->category;
            return [
                'id' => $rule->id,
                'name' => $rule->name,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
                'matchers' => $rule->matchers,
                'defaults' => $rule->defaults,
                'category' => $cat ? [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'color' => $cat->color,
                ] : null,
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
        $name = $arguments['name'] ?? null;
        $categoryId = $arguments['category_id'] ?? null;
        $matchers = $arguments['matchers'] ?? null;

        if (!$name) {
            return ToolResult::error('VALIDATION_ERROR', 'name ist erforderlich.');
        }
        if (!$categoryId) {
            return ToolResult::error('VALIDATION_ERROR', 'category_id ist erforderlich.');
        }
        if (!$matchers || !is_array($matchers) || empty($matchers)) {
            return ToolResult::error('VALIDATION_ERROR', 'matchers ist erforderlich (mind. 1 Matcher).');
        }

        // Verify category exists
        BankTransactionCategory::where('team_id', $teamId)->findOrFail($categoryId);

        $rule = RecurringPattern::create([
            'name' => $name,
            'team_id' => $teamId,
            'user_id' => $context->user?->id,
            'matchers' => $matchers,
            'defaults' => ['category_id' => $categoryId],
            'bank_transaction_category_id' => $categoryId,
            'priority' => (int) ($arguments['priority'] ?? 0),
            'is_active' => array_key_exists('is_active', $arguments) ? (bool) $arguments['is_active'] : true,
        ]);

        return ToolResult::success([
            'message' => "Regel '{$name}' erstellt.",
            'rule' => [
                'id' => $rule->id,
                'name' => $rule->name,
                'matchers' => $rule->matchers,
                'defaults' => $rule->defaults,
            ],
        ]);
    }

    protected function update(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['rule_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'rule_id ist erforderlich.');
        }

        $rule = RecurringPattern::where('team_id', $teamId)->findOrFail($id);

        if (isset($arguments['name'])) {
            $rule->name = $arguments['name'];
        }
        if (isset($arguments['matchers'])) {
            $rule->matchers = $arguments['matchers'];
        }
        if (isset($arguments['category_id'])) {
            BankTransactionCategory::where('team_id', $teamId)->findOrFail($arguments['category_id']);
            $rule->defaults = ['category_id' => $arguments['category_id']];
            $rule->bank_transaction_category_id = $arguments['category_id'];
        }
        if (array_key_exists('priority', $arguments)) {
            $rule->priority = (int) $arguments['priority'];
        }
        if (array_key_exists('is_active', $arguments)) {
            $rule->is_active = (bool) $arguments['is_active'];
        }

        $rule->save();

        return ToolResult::success([
            'message' => "Regel '{$rule->name}' aktualisiert.",
            'rule' => [
                'id' => $rule->id,
                'name' => $rule->name,
                'matchers' => $rule->matchers,
                'defaults' => $rule->defaults,
            ],
        ]);
    }

    protected function delete(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['rule_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'rule_id ist erforderlich.');
        }

        $rule = RecurringPattern::where('team_id', $teamId)->findOrFail($id);
        $name = $rule->name;
        $rule->delete();

        return ToolResult::success(['message' => "Regel '{$name}' gelöscht."]);
    }

    protected function test(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['rule_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'rule_id ist erforderlich.');
        }

        $rule = RecurringPattern::where('team_id', $teamId)->findOrFail($id);
        $service = app(CategorizationService::class);
        $count = $service->countMatches($rule, uncategorizedOnly: true);

        return ToolResult::success([
            'message' => "Regel '{$rule->name}' matcht {$count} unkategorisierte Transaktion(en).",
            'match_count' => $count,
            'rule_id' => $rule->id,
        ]);
    }

    protected function apply(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['rule_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'rule_id ist erforderlich.');
        }

        $rule = RecurringPattern::where('team_id', $teamId)->findOrFail($id);
        $service = app(CategorizationService::class);
        $count = $service->applyRule($rule);

        return ToolResult::success([
            'message' => "Regel '{$rule->name}' auf {$count} Transaktion(en) angewendet.",
            'applied_count' => $count,
            'rule_id' => $rule->id,
        ]);
    }

    protected function applyAll(int $teamId): ToolResult
    {
        $count = app(CategorizationService::class)->categorizeUncategorized($teamId);

        return ToolResult::success([
            'message' => "{$count} unkategorisierte Transaktion(en) über alle aktiven Regeln zugeordnet.",
            'applied_count' => $count,
            'team_id' => $teamId,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'crud',
            'tags' => ['drip', 'rules', 'categorization'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'low',
            'idempotent' => false,
        ];
    }
}
