<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Models\InternalTransfer;

class RecurringDetectionService
{
    /**
     * Detect recurring transaction patterns for a team.
     *
     * @return Collection<int, array> Collection of suggestion DTOs
     */
    public function detect(int $teamId, int $lookbackMonths = 6, int $minMonths = 3): Collection
    {
        $since = now()->subMonths($lookbackMonths)->startOfMonth();

        // Load all transactions in the lookback window (amounts are encrypted, must process in PHP)
        $transactions = BankTransaction::where('team_id', $teamId)
            ->where(function ($q) use ($since) {
                $q->where(function ($inner) use ($since) {
                    $inner->whereNotNull('booked_at')
                        ->where('booked_at', '>=', $since);
                })->orWhere(function ($or) use ($since) {
                    $or->whereNull('booked_at')
                        ->where('created_at', '>=', $since);
                });
            })
            ->get(['id', 'amount', 'direction', 'counterparty_name', 'counterparty_iban', 'category_id', 'booked_at', 'created_at']);

        // Filter out internal transfers
        $transferTxIds = InternalTransfer::where('team_id', $teamId)
            ->pluck('source_transaction_id')
            ->merge(InternalTransfer::where('team_id', $teamId)->pluck('target_transaction_id'))
            ->unique();

        $transactions = $transactions->reject(fn ($tx) => $transferTxIds->contains($tx->id));

        // Group by counterparty_name (lowercase/trimmed) + direction
        $grouped = $transactions->groupBy(function ($tx) {
            $name = mb_strtolower(trim($tx->counterparty_name ?? ''));
            return $name . '|' . $tx->direction;
        });

        // Load existing budget items to filter already-covered patterns
        $existingBudgets = BudgetItem::where('team_id', $teamId)
            ->whereNull('deleted_at')
            ->get(['source_counterparty', 'name', 'direction', 'status']);

        $existingKeys = $existingBudgets->map(function ($b) {
            $key = mb_strtolower(trim($b->source_counterparty ?? $b->name));
            return $key . '|' . $b->direction;
        })->unique()->toArray();

        $candidates = collect();

        foreach ($grouped as $groupKey => $txs) {
            [$nameLower, $direction] = explode('|', $groupKey, 2);

            if ($nameLower === '') {
                continue;
            }

            // Skip if already exists as budget
            if (in_array($groupKey, $existingKeys, true)) {
                continue;
            }

            // Count distinct months
            $monthKeys = $txs->map(function ($tx) {
                $date = $tx->booked_at ?? $tx->created_at;
                return $date ? $date->format('Y-m') : null;
            })->filter()->unique();

            if ($monthKeys->count() < $minMonths) {
                continue;
            }

            // Calculate amounts
            $amounts = $txs->map(fn ($tx) => abs((float) $tx->amount))->values();
            $avg = $amounts->avg();
            $count = $amounts->count();

            if ($avg <= 0) {
                continue;
            }

            // Standard deviation & coefficient of variation
            $stddev = 0;
            if ($count > 1) {
                $variance = $amounts->reduce(fn ($carry, $val) => $carry + pow($val - $avg, 2), 0) / ($count - 1);
                $stddev = sqrt($variance);
            }
            $cv = $avg > 0 ? $stddev / $avg : 999;

            // Only keep if amount is relatively stable (CV < 0.5)
            if ($cv >= 0.5) {
                continue;
            }

            // Determine typical day of month (mode)
            $days = $txs->map(function ($tx) {
                $date = $tx->booked_at ?? $tx->created_at;
                return $date ? (int) $date->format('j') : null;
            })->filter()->countBy()->sortDesc();
            $typicalDay = $days->keys()->first();

            // Determine most common category
            $categoryId = $txs->pluck('category_id')->filter()->countBy()->sortDesc()->keys()->first();

            // Use the original-case counterparty name from first tx
            $displayName = $txs->first()->counterparty_name ?? $nameLower;
            $iban = $txs->first()->counterparty_iban;

            $candidates->push([
                'counterparty_name' => $displayName,
                'counterparty_iban' => $iban,
                'direction' => $direction,
                'avg_amount' => round($avg, 2),
                'month_count' => $monthKeys->count(),
                'cv' => round($cv, 3),
                'typical_day' => $typicalDay,
                'category_id' => $categoryId ? (int) $categoryId : null,
                'tx_count' => $count,
            ]);
        }

        return $candidates->sortByDesc('avg_amount')->values();
    }

    /**
     * Create BudgetItem suggestions from detected patterns.
     *
     * @return int Number of suggestions created
     */
    public function createSuggestions(int $teamId, int $lookbackMonths = 6, int $minMonths = 3): int
    {
        $candidates = $this->detect($teamId, $lookbackMonths, $minMonths);
        $created = 0;

        foreach ($candidates as $candidate) {
            $name = $candidate['counterparty_name'];

            // Check for existing suggestion with same name + direction (avoid duplicates)
            $exists = BudgetItem::where('team_id', $teamId)
                ->where('source_counterparty', $name)
                ->where('direction', $candidate['direction'])
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                continue;
            }

            BudgetItem::create([
                'team_id' => $teamId,
                'name' => $name,
                'direction' => $candidate['direction'],
                'amount' => $candidate['avg_amount'],
                'frequency' => 'monthly',
                'day_of_month' => $candidate['typical_day'],
                'category_id' => $candidate['category_id'],
                'is_active' => false,
                'status' => 'suggested',
                'source_type' => 'detected',
                'source_counterparty' => $name,
                'source_iban' => $candidate['counterparty_iban'],
                'source_month_count' => $candidate['month_count'],
                'source_avg_amount' => $candidate['avg_amount'],
                'suggested_at' => now(),
            ]);

            $created++;
        }

        return $created;
    }
}
