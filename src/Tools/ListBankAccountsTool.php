<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Tools\Concerns\ResolvesDripTeam;

class ListBankAccountsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesDripTeam;

    public function getName(): string
    {
        return 'drip.bank_accounts.GET';
    }

    public function getDescription(): string
    {
        return 'GET /drip/bank_accounts - Listet Bankkonten. Parameter: team_id (optional), filters/search/sort/limit/offset.';
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

            $query = BankAccount::query()
                ->where('team_id', $teamId)
                ->with(['institution', 'group', 'balances']);

            $this->applyStandardFilters($query, $arguments, ['name', 'currency', 'institution_id', 'group_id', 'created_at']);
            $this->applyStandardSearch($query, $arguments, ['name', 'external_id']);
            $this->applyStandardSort($query, $arguments, ['name', 'created_at'], 'name', 'asc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $items = collect($result['data'])->map(fn (BankAccount $a) => [
                'id' => $a->id,
                'uuid' => $a->uuid,
                'external_id' => $a->external_id,
                'name' => $a->name,
                'iban' => $a->iban,
                'bban' => $a->bban,
                'bic' => $a->bic,
                'currency' => $a->currency,
                'initial_balance' => $a->initial_balance,
                'institution' => $a->institution?->name,
                'group' => $a->group?->name,
                'balances' => $a->balances->map(fn ($b) => [
                    'type' => $b->balance_type,
                    'amount' => $b->amount,
                    'currency' => $b->currency,
                    'retrieved_at' => $b->retrieved_at?->toISOString(),
                ])->toArray(),
                'last_details_synced_at' => $a->last_details_synced_at?->toISOString(),
                'last_transactions_synced_at' => $a->last_transactions_synced_at?->toISOString(),
                'created_at' => $a->created_at?->toISOString(),
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
            'tags' => ['drip', 'bank', 'accounts'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
