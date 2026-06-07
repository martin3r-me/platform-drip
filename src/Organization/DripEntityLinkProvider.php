<?php

namespace Platform\Drip\Organization;

use Illuminate\Database\Eloquent\Builder;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankAccountGroup;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\CashflowSnapshot;
use Platform\Drip\Services\CashflowSnapshotService;
use Platform\Organization\Contracts\EntityLinkProvider;
use Platform\Organization\Contracts\HasCostDriverMetrics;
use Platform\Organization\Contracts\HasMetricDefinitions;

class DripEntityLinkProvider implements EntityLinkProvider, HasMetricDefinitions, HasCostDriverMetrics
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
                ['field' => 'percentage', 'format' => 'percentage'],
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

    /**
     * Cost-Driver-Adjustments: Verschiebung von Cashflow-Anteilen
     * von der Default-Entity (Kontengruppen-Owner) auf Kostenverursacher.
     *
     * Logik:
     * 1. Alle Transaktionen im aktuellen Monat mit cost-driver Links laden
     * 2. Für jede: attributierter Betrag = amount × percentage / 100
     * 3. Default-Entity (Kontengruppen-Owner) bekommt negative Korrektur
     * 4. Cost-Driver-Entity bekommt positive Zurechnung
     */
    public function costDriverAdjustments(array $groupLinksByEntity): array
    {
        $currentMonth = now()->format('Y-m');

        // 1. Find cost-driver dimension
        $costDriverDef = \Platform\Organization\Models\OrganizationDimensionDefinition::findByKey('cost-driver');
        if (!$costDriverDef) {
            return [];
        }

        // 2. Load all cost-driver links for drip_bank_transaction in this team
        $costDriverLinks = \Platform\Organization\Models\OrganizationDimensionLink::where('dimension_definition_id', $costDriverDef->id)
            ->where('linkable_type', 'drip_bank_transaction')
            ->with('value')
            ->get();

        if ($costDriverLinks->isEmpty()) {
            return [];
        }

        // 3. Load linked transactions for current month
        $transactionIds = $costDriverLinks->pluck('linkable_id')->unique()->toArray();

        $transactions = BankTransaction::whereIn('id', $transactionIds)
            ->whereRaw("DATE_FORMAT(booked_at, '%Y-%m') = ?", [$currentMonth])
            ->select('id', 'bank_account_id', 'amount', 'direction')
            ->get()
            ->keyBy('id');

        if ($transactions->isEmpty()) {
            return [];
        }

        // 4. Build account → group → default entity map
        $accountIds = $transactions->pluck('bank_account_id')->unique()->toArray();
        $accounts = BankAccount::whereIn('id', $accountIds)->select('id', 'group_id')->get();
        $accountToGroup = $accounts->pluck('group_id', 'id')->toArray();

        // Invert groupLinksByEntity: groupId → entityId
        $groupToEntity = [];
        foreach ($groupLinksByEntity as $entityId => $groupIds) {
            foreach ($groupIds as $gid) {
                $groupToEntity[$gid] = $entityId;
            }
        }

        // 5. Build cost-driver value → entity map
        $dimValueToEntity = [];
        foreach ($costDriverDef->values()->get() as $v) {
            $sourceEntityId = $v->metadata['source_entity_id'] ?? null;
            if ($sourceEntityId) {
                $dimValueToEntity[$v->id] = $sourceEntityId;
            }
        }

        // 6. Group cost-driver links by transaction
        $linksByTransaction = $costDriverLinks->groupBy('linkable_id');

        // 7. Calculate adjustments
        $adjustments = []; // entityId => [cashflow_in => x, cashflow_out => y]

        foreach ($linksByTransaction as $txId => $links) {
            $tx = $transactions->get($txId);
            if (!$tx) {
                continue;
            }

            $groupId = $accountToGroup[$tx->bank_account_id] ?? null;
            $defaultEntityId = $groupId ? ($groupToEntity[$groupId] ?? null) : null;

            if (!$defaultEntityId) {
                continue;
            }

            $amount = abs((float) $tx->amount);
            $isCredit = $tx->direction === 'credit';

            foreach ($links as $link) {
                $costDriverEntityId = $dimValueToEntity[$link->dimension_value_id] ?? null;
                if (!$costDriverEntityId || $costDriverEntityId === $defaultEntityId) {
                    continue;
                }

                $pct = (float) ($link->percentage ?? 0);
                if ($pct <= 0) {
                    continue;
                }

                $attributed = round($amount * $pct / 100, 2);

                // Add to cost-driver entity
                if ($isCredit) {
                    $adjustments[$costDriverEntityId]['cashflow_in'] = ($adjustments[$costDriverEntityId]['cashflow_in'] ?? 0) + $attributed;
                } else {
                    $adjustments[$costDriverEntityId]['cashflow_out'] = ($adjustments[$costDriverEntityId]['cashflow_out'] ?? 0) + $attributed;
                }

                // Subtract from default entity
                if ($isCredit) {
                    $adjustments[$defaultEntityId]['cashflow_in'] = ($adjustments[$defaultEntityId]['cashflow_in'] ?? 0) - $attributed;
                } else {
                    $adjustments[$defaultEntityId]['cashflow_out'] = ($adjustments[$defaultEntityId]['cashflow_out'] ?? 0) - $attributed;
                }
            }
        }

        // 8. Calculate net for each entity
        foreach ($adjustments as $entityId => &$m) {
            $m['cashflow_in'] = round($m['cashflow_in'] ?? 0, 2);
            $m['cashflow_out'] = round($m['cashflow_out'] ?? 0, 2);
            $m['cashflow_net'] = round($m['cashflow_in'] - $m['cashflow_out'], 2);
        }
        unset($m);

        return $adjustments;
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
                'basis' => 'window_30d',
                'is_dimension_primary' => true,
            ],
            'cashflow_out' => [
                'label' => 'Ausgaben (Monat)',
                'group' => 'finance',
                'direction' => 'down',
                'unit' => 'currency',
                'dimension' => 'costs',
                'type' => 'flow',
                'aggregation_mode' => 'rolled_up',
                'basis' => 'window_30d',
                'is_dimension_primary' => true,
            ],
            'cashflow_net' => [
                'label' => 'Cashflow netto (Monat)',
                'group' => 'finance',
                'direction' => 'up',
                'unit' => 'currency',
                'dimension' => 'revenue',
                'type' => 'flow',
                'aggregation_mode' => 'rolled_up',
                'basis' => 'window_30d',
            ],
            'cashflow_tx_count' => [
                'label' => 'Transaktionen (Monat)',
                'group' => 'finance',
                'direction' => 'neutral',
                'unit' => 'count',
                'dimension' => 'throughput',
                'type' => 'flow',
                'aggregation_mode' => 'rolled_up',
                'basis' => 'window_30d',
            ],
        ];
    }
}
