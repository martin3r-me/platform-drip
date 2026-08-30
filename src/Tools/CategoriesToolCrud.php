<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Services\CategoryException;
use Platform\Drip\Services\CategoryService;
use Platform\Drip\Tools\Concerns\ResolvesDripTeam;

class CategoriesToolCrud implements ToolContract, ToolMetadataContract
{
    use ResolvesDripTeam;

    public function getName(): string
    {
        return 'drip.categories.CRUD';
    }

    public function getDescription(): string
    {
        return 'CRUD /drip/categories - Verwaltet Transaktionskategorien. action=list (default), action=create (name required, color/parent_id optional), action=update (category_id + Felder), action=delete (category_id), action=assign (category_id + counterparty_pattern für Bulk-Zuweisung per Teilstring-Match auf counterparty_name, oder transaction_ids für explizite IDs — ÜBERSCHREIBT auch bereits gesetzte Kategorien, im Gegensatz zu drip.rules.CRUD apply/apply_all, die nur unkategorisierte Transaktionen zuweisen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'create', 'update', 'delete', 'assign'],
                    'description' => 'Aktion: list, create, update, delete, assign. Default: list.',
                ],
                'counterparty_pattern' => [
                    'type' => 'string',
                    'description' => 'Teilstring für counterparty_name (für assign, case-insensitive, z.B. "DKV"). Matched alle TXs, deren Gegenpartei diesen Text enthält.',
                ],
                'transaction_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Explizite TX-IDs für assign. Überschreibt auch eine bereits gesetzte category_id (kein whereNull-Filter, anders als bei rules.CRUD apply).',
                ],
                'reference_pattern' => [
                    'type' => 'string',
                    'description' => 'Teilstring für reference (für assign, case-insensitive, z.B. "Ausleihung").',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Kategorie-ID (für update/delete).',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Name der Kategorie (für create/update).',
                ],
                'color' => [
                    'type' => 'string',
                    'description' => 'Farbe als Hex (z.B. #3B82F6) (für create/update).',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Eltern-Kategorie (für create/update, null = Root).',
                ],
                'direction' => [
                    'type' => 'string',
                    'enum' => ['credit', 'debit', 'both'],
                    'description' => 'Richtung: credit (Einnahmen), debit (Ausgaben), both (beides). Default: debit.',
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
                'assign' => $this->assign($arguments, $teamId),
                default => $this->list($teamId),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    protected function list(int $teamId): ToolResult
    {
        $categories = BankTransactionCategory::where('team_id', $teamId)
            ->whereNull('parent_id')
            ->withCount('transactions')
            ->with(['children' => fn ($q) => $q->withCount('transactions')])
            ->orderBy('name')
            ->get();

        $data = $categories->map(function ($cat) {
            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'color' => $cat->color,
                'direction' => $cat->direction,
                'transactions_count' => $cat->transactions_count,
                'children' => $cat->children->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'color' => $c->color,
                    'direction' => $c->direction,
                    'parent_id' => $c->parent_id,
                    'transactions_count' => $c->transactions_count,
                ])->toArray(),
            ];
        })->toArray();

        return ToolResult::success([
            'data' => $data,
            'total' => BankTransactionCategory::where('team_id', $teamId)->count(),
            'team_id' => $teamId,
        ]);
    }

    protected function create(array $arguments, int $teamId, ToolContext $context): ToolResult
    {
        try {
            $category = app(CategoryService::class)->create($teamId, [
                'name' => $arguments['name'] ?? null,
                'color' => $arguments['color'] ?? null,
                'direction' => $arguments['direction'] ?? null,
                'parent_id' => $arguments['parent_id'] ?? null,
                'user_id' => $context->user?->id,
            ]);
        } catch (CategoryException $e) {
            return ToolResult::error('VALIDATION_ERROR', $e->getMessage());
        }

        return ToolResult::success([
            'message' => "Kategorie '{$category->name}' erstellt.",
            'category' => $this->present($category),
        ]);
    }

    protected function update(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['category_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'category_id ist erforderlich.');
        }

        $category = BankTransactionCategory::forTeam($teamId)->findOrFail($id);

        $data = [];
        foreach (['name', 'color', 'direction'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $data[$key] = $arguments[$key];
            }
        }
        if (array_key_exists('parent_id', $arguments)) {
            $data['parent_id'] = $arguments['parent_id'];
        }

        try {
            app(CategoryService::class)->update($category, $data);
        } catch (CategoryException $e) {
            return ToolResult::error('VALIDATION_ERROR', $e->getMessage());
        }

        return ToolResult::success([
            'message' => "Kategorie '{$category->name}' aktualisiert.",
            'category' => $this->present($category),
        ]);
    }

    protected function delete(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['category_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'category_id ist erforderlich.');
        }

        $category = BankTransactionCategory::forTeam($teamId)->findOrFail($id);
        $name = $category->name;

        $summary = app(CategoryService::class)->delete($category);

        return ToolResult::success([
            'message' => "Kategorie '{$name}' gelöscht.",
            'reassigned' => $summary,
        ]);
    }

    protected function present(BankTransactionCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'color' => $category->color,
            'direction' => $category->direction,
            'default_tax_rate' => $category->default_tax_rate,
            'parent_id' => $category->parent_id,
        ];
    }

    protected function assign(array $arguments, int $teamId): ToolResult
    {
        $categoryId = $arguments['category_id'] ?? null;
        if (!$categoryId) {
            return ToolResult::error('VALIDATION_ERROR', 'category_id ist erforderlich.');
        }

        // Verify category exists
        $category = BankTransactionCategory::forTeam($teamId)->findOrFail($categoryId);

        // Explizite IDs: direkter, sicherer Weg.
        if (!empty($arguments['transaction_ids'])) {
            $count = BankTransaction::where('team_id', $teamId)
                ->whereIn('id', $arguments['transaction_ids'])
                ->update(['category_id' => $categoryId]);

            return ToolResult::success([
                'message' => "{$count} Transaktionen der Kategorie '{$category->name}' zugewiesen.",
                'updated_count' => $count,
                'category_id' => $categoryId,
            ]);
        }

        $cpNeedle = !empty($arguments['counterparty_pattern']) ? mb_strtolower(trim($arguments['counterparty_pattern'], '%')) : null;
        $refNeedle = !empty($arguments['reference_pattern']) ? mb_strtolower(trim($arguments['reference_pattern'], '%')) : null;

        if ($cpNeedle === null && $refNeedle === null) {
            return ToolResult::error('VALIDATION_ERROR', 'transaction_ids, counterparty_pattern oder reference_pattern erforderlich.');
        }

        // counterparty_name/reference sind verschlüsselt → Matching in PHP (Teilstring, case-insensitive, AND).
        $ids = [];
        BankTransaction::where('team_id', $teamId)
            ->chunkById(500, function ($txs) use ($cpNeedle, $refNeedle, &$ids) {
                foreach ($txs as $tx) {
                    $ok = true;
                    if ($cpNeedle !== null) {
                        $ok = $ok && is_string($tx->counterparty_name) && str_contains(mb_strtolower($tx->counterparty_name), $cpNeedle);
                    }
                    if ($refNeedle !== null) {
                        $ok = $ok && is_string($tx->reference) && str_contains(mb_strtolower($tx->reference), $refNeedle);
                    }
                    if ($ok) {
                        $ids[] = $tx->id;
                    }
                }
            });

        $count = 0;
        if (!empty($ids)) {
            $count = BankTransaction::where('team_id', $teamId)->whereIn('id', $ids)->update(['category_id' => $categoryId]);
        }

        return ToolResult::success([
            'message' => "{$count} Transaktionen der Kategorie '{$category->name}' zugewiesen.",
            'updated_count' => $count,
            'category_id' => $categoryId,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'crud',
            'tags' => ['drip', 'categories'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'low',
            'idempotent' => false,
        ];
    }
}
