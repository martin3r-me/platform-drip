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
                    'verbose' => [
                        'type' => 'boolean',
                        'description' => 'Optional: Alle Felder ausgeben (inkl. remittance, agents, IBANs etc.).',
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

            $verbose = !empty($arguments['verbose']);

            $items = collect($result['data'])->map(function (BankTransaction $tx) use ($verbose) {
                $base = [
                    'id' => $tx->id,
                    'uuid' => $tx->uuid,
                    'transaction_id' => $tx->transaction_id,
                    'bank_account' => $tx->bankAccount?->name,
                    'bank_account_id' => $tx->bank_account_id,
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
                ];

                if ($verbose) {
                    $base += [
                        'booking_date' => $tx->booking_date?->toDateString(),
                        'booking_date_time' => $tx->booking_date_time?->toISOString(),
                        'value_date' => $tx->value_date?->toDateString(),
                        'value_date_time' => $tx->value_date_time?->toISOString(),
                        'debtor_account_iban' => $tx->debtor_account_iban,
                        'creditor_account_iban' => $tx->creditor_account_iban,
                        'debtor_agent' => $tx->debtor_agent,
                        'creditor_agent' => $tx->creditor_agent,
                        'remittance_information' => $tx->remittance_information,
                        'remittance_information_structured' => $tx->remittance_information_structured,
                        'remittance_information_unstructured' => $tx->remittance_information_unstructured,
                        'transaction_type' => $tx->transaction_type,
                        'bank_transaction_code' => $tx->bank_transaction_code,
                        'proprietary_bank_transaction_code' => $tx->proprietary_bank_transaction_code,
                        'internal_transaction_id' => $tx->internal_transaction_id,
                        'entry_reference' => $tx->entry_reference,
                        'end_to_end_id' => $tx->end_to_end_id,
                        'mandate_id' => $tx->mandate_id,
                        'merchant_category_code' => $tx->merchant_category_code,
                        'creditor_id' => $tx->creditor_id,
                        'purpose_code' => $tx->purpose_code,
                        'ultimate_creditor' => $tx->ultimate_creditor,
                        'ultimate_debtor' => $tx->ultimate_debtor,
                        'additional_information' => $tx->additional_information,
                        'additional_information_structured' => $tx->additional_information_structured,
                        'balance_after_transaction' => $tx->balance_after_transaction,
                        'metadata' => $tx->metadata,
                        'created_at' => $tx->created_at?->toISOString(),
                    ];
                }

                return $base;
            })->toArray();

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
