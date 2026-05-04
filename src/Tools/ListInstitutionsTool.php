<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Drip\Models\Institution;
use Platform\Drip\Tools\Concerns\ResolvesDripTeam;

class ListInstitutionsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesDripTeam;

    public function getName(): string
    {
        return 'drip.institutions.GET';
    }

    public function getDescription(): string
    {
        return 'GET /drip/institutions - Listet Banken/Institute. Parameter: team_id (optional), filters/search/sort/limit/offset.';
    }

    public function getSchema(): array
    {
        return $this->mergeSchemas(
            $this->getStandardGetSchema(),
            [
                'properties' => [
                    'team_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Team-ID.',
                    ],
                ],
            ]
        );
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int)$resolved['team_id'];

            $query = Institution::query()->where('team_id', $teamId);

            $this->applyStandardFilters($query, $arguments, ['name', 'country', 'bic', 'created_at']);
            $this->applyStandardSearch($query, $arguments, ['name', 'bic', 'external_id']);
            $this->applyStandardSort($query, $arguments, ['name', 'created_at'], 'name', 'asc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $items = collect($result['data'])->map(fn (Institution $i) => [
                'id' => $i->id,
                'uuid' => $i->uuid,
                'external_id' => $i->external_id,
                'name' => $i->name,
                'bic' => $i->bic,
                'country' => $i->country,
                'logo' => $i->logo,
                'transaction_total_days' => $i->transaction_total_days,
                'max_access_valid_for_days' => $i->max_access_valid_for_days,
                'created_at' => $i->created_at?->toISOString(),
            ])->toArray();

            return ToolResult::success([
                'data' => $items,
                'pagination' => $result['pagination'] ?? null,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['drip', 'institutions', 'banks'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
