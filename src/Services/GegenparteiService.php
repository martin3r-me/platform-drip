<?php

namespace Platform\Drip\Services;

/**
 * Löst die Gegenpartei einer Transaktion (die Umwelt-Seite: Absender bzw.
 * Empfänger) über ihre IBAN zu einer Organisations-Entity auf.
 *
 * Bewusst lose gekoppelt: drip besitzt KEIN eigenes IBAN→Entity-Mapping. Die
 * IBAN wird als `entity_external_id` (system=iban) am Org-Knoten geführt; hier
 * wird nur aufgelöst bzw. — bei "unbekannt" — die Zuordnung angelegt.
 * Bricht nicht, wenn das Organization-Modul fehlt.
 */
class GegenparteiService
{
    public const SYSTEM_IBAN = 'iban';

    public function available(): bool
    {
        return class_exists(\Platform\Organization\Models\OrganizationEntityExternalId::class)
            && class_exists(\Platform\Organization\Models\OrganizationEntity::class);
    }

    /**
     * Löst eine IBAN zur Org-Entity auf.
     *
     * @return array{id:int, name:string, code:?string}|null
     */
    public function resolveIban(?string $iban, int $teamId): ?array
    {
        if (!$this->available() || !$iban) {
            return null;
        }

        $entity = \Platform\Organization\Models\OrganizationEntityExternalId::resolveEntity(self::SYSTEM_IBAN, $iban, $teamId);

        return $entity ? ['id' => (int) $entity->id, 'name' => $entity->name, 'code' => $entity->code] : null;
    }

    /**
     * Ordnet eine IBAN einer Entity zu (idempotent — vorhandene Zuordnung wird
     * aktualisiert). Die IBAN gehört fachlich der Entity → am Knoten gespeichert.
     */
    public function mapIban(string $iban, int $entityId, int $teamId): void
    {
        if (!$this->available() || $iban === '') {
            return;
        }

        $model = \Platform\Organization\Models\OrganizationEntityExternalId::class;

        $existing = $model::where('system', self::SYSTEM_IBAN)
            ->where('value', $iban)
            ->where('team_id', $teamId)
            ->first();

        if ($existing) {
            $existing->update(['entity_id' => $entityId]);
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

    /** Entfernt die IBAN-Zuordnung. */
    public function unmapIban(string $iban, int $teamId): void
    {
        if (!$this->available() || $iban === '') {
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
