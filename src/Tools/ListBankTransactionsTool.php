<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Tools\Concerns\ResolvesDripTeam;

class ListBankTransactionsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesDripTeam;

    public function getName(): string
    {
        return 'drip.bank_transactions.GET';
    }

    public function getDescription(): string
    {
        return 'GET /drip/bank_transactions - Listet Banktransaktionen. Parameter: team_id (optional), bank_account_id (optional), filters/search/sort/limit/offset.';
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
                    'bank_account_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Filter auf ein Bankkonto.',
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

            $query = BankTransaction::query()
                ->where('team_id', $teamId)
                ->with(['bankAccount', 'category']);

            if (!empty($arguments['bank_account_id'])) {
                $query->where('bank_account_id', (int)$arguments['bank_account_id']);
            }

            $this->applyStandardFilters($query, $arguments, ['direction', 'status', 'currency', 'booked_at', 'bank_account_id', 'category_id']);
            $this->applyStandardSearch($query, $arguments, ['transaction_id']);
            $this->applyStandardSort($query, $arguments, ['booked_at', 'created_at'], 'booked_at', 'desc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $items = collect($result['data'])->map(fn (BankTransaction $tx) => [
                'id' => $tx->id,
                'uuid' => $tx->uuid,
                'transaction_id' => $tx->transaction_id,
                'bank_account' => $tx->bankAccount?->name,
                'booked_at' => $tx->booked_at?->toDateString(),
                'amount' => $tx->amount,
                'currency' => $tx->currency,
                'direction' => $tx->direction,
                'counterparty_name' => $tx->counterparty_name,
                'counterparty_iban' => $tx->counterparty_iban,
                'reference' => $tx->reference,
                'debtor_name' => $tx->debtor_name,
                'creditor_name' => $tx->creditor_name,
                'category' => $tx->category?->name,
                'status' => $tx->status,
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
            'tags' => ['drip', 'bank', 'transactions'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
