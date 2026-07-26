<?php

use Illuminate\Database\Migrations\Migration;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\RecurringPattern;

return new class extends Migration
{
    /**
     * Bestehende Auto-Regeln richtungsscharf machen: die Richtung wird aus der
     * Ziel-Kategorie abgeleitet (Umsatzerlöse=credit, Ausgaben-Kategorien=debit)
     * und als Matcher ergänzt. So fängt z. B. eine Finanzamt-Regel (→ Steuern &
     * Abgaben, debit) nicht mehr die Erstattungen (credit).
     *
     * Idempotent + verschlüsselungssicher (läuft übers Model): überspringt
     * Regeln, die schon einen direction-Matcher haben, sowie Kategorien ohne
     * klare Richtung (both/null — z. B. Bankgebühren, Zinsen, Ausleihungen).
     */
    public function up(): void
    {
        if (!class_exists(RecurringPattern::class)) {
            return;
        }

        RecurringPattern::query()->chunkById(200, function ($rules) {
            foreach ($rules as $rule) {
                $matchers = $rule->matchers;
                if (!is_array($matchers) || $matchers === []) {
                    continue;
                }

                // Schon richtungsscharf? → nichts tun.
                foreach ($matchers as $m) {
                    if (($m['field'] ?? null) === 'direction') {
                        continue 2;
                    }
                }

                $categoryId = $rule->bank_transaction_category_id
                    ?? (is_array($rule->defaults) ? ($rule->defaults['category_id'] ?? null) : null);
                if (!$categoryId) {
                    continue;
                }

                $direction = BankTransactionCategory::query()->whereKey($categoryId)->value('direction');
                if (!in_array($direction, ['debit', 'credit'], true)) {
                    continue; // both / null → Regel bleibt richtungsneutral
                }

                $matchers[] = ['field' => 'direction', 'op' => 'equals', 'value' => $direction];
                $rule->matchers = $matchers;
                $rule->save();
            }
        });
    }

    public function down(): void
    {
        // Reversibel: die ergänzten direction-Matcher wieder entfernen.
        if (!class_exists(RecurringPattern::class)) {
            return;
        }

        RecurringPattern::query()->chunkById(200, function ($rules) {
            foreach ($rules as $rule) {
                $matchers = $rule->matchers;
                if (!is_array($matchers)) {
                    continue;
                }

                $filtered = array_values(array_filter(
                    $matchers,
                    fn ($m) => ($m['field'] ?? null) !== 'direction'
                ));

                if (count($filtered) !== count($matchers)) {
                    $rule->matchers = $filtered;
                    $rule->save();
                }
            }
        });
    }
};
