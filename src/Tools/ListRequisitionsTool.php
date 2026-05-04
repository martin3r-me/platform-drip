<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Drip\Models\Requisition;
use Platform\Drip\Tools\Concerns\ResolvesDripTeam;

class ListRequisitionsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesDripTeam;

    public function getName(): string
    {
        return 'drip.requisitions.GET';
    }

    public function getDescription(): string
    {
        return 'GET /drip/requisitions - Listet GoCardless-Bankverbindungen (Requisitions). Zeigt Status, Hashes, Ablaufdaten. Parameter: team_id (optional), include_trashed (optional, bool).';
    }

    public function getSchema(): array
    {
        return $this->mergeSchemas(
            $this->getStandardGetSchema(),
            [
                'properties' => [
                    'team_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Team-ID. Default: aktuelles Team.',
                    ],
                    'include_trashed' => [
                        'type' => 'boolean',
                        'description' => 'Optional: Auch gelöschte Requisitions anzeigen.',
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

            $query = Requisition::query()->where('team_id', $teamId);

            if (!empty($arguments['include_trashed'])) {
                $query->withTrashed();
            }

            $this->applyStandardFilters($query, $arguments, ['status', 'institution_id', 'created_at', 'linked_at', 'access_expires_at']);
            $this->applyStandardSearch($query, $arguments, ['external_id', 'status']);
            $this->applyStandardSort($query, $arguments, ['created_at', 'linked_at', 'access_expires_at', 'status'], 'created_at', 'desc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $items = collect($result['data'])->map(fn (Requisition $r) => [
                'id' => $r->id,
                'uuid' => $r->uuid,
                'external_id' => $r->external_id,
                'reference' => $r->reference,
                'reference_hash' => $r->reference_hash ?? '(NULL - Spalte fehlt oder leer)',
                'institution_id' => $r->institution_id,
                'institution_name' => $r->institution?->name,
                'status' => $r->status,
                'redirect' => $r->redirect,
                'accounts' => $r->accounts,
                'accounts_hash' => $r->accounts_hash ?? '(NULL)',
                'linked_at' => $r->linked_at?->toISOString(),
                'access_expires_at' => $r->access_expires_at?->toISOString(),
                'last_sync_at' => $r->last_sync_at?->toISOString(),
                'deleted_at' => $r->deleted_at?->toISOString(),
                'created_at' => $r->created_at?->toISOString(),
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
            'tags' => ['drip', 'requisitions', 'gocardless', 'bank'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
