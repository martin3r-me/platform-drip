<?php

namespace Platform\Drip\Services;

/**
 * Gegenpartei einer Transaktion — die Umwelt-Seite (Absender bzw. Empfänger).
 *
 * Abgelegt als Link auf der **entity-Dimension** der Organisation: das ist der
 * EINE Ort für „wer" (dieselbe Dimension, an der z. B. Projekte an Entities
 * hängen). Damit taucht die Gegenpartei am Org-Knoten auf und rollt mit —
 * genau wie die Kontierung („wofür") auf der cost-driver-Dimension.
 *
 * IBAN und Name sind nur **Auflöser**, die die passende Entity finden — kein
 * zweiter Speicher. Die IBAN bleibt als Identifier an der Entity
 * (entity_external_ids), damit gleichartige Transaktionen künftig automatisch
 * aufgelöst werden können.
 *
 * Bewusst lose gekoppelt: bricht nicht, wenn das Organization-Modul fehlt.
 */
class GegenparteiService
{
    public const SYSTEM_IBAN = 'iban';
    public const DIMENSION = 'entity';
    public const CONTEXT_TYPE = 'drip_bank_transaction';

    public function available(): bool
    {
        return class_exists(\Platform\Organization\Services\DimensionLinkService::class)
            && class_exists(\Platform\Organization\Models\OrganizationDimensionDefinition::class)
            && class_exists(\Platform\Organization\Models\OrganizationDimensionValue::class)
            && class_exists(\Platform\Organization\Models\OrganizationDimensionLink::class)
            && class_exists(\Platform\Organization\Models\OrganizationEntity::class)
            && \Platform\Organization\Models\OrganizationDimensionDefinition::findByKey(self::DIMENSION) !== null;
    }

    /**
     * Aktuelle Gegenpartei einer Transaktion (entity-Link, genau eine).
     *
     * @return array{id:int, name:string, code:?string}|null
     */
    public function forTransaction(int $transactionId): ?array
    {
        if (!$this->available()) {
            return null;
        }

        $def = \Platform\Organization\Models\OrganizationDimensionDefinition::findByKey(self::DIMENSION);

        $link = \Platform\Organization\Models\OrganizationDimensionLink::query()
            ->where('dimension_definition_id', $def->id)
            ->where('linkable_type', self::CONTEXT_TYPE)
            ->where('linkable_id', $transactionId)
            ->with('value')
            ->first();

        if (!$link || !$link->value) {
            return null;
        }

        $entityId = $link->value->metadata['source_entity_id'] ?? null;

        return $entityId
            ? ['id' => (int) $entityId, 'name' => $link->value->name, 'code' => $link->value->code]
            : null;
    }

    /**
     * Setzt die Gegenpartei einer Transaktion (genau eine entity-Zuordnung).
     * Optional: lernt die IBAN als Identifier an der Entity, damit gleichartige
     * Transaktionen künftig automatisch aufgelöst werden.
     */
    public function setForTransaction(int $transactionId, int $entityId, int $teamId, ?string $learnIban = null): bool
    {
        if (!$this->available() || $entityId <= 0) {
            return false;
        }

        $def = \Platform\Organization\Models\OrganizationDimensionDefinition::findByKey(self::DIMENSION);

        $dimValue = \Platform\Organization\Models\OrganizationDimensionValue::query()
            ->where('dimension_definition_id', $def->id)
            ->where('metadata->source_entity_id', $entityId)
            ->first();

        if (!$dimValue) {
            return false;
        }

        // Genau eine Gegenpartei: bestehende entity-Links dieser Transaktion lösen.
        $this->clearForTransaction($transactionId);

        app(\Platform\Organization\Services\DimensionLinkService::class)
            ->link(self::DIMENSION, self::CONTEXT_TYPE, $transactionId, (int) $dimValue->id, [
                'team_id' => $teamId,
            ]);

        if ($learnIban) {
            $this->mapIban($learnIban, $entityId, $teamId);
        }

        return true;
    }

    /** Entfernt die Gegenpartei-Zuordnung (alle entity-Links dieser Transaktion). */
    public function clearForTransaction(int $transactionId): void
    {
        if (!$this->available()) {
            return;
        }

        $def = \Platform\Organization\Models\OrganizationDimensionDefinition::findByKey(self::DIMENSION);
        $service = app(\Platform\Organization\Services\DimensionLinkService::class);

        $links = \Platform\Organization\Models\OrganizationDimensionLink::query()
            ->where('dimension_definition_id', $def->id)
            ->where('linkable_type', self::CONTEXT_TYPE)
            ->where('linkable_id', $transactionId)
            ->get();

        foreach ($links as $link) {
            $service->unlink(self::DIMENSION, self::CONTEXT_TYPE, $transactionId, (int) $link->dimension_value_id);
        }
    }

    /**
     * Löst eine IBAN zu einer Entity auf — Auflöser für Vorschlag/Auto-Set,
     * KEINE Speicherung der Zuordnung (die lebt auf der entity-Dimension).
     *
     * @return array{id:int, name:string, code:?string}|null
     */
    public function resolveIban(?string $iban, int $teamId): ?array
    {
        if (!$this->available() || !$iban
            || !class_exists(\Platform\Organization\Models\OrganizationEntityExternalId::class)) {
            return null;
        }

        $entity = \Platform\Organization\Models\OrganizationEntityExternalId::resolveEntity(self::SYSTEM_IBAN, $iban, $teamId);

        return $entity ? ['id' => (int) $entity->id, 'name' => $entity->name, 'code' => $entity->code] : null;
    }

    /**
     * Registriert eine IBAN als Identifier an einer Entity (idempotent). Nur der
     * Identifier — die Zuordnung pro Transaktion läuft über setForTransaction.
     */
    public function mapIban(string $iban, int $entityId, int $teamId): void
    {
        if (!$this->available() || $iban === ''
            || !class_exists(\Platform\Organization\Models\OrganizationEntityExternalId::class)) {
            return;
        }

        $model = \Platform\Organization\Models\OrganizationEntityExternalId::class;

        $existing = $model::where('system', self::SYSTEM_IBAN)
            ->where('value', $iban)
            ->where('team_id', $teamId)
            ->first();

        if ($existing) {
            if ((int) $existing->entity_id !== $entityId) {
                $existing->update(['entity_id' => $entityId]);
            }
            return;
        }

        $model::create([
            'entity_id' => $entityId,
            'system' => self::SYSTEM_IBAN,
            'value' => $iban,
            'label' => 'IBAN',
            'team_id' => $teamId,
        ]);
    }

    /** Entfernt einen IBAN-Identifier von der Entity. */
    public function unmapIban(string $iban, int $teamId): void
    {
        if (!$this->available() || $iban === ''
            || !class_exists(\Platform\Organization\Models\OrganizationEntityExternalId::class)) {
            return;
        }

        \Platform\Organization\Models\OrganizationEntityExternalId::where('system', self::SYSTEM_IBAN)
            ->where('value', $iban)
            ->where('team_id', $teamId)
            ->delete();
    }

    /**
     * Auswählbare Entities für die Zuordnung (alle aktiven, live aus der Org).
     *
     * @return array<int, array{value:int, label:string}>
     */
    public function entityOptions(int $teamId): array
    {
        if (!$this->available()) {
            return [];
        }

        return \Platform\Organization\Models\OrganizationEntity::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($e) => [
                'value' => (int) $e->id,
                'label' => $e->code ? $e->name . ' · ' . $e->code : $e->name,
            ])
            ->values()
            ->all();
    }
}
