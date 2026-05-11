<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Collection;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\RecurringPattern;

class CategorizationService
{
    /**
     * Categorize a single transaction against all rules for its team.
     * Returns the matched category_id or null.
     */
    public function categorizeTransaction(BankTransaction $tx, ?Collection $rules = null): ?int
    {
        $rules = $rules ?? $this->loadRules((int) $tx->team_id);

        foreach ($rules as $rule) {
            $matchers = $rule->matchers;
            if (!is_array($matchers) || empty($matchers)) {
                continue;
            }

            if ($this->matchesRule($tx, $matchers)) {
                $defaults = $rule->defaults;
                $categoryId = $defaults['category_id'] ?? $rule->bank_transaction_category_id ?? null;
                if ($categoryId) {
                    return (int) $categoryId;
                }
            }
        }

        return null;
    }

    /**
     * Categorize all uncategorized transactions for a team.
     * Returns the number of categorized transactions.
     */
    public function categorizeUncategorized(int $teamId): int
    {
        $rules = $this->loadRules($teamId);
        if ($rules->isEmpty()) {
            return 0;
        }

        $count = 0;

        BankTransaction::where('team_id', $teamId)
            ->whereNull('category_id')
            ->chunkById(500, function (Collection $txs) use ($rules, &$count) {
                foreach ($txs as $tx) {
                    $categoryId = $this->categorizeTransaction($tx, $rules);
                    if ($categoryId) {
                        $tx->category_id = $categoryId;
                        $tx->save();
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Apply a single rule to all uncategorized transactions for a team.
     * Returns the number of categorized transactions.
     */
    public function applyRule(RecurringPattern $rule): int
    {
        $matchers = $rule->matchers;
        if (!is_array($matchers) || empty($matchers)) {
            return 0;
        }

        $defaults = $rule->defaults;
        $categoryId = $defaults['category_id'] ?? $rule->bank_transaction_category_id ?? null;
        if (!$categoryId) {
            return 0;
        }

        $count = 0;

        BankTransaction::where('team_id', $rule->team_id)
            ->whereNull('category_id')
            ->chunkById(500, function (Collection $txs) use ($matchers, $categoryId, &$count) {
                foreach ($txs as $tx) {
                    if ($this->matchesRule($tx, $matchers)) {
                        $tx->category_id = $categoryId;
                        $tx->save();
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Count how many transactions would match a rule (for preview).
     */
    public function countMatches(RecurringPattern $rule, bool $uncategorizedOnly = true): int
    {
        $matchers = $rule->matchers;
        if (!is_array($matchers) || empty($matchers)) {
            return 0;
        }

        $count = 0;

        $query = BankTransaction::where('team_id', $rule->team_id);
        if ($uncategorizedOnly) {
            $query->whereNull('category_id');
        }

        $query->chunkById(500, function (Collection $txs) use ($matchers, &$count) {
            foreach ($txs as $tx) {
                if ($this->matchesRule($tx, $matchers)) {
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * Check if a transaction matches all matchers (AND logic).
     */
    public function matchesRule(BankTransaction $tx, array $matchers): bool
    {
        foreach ($matchers as $matcher) {
            $field = $matcher['field'] ?? null;
            $op = $matcher['op'] ?? null;
            $value = $matcher['value'] ?? null;

            if (!$field || !$op) {
                return false;
            }

            $txValue = $this->getFieldValue($tx, $field);

            if (!$this->matchOperator($txValue, $op, $value)) {
                return false;
            }
        }

        return true;
    }

    protected function getFieldValue(BankTransaction $tx, string $field): mixed
    {
        return match ($field) {
            'counterparty_name' => $tx->counterparty_name,
            'reference' => $tx->reference,
            'creditor_name' => $tx->creditor_name,
            'amount' => abs((float) $tx->amount),
            'counterparty_iban' => $tx->counterparty_iban,
            'remittance_information' => $tx->remittance_information,
            default => null,
        };
    }

    protected function matchOperator(mixed $txValue, string $op, mixed $matchValue): bool
    {
        if ($txValue === null && $op !== 'equals') {
            return false;
        }

        return match ($op) {
            'contains' => is_string($txValue) && str_contains(mb_strtolower($txValue), mb_strtolower((string) $matchValue)),
            'starts_with' => is_string($txValue) && str_starts_with(mb_strtolower($txValue), mb_strtolower((string) $matchValue)),
            'equals' => is_numeric($txValue) && is_numeric($matchValue)
                ? (float) $txValue === (float) $matchValue
                : mb_strtolower((string) $txValue) === mb_strtolower((string) $matchValue),
            'gte' => is_numeric($txValue) && (float) $txValue >= (float) $matchValue,
            'lte' => is_numeric($txValue) && (float) $txValue <= (float) $matchValue,
            default => false,
        };
    }

    protected function loadRules(int $teamId): Collection
    {
        return RecurringPattern::where('team_id', $teamId)
            ->whereNotNull('matchers')
            ->get();
    }
}
