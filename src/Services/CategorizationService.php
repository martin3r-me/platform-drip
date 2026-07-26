<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\RecurringPattern;

/**
 * Auto-Kategorisierungs-Engine. Regeln (RecurringPattern) matchen Transaktionen
 * über Matcher (AND-Logik) und weisen eine Kategorie zu.
 *
 * Invarianten:
 *  - Nur AKTIVE Regeln werden ausgewertet.
 *  - Regeln werden nach PRIORITÄT (desc), dann id (asc) ausgewertet →
 *    deterministischer Gewinner bei mehreren Treffern.
 *  - Auto-Zuordnung überschreibt NIE eine bestehende Kategorie (nur unkategorisierte).
 */
class CategorizationService
{
    /**
     * Kategorisiert eine einzelne Transaktion gegen alle Regeln ihres Teams.
     * Gibt die getroffene category_id oder null zurück.
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
                $categoryId = $rule->targetCategoryId();
                if ($categoryId) {
                    return $categoryId;
                }
            }
        }

        return null;
    }

    /**
     * Wendet alle Regeln auf alle unkategorisierten Transaktionen eines Teams an.
     * Gibt die Anzahl neu kategorisierter Transaktionen zurück.
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
     * Wendet eine einzelne Regel auf alle unkategorisierten Transaktionen an.
     * Gibt die Anzahl kategorisierter Transaktionen zurück.
     */
    public function applyRule(RecurringPattern $rule): int
    {
        $matchers = $rule->matchers;
        if (!is_array($matchers) || empty($matchers)) {
            return 0;
        }

        $categoryId = $rule->targetCategoryId();
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
     * Zählt, wie viele Transaktionen eine Regel matchen würde (für Vorschau).
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

    // ── Lernen aus manueller Zuordnung ──────────────────────────────────────

    /**
     * Anzahl weiterer UNKATEGORISIERTER Transaktionen mit derselben Gegenpartei
     * (ohne die übergebene). Nutzt den deterministischen counterparty_name_hash.
     */
    public function countUncategorizedForCounterparty(BankTransaction $tx): int
    {
        $hash = $tx->counterparty_name_hash;
        if (!$hash) {
            return 0;
        }

        return BankTransaction::where('team_id', $tx->team_id)
            ->where('counterparty_name_hash', $hash)
            ->where('direction', $tx->direction) // richtungsscharf: rein/raus getrennt lernen
            ->whereNull('category_id')
            ->where('id', '!=', $tx->id)
            ->count();
    }

    /**
     * Weist alle Transaktionen mit demselben Gegenpartei-Hash einer Kategorie zu.
     * Standardmäßig nur unkategorisierte (überschreibt keine bestehende Zuordnung).
     * Gibt die Anzahl aktualisierter Transaktionen zurück.
     */
    public function applyToCounterparty(int $teamId, string $counterpartyHash, int $categoryId, bool $uncategorizedOnly = true, ?string $direction = null): int
    {
        $query = BankTransaction::where('team_id', $teamId)
            ->where('counterparty_name_hash', $counterpartyHash);

        if ($direction) {
            $query->where('direction', $direction);
        }

        if ($uncategorizedOnly) {
            $query->whereNull('category_id');
        }

        return $query->update(['category_id' => $categoryId]);
    }

    /**
     * Legt eine dauerhafte Regel "counterparty_name equals <name> → Kategorie" an
     * (idempotenter, eindeutiger Name). Priorität hoch, damit gelernte
     * Exakt-Regeln vor generischen contains-Regeln greifen.
     */
    public function createCounterpartyRule(int $teamId, string $counterpartyName, int $categoryId, ?int $userId = null, ?string $direction = null): RecurringPattern
    {
        $dirHint = match ($direction) {
            'credit' => ' (rein)',
            'debit' => ' (raus)',
            default => '',
        };

        $base = 'Auto: ' . Str::limit($counterpartyName, 230, '') . $dirHint;
        $name = $base;
        $i = 2;
        while (RecurringPattern::forTeam($teamId)->where('name', $name)->exists()) {
            $name = $base . ' (' . $i . ')';
            $i++;
        }

        // Matcher: Gegenpartei exakt — bei gelernter Richtung zusätzlich die
        // Richtung, damit dieselbe Gegenpartei je rein/raus getrennt landen kann.
        $matchers = [['field' => 'counterparty_name', 'op' => 'equals', 'value' => $counterpartyName]];
        if ($direction) {
            $matchers[] = ['field' => 'direction', 'op' => 'equals', 'value' => $direction];
        }

        return RecurringPattern::create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'name' => $name,
            'matchers' => $matchers,
            'defaults' => ['category_id' => $categoryId],
            'bank_transaction_category_id' => $categoryId,
            'priority' => 10,
            'is_active' => true,
        ]);
    }

    // ── Matching-Kern ───────────────────────────────────────────────────────

    /**
     * Prüft, ob eine Transaktion ALLE Matcher erfüllt (AND-Logik).
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
            'direction' => $tx->direction, // 'credit' (rein) | 'debit' (raus)
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

    /**
     * Lädt die auszuwertenden Regeln: nur aktiv, mit Matchern, nach Priorität
     * (desc) und id (asc) → deterministische Reihenfolge.
     */
    protected function loadRules(int $teamId): Collection
    {
        return RecurringPattern::forTeam($teamId)
            ->active()
            ->whereNotNull('matchers')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
    }
}
