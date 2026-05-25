<?php

namespace Platform\Drip\Organization;

use Illuminate\Database\Eloquent\Builder;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankAccountGroup;
use Platform\Drip\Models\CashflowSnapshot;
use Platform\Drip\Services\CashflowSnapshotService;
use Platform\Organization\Contracts\EntityLinkProvider;
use Platform\Organization\Contracts\HasMetricDefinitions;

class DripEntityLinkProvider implements EntityLinkProvider, HasMetricDefinitions
{
    public function morphAliases(): array
    {
        return ['drip_bank_account_group', 'drip_bank_transaction'];
    }

    public function linkTypeConfig(): array
    {
        return [
            'drip_bank_account_group' => [
                'label' => 'Kontengruppen',
                'singular' => 'Kontengruppe',
                'icon' => 'banknotes',
                'route' => null,
            ],
            'drip_bank_transaction' => [
                'label' => 'Transaktionen',
                'singular' => 'Transaktion',
                'icon' => 'receipt-percent',
                'route' => null,
            ],
        ];
    }

    public function applyEagerLoading(Builder $query, string $morphAlias, string $fqcn): void
    {
        // No eager loading needed
    }

    public function extractMetadata(string $morphAlias, mixed $model): array
    {
        if ($morphAlias === 'drip_bank_transaction') {
            return [
                'amount' => $model->amount,
                'direction' => $model->direction,
                'counterparty' => $model->counterparty_name,
                'booked_at' => $model->booked_at?->toDateString(),
            ];
        }

        return [];
    }

    public function metadataDisplayRules(): array
    {
        return [
            'drip_bank_transaction' => [
                ['field' => 'amount', 'format' => 'text', 'suffix' => '€'],
                ['field' => 'counterparty', 'format' => 'text'],
                ['field' => 'booked_at', 'format' => 'text'],
            ],
        ];
    }

    public function timeTrackableCascades(): array
    {
        return [];
    }

    public function activityChildren(string $morphAlias, array $linkableIds): array
    {
        return [];
    }

    public function metrics(string $morphAlias, array $linksByEntity): array
    {
        if ($morphAlias !== 'drip_bank_account_group') {
            return [];
        }

        // Collect all group IDs
        $allGroupIds = [];
        foreach ($linksByEntity as $ids) {
            $allGroupIds = array_merge($allGroupIds, $ids);
        }
        $allGroupIds = array_values(array_unique($allGroupIds));

        if (empty($allGroupIds)) {
            return [];
        }

        // 1. Load bank accounts per group
        $accounts = BankAccount::whereIn('group_id', $allGroupIds)
            ->select('id', 'group_id')
            ->get();

        $accountsByGroup = [];
        $allAccountIds = [];
        foreach ($accounts as $account) {
            $accountsByGroup[$account->group_id][] = $account->id;
            $allAccountIds[] = $account->id;
        }

        if (empty($allAccountIds)) {
            return array_fill_keys(array_keys($linksByEntity), [
                'cashflow_in' => 0,
                'cashflow_out' => 0,
                'cashflow_net' => 0,
                'cashflow_tx_count' => 0,
            ]);
        }

        // 2. Load cashflow snapshots for current month (total rows: category=0, counterparty='')
        $currentMonth = now()->format('Y-m');

        $snapshots = CashflowSnapshot::whereIn('bank_account_id', $allAccountIds)
            ->where('period_type', 'month')
            ->where('period_key', $currentMonth)
            ->where('category_id', CashflowSnapshot::SENTINEL_ALL)
            ->where('counterparty_hash', CashflowSnapshot::SENTINEL_HASH_ALL)
            ->get();

        // Index: accountId|direction → snapshot
        $snapshotIndex = [];
        foreach ($snapshots as $snap) {
            $snapshotIndex[$snap->bank_account_id . '|' . $snap->direction] = $snap;
        }

        // 3. Aggregate per entity
        $result = [];
        foreach ($linksByEntity as $entityId => $groupIds) {
            $credit = 0;
            $debit = 0;
            $txCount = 0;

            foreach ($groupIds as $groupId) {
                $accountIds = $accountsByGroup[$groupId] ?? [];
                foreach ($accountIds as $accId) {
                    $creditSnap = $snapshotIndex[$accId . '|credit'] ?? null;
                    $debitSnap = $snapshotIndex[$accId . '|debit'] ?? null;

                    if ($creditSnap) {
                        $credit += (float) $creditSnap->total_amount;
                        $txCount += (int) $creditSnap->transaction_count;
                    }
                    if ($debitSnap) {
                        $debit += (float) $debitSnap->total_amount;
                        $txCount += (int) $debitSnap->transaction_count;
                    }
                }
            }

            $result[$entityId] = [
                'cashflow_in' => round($credit, 2),
                'cashflow_out' => round($debit, 2),
                'cashflow_net' => round($credit - $debit, 2),
                'cashflow_tx_count' => $txCount,
            ];
        }

        return $result;
    }

    public function metricDefinitions(): array
    {
        return [
            'cashflow_in' => [
                'label' => 'Einnahmen (Monat)',
                'group' => 'finance',
                'direction' => 'up',
                'unit' => 'currency',
                'dimension' => 'revenue',
                'type' => 'flow',
                'aggregation_mode' => 'rolled_up',
            ],
            'cashflow_out' => [
                'label' => 'Ausgaben (Monat)',
                'group' => 'finance',
                'direction' => 'down',
                'unit' => 'currency',
                'dimension' => 'costs',
                'type' => 'flow',
                'aggregation_mode' => 'rolled_up',
            ],
            'cashflow_net' => [
                'label' => 'Cashflow netto (Monat)',
                'group' => 'finance',
                'direction' => 'up',
                'unit' => 'currency',
                'dimension' => 'revenue',
                'type' => 'flow',
                'aggregation_mode' => 'rolled_up',
            ],
            'cashflow_tx_count' => [
                'label' => 'Transaktionen (Monat)',
                'group' => 'finance',
                'direction' => 'neutral',
                'unit' => 'count',
                'dimension' => 'throughput',
                'type' => 'flow',
                'aggregation_mode' => 'rolled_up',
            ],
        ];
    }
}
