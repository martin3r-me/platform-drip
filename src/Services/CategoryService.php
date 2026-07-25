<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Models\RecurringPattern;

/**
 * Single Source of Truth für Kategorie-Mutationen (Livewire + MCP-Tool nutzen
 * ausschließlich diesen Service). Erzwingt die fachlichen Invarianten:
 *  - Team-Scoping des Parents
 *  - maximale Hierarchie-Tiefe (MAX_DEPTH)
 *  - kein Zyklus (Kategorie kann nicht sich selbst/ihr Kind als Parent haben)
 *  - Richtungs-Vererbung (Unterkategorie erbt Richtung des Parents beim Anlegen)
 *  - Namens-Eindeutigkeit je Ebene (team_id + parent_id)
 * und hängt beim Löschen alle Referenzen sauber auf den Parent um (keine
 * verwaisten Transaktionen/Regeln/Budgets).
 *
 * Integritätsfehler werden als {@see CategoryException} (mit Feldbezug) geworfen.
 */
class CategoryService
{
    /** Erlaubte Tiefe: 1 = Hauptkategorie, 2 = Unterkategorie. Zentral anhebbar. */
    public const MAX_DEPTH = 2;

    public function create(int $teamId, array $attributes): BankTransactionCategory
    {
        $data = $this->prepare($teamId, null, $attributes);

        $category = new BankTransactionCategory();
        $category->team_id = $teamId;
        if (!empty($attributes['user_id'])) {
            $category->user_id = $attributes['user_id'];
        }
        $category->fill($data);
        $category->save();

        return $category;
    }

    public function update(BankTransactionCategory $category, array $attributes): BankTransactionCategory
    {
        $data = $this->prepare((int) $category->team_id, $category, $attributes);
        $category->fill($data);
        $category->save();

        return $category;
    }

    /**
     * Löscht eine Kategorie und hängt alle Referenzen auf den Parent um.
     * Hat die Kategorie keinen Parent, werden Transaktionen/Budgets entkoppelt
     * (category_id = null) und ins Leere zeigende Regeln gelöscht.
     * Unterkategorien rücken auf die Hauptebene.
     *
     * @return array{transactions:int,budgets:int,rules_repointed:int,rules_deleted:int,children_promoted:int}
     */
    public function delete(BankTransactionCategory $category): array
    {
        $teamId = (int) $category->team_id;
        $parentId = $category->parent_id; // Umhänge-Ziel (null = Root → entkoppeln)

        return DB::transaction(function () use ($category, $teamId, $parentId) {
            $transactions = BankTransaction::where('team_id', $teamId)
                ->where('category_id', $category->id)
                ->update(['category_id' => $parentId]);

            $budgets = BudgetItem::where('team_id', $teamId)
                ->where('category_id', $category->id)
                ->update(['category_id' => $parentId]);

            [$rulesRepointed, $rulesDeleted] = $this->reassignRules($teamId, (int) $category->id, $parentId);

            $childrenPromoted = BankTransactionCategory::where('team_id', $teamId)
                ->where('parent_id', $category->id)
                ->update(['parent_id' => null]);

            $category->delete();

            return [
                'transactions' => $transactions,
                'budgets' => $budgets,
                'rules_repointed' => $rulesRepointed,
                'rules_deleted' => $rulesDeleted,
                'children_promoted' => $childrenPromoted,
            ];
        });
    }

    /**
     * Regeln, die auf die Kategorie zeigen (Spalte ODER defaults->category_id),
     * auf den Parent umhängen bzw. – ohne Parent – löschen. In PHP gefiltert,
     * damit es DB-unabhängig (JSON) korrekt bleibt.
     *
     * @return array{0:int,1:int} [repointed, deleted]
     */
    protected function reassignRules(int $teamId, int $categoryId, ?int $parentId): array
    {
        $repointed = 0;
        $deleted = 0;

        $rules = RecurringPattern::where('team_id', $teamId)->get();

        foreach ($rules as $rule) {
            $defaults = is_array($rule->defaults) ? $rule->defaults : [];
            $targetsThis = (int) $rule->bank_transaction_category_id === $categoryId
                || (int) ($defaults['category_id'] ?? 0) === $categoryId;

            if (!$targetsThis) {
                continue;
            }

            if ($parentId) {
                $rule->bank_transaction_category_id = $parentId;
                if ((int) ($defaults['category_id'] ?? 0) === $categoryId) {
                    $defaults['category_id'] = $parentId;
                    $rule->defaults = $defaults;
                }
                $rule->save();
                $repointed++;
            } else {
                $rule->delete();
                $deleted++;
            }
        }

        return [$repointed, $deleted];
    }

    /**
     * Validiert + normalisiert die Eingaben und liefert das persistierbare Array.
     *
     * @throws CategoryException
     */
    protected function prepare(int $teamId, ?BankTransactionCategory $existing, array $attr): array
    {
        // Name
        $name = trim((string) ($attr['name'] ?? $existing?->name ?? ''));
        if ($name === '') {
            throw new CategoryException('name', 'Name ist erforderlich.');
        }
        if (mb_strlen($name) > 255) {
            throw new CategoryException('name', 'Name ist zu lang (max. 255 Zeichen).');
        }

        // Parent + Tiefen-/Zyklus-Schutz
        $parentId = array_key_exists('parent_id', $attr)
            ? ($attr['parent_id'] ?: null)
            : ($existing?->parent_id);
        $parent = null;

        if ($parentId !== null) {
            $parentId = (int) $parentId;
            $parent = BankTransactionCategory::forTeam($teamId)->find($parentId);

            if (!$parent) {
                throw new CategoryException('parent_id', 'Übergeordnete Kategorie wurde nicht gefunden.');
            }
            if ($existing && (int) $parent->id === (int) $existing->id) {
                throw new CategoryException('parent_id', 'Eine Kategorie kann sich nicht selbst übergeordnet sein.');
            }
            // Parent muss auf Ebene 1 liegen (MAX_DEPTH = 2).
            if ($parent->depth() >= self::MAX_DEPTH) {
                throw new CategoryException('parent_id', 'Maximal ' . self::MAX_DEPTH . ' Ebenen erlaubt: die gewählte Kategorie ist bereits eine Unterkategorie.');
            }
            // Eine Kategorie mit eigenen Unterkategorien darf nicht selbst untergeordnet werden.
            if ($existing && $existing->children()->exists()) {
                throw new CategoryException('parent_id', 'Diese Kategorie hat Unterkategorien und kann daher nicht selbst untergeordnet werden.');
            }
        }

        // Richtung – Unterkategorie erbt die Richtung des Parents, wenn nicht explizit gesetzt.
        $direction = $attr['direction']
            ?? $existing?->direction
            ?? $parent?->direction
            ?? BankTransactionCategory::DIRECTION_DEBIT;

        if (!in_array($direction, BankTransactionCategory::DIRECTIONS, true)) {
            throw new CategoryException('direction', 'Ungültige Richtung.');
        }

        // USt-Satz
        $taxRaw = array_key_exists('default_tax_rate', $attr)
            ? $attr['default_tax_rate']
            : ($existing?->default_tax_rate);
        $tax = ($taxRaw === '' || $taxRaw === null) ? null : (float) $taxRaw;
        if ($tax !== null && ($tax < 0 || $tax > 100)) {
            throw new CategoryException('default_tax_rate', 'USt-Satz muss zwischen 0 und 100 liegen.');
        }

        // Namens-Eindeutigkeit je Ebene (team_id + parent_id)
        $duplicate = BankTransactionCategory::forTeam($teamId)
            ->where('parent_id', $parentId)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($duplicate) {
            throw new CategoryException('name', 'Auf dieser Ebene gibt es bereits eine Kategorie mit diesem Namen.');
        }

        // Farbe (Tone-Name oder Hex; leere Auswahl = keine Farbe)
        $color = array_key_exists('color', $attr)
            ? ($attr['color'] ?: null)
            : ($existing?->color);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'color' => $color,
            'parent_id' => $parentId,
            'direction' => $direction,
            'default_tax_rate' => $tax,
        ];
    }
}
