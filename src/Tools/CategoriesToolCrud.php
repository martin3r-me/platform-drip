<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Tools\Concerns\ResolvesDripTeam;
use Illuminate\Support\Str;

class CategoriesToolCrud implements ToolContract, ToolMetadataContract
{
    use ResolvesDripTeam;

    public function getName(): string
    {
        return 'drip.categories.CRUD';
    }

    public function getDescription(): string
    {
        return 'CRUD /drip/categories - Verwaltet Transaktionskategorien. action=list (default), action=create (name required, color/parent_id optional), action=update (category_id + Felder), action=delete (category_id).';
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
                'transactions_count' => $cat->transactions_count,
                'children' => $cat->children->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'color' => $c->color,
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
        $name = $arguments['name'] ?? null;
        if (!$name) {
            return ToolResult::error('VALIDATION_ERROR', 'name ist erforderlich.');
        }

        $category = BankTransactionCategory::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'color' => $arguments['color'] ?? null,
            'parent_id' => $arguments['parent_id'] ?? null,
            'team_id' => $teamId,
            'user_id' => $context->user?->id,
        ]);

        return ToolResult::success([
            'message' => "Kategorie '{$name}' erstellt.",
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'color' => $category->color,
                'parent_id' => $category->parent_id,
            ],
        ]);
    }

    protected function update(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['category_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'category_id ist erforderlich.');
        }

        $category = BankTransactionCategory::where('team_id', $teamId)->findOrFail($id);

        $data = array_filter([
            'name' => $arguments['name'] ?? null,
            'color' => $arguments['color'] ?? null,
            'parent_id' => array_key_exists('parent_id', $arguments) ? $arguments['parent_id'] : null,
        ], fn ($v) => $v !== null);

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category->update($data);

        return ToolResult::success([
            'message' => "Kategorie '{$category->name}' aktualisiert.",
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'color' => $category->color,
                'parent_id' => $category->parent_id,
            ],
        ]);
    }

    protected function delete(array $arguments, int $teamId): ToolResult
    {
        $id = $arguments['category_id'] ?? null;
        if (!$id) {
            return ToolResult::error('VALIDATION_ERROR', 'category_id ist erforderlich.');
        }

        $category = BankTransactionCategory::where('team_id', $teamId)->findOrFail($id);
        $name = $category->name;

        // Move children to root
        BankTransactionCategory::where('parent_id', $category->id)
            ->update(['parent_id' => null]);

        $category->delete();

        return ToolResult::success(['message' => "Kategorie '{$name}' gelöscht."]);
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
