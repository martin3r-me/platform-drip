<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Core\Support\FieldHasher;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\CashflowSnapshot;

class CashflowSnapshotService
{
    /**
     * Compute snapshots for a team over a date range.
     * Loads TXs via Eloquent (Encryptable decrypts amount automatically),
     * groups in PHP, and upserts snapshot rows.
     *
     * @return int Number of rows upserted
     */
    public function computeForTeam(int $teamId, ?Carbon $since = null, ?Carbon $until = null): int
    {
        $since ??= now()->startOfMonth()->subMonth();
        $until ??= now();

        $transactions = BankTransaction::where('team_id', $teamId)
            ->where(function ($q) {
                $q->whereNull('is_internal_transfer')
                    ->orWhere('is_internal_transfer', false);
            })
            ->where(function ($q) use ($since, $until) {
                $q->where(function ($inner) use ($since, $until) {
                    $inner->whereNotNull('booked_at')
                        ->whereBetween('booked_at', [$since->toDateString(), $until->toDateString()]);
                })->orWhere(function ($or) use ($since, $until) {
                    $or->whereNull('booked_at')
                        ->whereBetween('created_at', [$since->startOfDay(), $until->endOfDay()]);
                });
            })
            ->get([
                'id', 'bank_account_id', 'category_id',
                'counterparty_name_hash', 'direction', 'amount',
                'booked_at', 'created_at',
            ]);

        $buckets = [];
        $now = now();

        foreach ($transactions as $tx) {
            $date = $tx->booked_at ?? $tx->created_at;
            if (!$date) {
                continue;
            }

            $dateCarbon = $date instanceof Carbon ? $date : Carbon::parse($date);
            $monthKey = $dateCarbon->format('Y-m');
            $weekKey = $dateCarbon->format('o-\\WW'); // ISO week: 2026-W19
            $monthStart = $dateCarbon->copy()->startOfMonth()->toDateString();
            $monthEnd = $dateCarbon->copy()->endOfMonth()->toDateString();
            $weekStart = $dateCarbon->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $weekEnd = $dateCarbon->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

            $direction = $tx->direction;
            $bankAccountId = (int) $tx->bank_account_id;
            $categoryId = (int) ($tx->category_id ?? 0);
            $counterpartyHash = (string) ($tx->counterparty_name_hash ?? '');
            $amount = abs((float) $tx->amount);

            // Build keys for all dimension combinations we want to track
            $accountIds = [$bankAccountId, 0]; // per-account + team-wide

            foreach ($accountIds as $accId) {
                // Category dimension (only when categorized, otherwise total row covers it)
                if ($categoryId > 0) {
                    $this->addToBucket($buckets, $accId, $categoryId, '', $direction, 'month', $monthKey, $monthStart, $monthEnd, $amount);
                    $this->addToBucket($buckets, $accId, $categoryId, '', $direction, 'week', $weekKey, $weekStart, $weekEnd, $amount);
                }

                // Counterparty dimension (only if hash present)
                if ($counterpartyHash !== '') {
                    $this->addToBucket($buckets, $accId, 0, $counterpartyHash, $direction, 'month', $monthKey, $monthStart, $monthEnd, $amount);
                    $this->addToBucket($buckets, $accId, 0, $counterpartyHash, $direction, 'week', $weekKey, $weekStart, $weekEnd, $amount);
                }

                // Total row (both dimensions sentinel — includes all TXs)
                $this->addToBucket($buckets, $accId, 0, '', $direction, 'month', $monthKey, $monthStart, $monthEnd, $amount);
                $this->addToBucket($buckets, $accId, 0, '', $direction, 'week', $weekKey, $weekStart, $weekEnd, $amount);
            }
        }

        $rowCount = 0;

        foreach ($buckets as $key => $bucket) {
            CashflowSnapshot::updateOrCreate(
                [
                    'team_id' => $teamId,
                    'bank_account_id' => $bucket['bank_account_id'],
                    'category_id' => $bucket['category_id'],
                    'counterparty_hash' => $bucket['counterparty_hash'],
                    'direction' => $bucket['direction'],
                    'period_type' => $bucket['period_type'],
                    'period_key' => $bucket['period_key'],
                ],
                [
                    'period_start' => $bucket['period_start'],
                    'period_end' => $bucket['period_end'],
                    'total_amount' => $bucket['total_amount'],
                    'transaction_count' => $bucket['transaction_count'],
                    'computed_at' => $now,
                ]
            );
            $rowCount++;
        }

        return $rowCount;
    }

    /**
     * Compare two periods for a given dimension.
     */
    public function compare(
        int $teamId,
        string $periodA,
        string $periodB,
        string $dimension,
        int|string $dimensionId,
        string $direction = 'debit',
        ?int $bankAccountId = null,
    ): array {
        $query = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->forBankAccount($bankAccountId)
            ->where('direction', $direction)
            ->whereIn('period_key', [$periodA, $periodB]);

        $query = $this->applyDimensionScope($query, $dimension, $dimensionId);

        $rows = $query->get()->keyBy('period_key');

        $amountA = (float) ($rows[$periodA]->total_amount ?? 0);
        $amountB = (float) ($rows[$periodB]->total_amount ?? 0);
        $delta = $amountA - $amountB;
        $deltaPercent = $amountB > 0 ? round($delta / $amountB * 100, 1) : ($amountA > 0 ? 100.0 : 0.0);

        return [
            'period_a' => $periodA,
            'period_b' => $periodB,
            'amount_a' => $amountA,
            'amount_b' => $amountB,
            'count_a' => (int) ($rows[$periodA]->transaction_count ?? 0),
            'count_b' => (int) ($rows[$periodB]->transaction_count ?? 0),
            'delta' => $delta,
            'delta_percent' => $deltaPercent,
            'direction' => $direction,
            'dimension' => $dimension,
        ];
    }

    /**
     * Trend over N months for a given dimension.
     */
    public function trend(
        int $teamId,
        string $dimension,
        int|string $dimensionId,
        int $months = 6,
        string $direction = 'debit',
        ?int $bankAccountId = null,
    ): array {
        $periodKeys = collect();
        for ($i = $months - 1; $i >= 0; $i--) {
            $periodKeys->push(now()->subMonths($i)->format('Y-m'));
        }

        $query = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->forBankAccount($bankAccountId)
            ->where('direction', $direction)
            ->whereIn('period_key', $periodKeys->all());

        $query = $this->applyDimensionScope($query, $dimension, $dimensionId);

        $rows = $query->get()->keyBy('period_key');

        return $periodKeys->map(fn (string $pk) => [
            'period' => $pk,
            'amount' => (float) ($rows[$pk]->total_amount ?? 0),
            'count' => (int) ($rows[$pk]->transaction_count ?? 0),
        ])->all();
    }

    /**
     * Top N categories or counterparties for a period.
     */
    public function top(
        int $teamId,
        string $dimension,
        string $periodKey,
        string $direction = 'debit',
        int $limit = 10,
        ?int $bankAccountId = null,
    ): array {
        $query = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->forBankAccount($bankAccountId)
            ->where('direction', $direction)
            ->where('period_key', $periodKey)
            ->where('total_amount', '>', 0)
            ->orderByDesc('total_amount')
            ->limit($limit);

        if ($dimension === 'category') {
            $query->where('category_id', '!=', CashflowSnapshot::SENTINEL_ALL)
                ->where('counterparty_hash', CashflowSnapshot::SENTINEL_HASH_ALL);
        } else {
            $query->where('counterparty_hash', '!=', CashflowSnapshot::SENTINEL_HASH_ALL)
                ->where('category_id', CashflowSnapshot::SENTINEL_ALL);
        }

        $rows = $query->get();

        if ($dimension === 'category') {
            $categoryIds = $rows->pluck('category_id')->filter()->unique();
            $categories = BankTransactionCategory::whereIn('id', $categoryIds)
                ->pluck('name', 'id');

            return $rows->map(fn ($row) => [
                'category_id' => (int) $row->category_id,
                'name' => $categories[$row->category_id] ?? 'Unkategorisiert',
                'amount' => (float) $row->total_amount,
                'count' => (int) $row->transaction_count,
            ])->all();
        }

        // Counterparty: resolve names via TX lookup
        return $rows->map(fn ($row) => [
            'counterparty_hash' => $row->counterparty_hash,
            'name' => $this->resolveCounterpartyName($teamId, $row->counterparty_hash),
            'amount' => (float) $row->total_amount,
            'count' => (int) $row->transaction_count,
        ])->all();
    }

    /**
     * Weekly breakdown within a month.
     */
    public function weeklyBreakdown(
        int $teamId,
        string $dimension,
        int|string $dimensionId,
        string $monthKey,
        string $direction = 'debit',
        ?int $bankAccountId = null,
    ): array {
        $monthStart = Carbon::createFromFormat('Y-m', $monthKey)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $query = CashflowSnapshot::forTeam($teamId)
            ->weekly()
            ->forBankAccount($bankAccountId)
            ->where('direction', $direction)
            ->where('period_start', '<=', $monthEnd->toDateString())
            ->where('period_end', '>=', $monthStart->toDateString());

        $query = $this->applyDimensionScope($query, $dimension, $dimensionId);

        return $query->orderBy('period_start')
            ->get()
            ->map(fn ($row) => [
                'period_key' => $row->period_key,
                'period_start' => $row->period_start->format('Y-m-d'),
                'period_end' => $row->period_end->format('Y-m-d'),
                'amount' => (float) $row->total_amount,
                'count' => (int) $row->transaction_count,
            ])->all();
    }

    /**
     * Resolve counterparty hash to name via single TX lookup.
     */
    public function resolveCounterpartyName(int $teamId, string $hash): string
    {
        $tx = BankTransaction::where('team_id', $teamId)
            ->where('counterparty_name_hash', $hash)
            ->whereNotNull('counterparty_name')
            ->first(['counterparty_name']);

        return $tx?->counterparty_name ?? '(unbekannt)';
    }

    /**
     * Find counterparty hash from name using FieldHasher.
     */
    public function findCounterpartyHash(int $teamId, string $name): ?string
    {
        $hash = FieldHasher::hmacSha256($name, (string) $teamId);

        if (!$hash) {
            return null;
        }

        $exists = BankTransaction::where('team_id', $teamId)
            ->where('counterparty_name_hash', $hash)
            ->exists();

        return $exists ? $hash : null;
    }

    // ── Private helpers ──

    private function addToBucket(
        array &$buckets,
        int $bankAccountId,
        int $categoryId,
        string $counterpartyHash,
        string $direction,
        string $periodType,
        string $periodKey,
        string $periodStart,
        string $periodEnd,
        float $amount,
    ): void {
        $key = "{$bankAccountId}|{$categoryId}|{$counterpartyHash}|{$direction}|{$periodType}|{$periodKey}";

        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'bank_account_id' => $bankAccountId,
                'category_id' => $categoryId,
                'counterparty_hash' => $counterpartyHash,
                'direction' => $direction,
                'period_type' => $periodType,
                'period_key' => $periodKey,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_amount' => 0.0,
                'transaction_count' => 0,
            ];
        }

        $buckets[$key]['total_amount'] += $amount;
        $buckets[$key]['transaction_count']++;
    }

    private function applyDimensionScope($query, string $dimension, int|string $dimensionId): mixed
    {
        if ($dimension === 'category') {
            return $query->forCategory((int) $dimensionId);
        }

        return $query->forCounterparty((string) $dimensionId);
    }
}
