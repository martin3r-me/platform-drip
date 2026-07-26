<?php

namespace Platform\Drip\Services;

use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Drip\Models\BankTransaction;
use Platform\Integrations\Exceptions\MossApiException;
use Platform\Integrations\Services\IntegrationConnectionResolver;
use Platform\Integrations\Services\MossApiService;

/**
 * Zugriff auf die MOSS-Belege (Files) zu einer Ausgaben-Transaktion.
 *
 * Der Beleg-STATUS liegt bereits lokal vor (drip speichert das komplette
 * Expense-Objekt in transaction.metadata) — die Beleg-DATEI wird bei Bedarf
 * live über den MOSS-Connector geholt (searchFiles → downloadFile). Bewusst
 * lose gekoppelt: bricht nicht, wenn keine MOSS-Verbindung besteht.
 */
class MossReceiptService
{
    private const PREFIX = 'moss_';

    public function __construct(
        protected IntegrationConnectionResolver $connectionResolver,
        protected MossApiService $api,
    ) {}

    public function isMossTransaction(BankTransaction $tx): bool
    {
        return str_starts_with((string) $tx->transaction_id, self::PREFIX);
    }

    /** MOSS-Expense-UUID aus der transaction_id (moss_<uuid>). */
    public function expenseId(BankTransaction $tx): ?string
    {
        if (!$this->isMossTransaction($tx)) {
            return null;
        }

        return substr((string) $tx->transaction_id, strlen(self::PREFIX)) ?: null;
    }

    /** Beleg-Status aus dem gespeicherten Expense-Metadata (kein API-Call). */
    public function receiptStatus(BankTransaction $tx): ?string
    {
        $meta = $tx->metadata;

        return is_array($meta) ? ($meta['expenseMetadata']['receiptStatus'] ?? null) : null;
    }

    /** Gilt der Beleg fachlich als vorhanden? (alles außer „nicht erstellt"/leer). */
    public function hasReceipt(BankTransaction $tx): bool
    {
        $status = strtoupper((string) $this->receiptStatus($tx));

        return $status !== '' && !in_array($status, ['NOT_CREATED', 'MISSING', 'NONE'], true);
    }

    /**
     * Beleg-Dateien (Metadaten) zu einer MOSS-Transaktion.
     *
     * @return array<int, array{id:string, name?:string, size?:int}>
     */
    public function files(BankTransaction $tx): array
    {
        $expenseId = $this->expenseId($tx);
        if (!$expenseId) {
            return [];
        }

        [$api, $user] = $this->resolve((int) $tx->team_id);
        if (!$api) {
            return [];
        }

        try {
            $resp = $api->searchFilesByExpenses($user, [$expenseId]);

            return $resp['data'] ?? [];
        } catch (MossApiException $e) {
            return [];
        }
    }

    /**
     * Erste Beleg-Datei als Binary.
     *
     * @return array{mime:string, data_base64:string, size:int, filename:?string}|null
     */
    public function firstFileContent(BankTransaction $tx): ?array
    {
        $files = $this->files($tx);
        $fileId = $files[0]['id'] ?? null;
        if (!$fileId) {
            return null;
        }

        [$api, $user] = $this->resolve((int) $tx->team_id);
        if (!$api) {
            return null;
        }

        try {
            $binary = $api->downloadFile($user, (string) $fileId);
            $binary['filename'] = $binary['filename'] ?? ($files[0]['name'] ?? 'beleg');

            return $binary;
        } catch (MossApiException $e) {
            return null;
        }
    }

    /**
     * @return array{0:?MossApiService, 1:?User}
     */
    private function resolve(int $teamId): array
    {
        $team = Team::find($teamId);
        if (!$team) {
            return [null, null];
        }

        $connection = $this->connectionResolver->resolveForTeam('moss', $team);
        if (!$connection) {
            return [null, null];
        }

        $user = User::find($connection->owner_user_id);
        if (!$user) {
            return [null, null];
        }

        return [$this->api->forConnection($connection->id), $user];
    }
}
