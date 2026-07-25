<?php

namespace Platform\Drip\Services;

/**
 * Kapselt die (lose) Kopplung an das Organization-Modul für die Transaktions-
 * Kontierung: eine Transaktion wird über die `cost-driver`-Dimension anteilig
 * an Organisations-Entities (Leistungsempfänger) gebunden.
 *
 * Bewusst generisch: die Empfänger kommen live aus der Organisation (alle
 * aktiven cost-driver-Werte) — wächst die Org, wachsen die Optionen. Richtungs-
 * neutral (Einnahmen wie Ausgaben). Bricht nicht, wenn das Org-Modul fehlt.
 */
class KontierungService
{
    public const DIMENSION = 'cost-driver';
    public const CONTEXT_TYPE = 'drip_bank_transaction';

    public function available(): bool
    {
        return class_exists(\Platform\Organization\Services\DimensionLinkService::class)
            && class_exists(\Platform\Organization\Models\OrganizationDimensionValue::class)
            && class_exists(\Platform\Organization\Models\OrganizationDimensionDefinition::class)
            && \Platform\Organization\Models\OrganizationDimensionDefinition::findByKey(self::DIMENSION) !== null;
    }

    /**
     * Auswählbare Empfänger (alle aktiven cost-driver-Werte, live aus der Org).
     *
     * @return array<int, array{value:int, label:string}>
     */
    public function recipientOptions(): array
    {
        if (!$this->available()) {
            return [];
        }

        $def = \Platform\Organization\Models\OrganizationDimensionDefinition::findByKey(self::DIMENSION);

        return \Platform\Organization\Models\OrganizationDimensionValue::query()
            ->where('dimension_definition_id', $def->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($v) => [
                'value' => (int) $v->id,
                'label' => $v->code ? $v->name . ' · ' . $v->code : $v->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Bestehende Kontierung einer Transaktion.
     *
     * @return array<int, array{dimension_value_id:int, percentage:float}>
     */
    public function forTransaction(int $transactionId): array
    {
        if (!$this->available()) {
            return [];
        }

        return \Platform\Organization\Models\OrganizationDimensionLink::query()
            ->whereHas('definition', fn ($q) => $q->where('key', self::DIMENSION))
            ->where('linkable_type', self::CONTEXT_TYPE)
            ->where('linkable_id', $transactionId)
            ->get(['dimension_value_id', 'percentage'])
            ->map(fn ($l) => [
                'dimension_value_id' => (int) $l->dimension_value_id,
                'percentage' => (float) $l->percentage,
            ])
            ->values()
            ->all();
    }

    /**
     * Setzt die Kontierung einer Transaktion neu (Sync über den Org-Service):
     * bestehende cost-driver-Links entfernen, dann die übergebenen Zeilen anlegen.
     *
     * @param array<int, array{dimension_value_id:int|string, percentage:float|string}> $rows
     */
    public function syncForTransaction(int $transactionId, int $teamId, array $rows): void
    {
        if (!$this->available()) {
            return;
        }

        $service = app(\Platform\Organization\Services\DimensionLinkService::class);

        foreach ($this->forTransaction($transactionId) as $existing) {
            $service->unlink(self::DIMENSION, self::CONTEXT_TYPE, $transactionId, $existing['dimension_value_id']);
        }

        foreach ($rows as $row) {
            $valueId = (int) ($row['dimension_value_id'] ?? 0);
            $pct = (float) ($row['percentage'] ?? 0);

            if ($valueId <= 0 || $pct <= 0) {
                continue;
            }

            $service->link(self::DIMENSION, self::CONTEXT_TYPE, $transactionId, $valueId, [
                'percentage' => $pct,
                'team_id' => $teamId,
            ]);
        }
    }
}
