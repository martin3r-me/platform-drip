<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Drip\Models\DripInvoice;
use Platform\Integrations\Exceptions\EasybillApiException;
use Platform\Integrations\Services\EasybillApiService;
use Platform\Integrations\Services\IntegrationConnectionResolver;

/**
 * Spiegelt Ausgangsrechnungen aus easybill in den lokalen `drip_invoices`-Mirror
 * (Basis für Belege-View + Zahlungsabgleich). Bewusst lose gekoppelt an die
 * Integrations-Ebene — bricht nicht, wenn keine easybill-Verbindung besteht.
 *
 * Der Re-Sync aktualisiert NUR die easybill-Felder; die drip-seitigen Match-
 * Felder (match_status, matched_transaction_id, …) bleiben erhalten.
 */
class InvoiceSyncService
{
    /** Geldrelevante Belegtypen — Angebote/Lieferscheine/Recurring interessieren hier nicht. */
    private const RELEVANT_TYPES = ['INVOICE', 'STORNO', 'CREDIT'];

    private const MAX_PAGES = 50;

    public function __construct(
        protected IntegrationConnectionResolver $connectionResolver,
        protected EasybillApiService $api,
    ) {}

    /**
     * @return array{synced:int, skipped:int}
     */
    public function syncForTeam(Team $team): array
    {
        $connection = $this->connectionResolver->resolveForTeam('easybill', $team);
        if (!$connection) {
            Log::debug('InvoiceSyncService: Keine easybill-Verbindung für Team', ['team_id' => $team->id]);
            return ['synced' => 0, 'skipped' => 0];
        }

        $proxyUser = User::find($connection->owner_user_id);
        if (!$proxyUser) {
            Log::warning('InvoiceSyncService: Connection-Owner nicht gefunden', ['team_id' => $team->id]);
            return ['synced' => 0, 'skipped' => 0];
        }

        $api = $this->api->forConnection($connection->id);

        $synced = 0;
        $skipped = 0;
        $page = 1;

        try {
            do {
                $resp = $api->listDocuments($proxyUser, [
                    'type' => 'INVOICE',
                    'limit' => 100,
                    'page' => $page,
                ]);

                $items = $resp['items'] ?? [];
                foreach ($items as $doc) {
                    if ($this->upsert($team, $doc)) {
                        $synced++;
                    } else {
                        $skipped++;
                    }
                }

                $pages = (int) ($resp['pages'] ?? 1);
                $page++;
            } while ($page <= $pages && $page <= self::MAX_PAGES);
        } catch (EasybillApiException $e) {
            Log::error('InvoiceSyncService: Rechnungs-Sync fehlgeschlagen', [
                'team_id' => $team->id,
                'status' => $e->getHttpStatusCode(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return ['synced' => $synced, 'skipped' => $skipped];
    }

    /**
     * Legt einen Beleg an bzw. aktualisiert die easybill-Felder. Gibt false
     * zurück, wenn der Typ nicht geldrelevant ist (übersprungen).
     */
    protected function upsert(Team $team, array $doc): bool
    {
        $type = strtoupper((string) ($doc['type'] ?? 'INVOICE'));
        if (!in_array($type, self::RELEVANT_TYPES, true)) {
            return false;
        }

        $snapshot = $doc['customer_snapshot'] ?? [];

        DripInvoice::updateOrCreate(
            [
                'team_id' => $team->id,
                'provider' => 'easybill',
                'external_id' => (int) $doc['id'],
            ],
            [
                'number' => $doc['number'] ?? null,
                'type' => $type,
                'external_status' => $doc['status'] ?? null,
                'is_draft' => (bool) ($doc['is_draft'] ?? false),
                'customer_external_id' => $doc['customer_id'] ?? null,
                'customer_name' => $snapshot['company_name'] ?? $snapshot['display_name'] ?? null,
                'customer_iban' => $snapshot['bank_iban'] ?? null,
                'amount_gross_cents' => (int) ($doc['amount'] ?? 0),
                'amount_net_cents' => isset($doc['amount_net']) ? (int) $doc['amount_net'] : null,
                'paid_amount_cents' => (int) ($doc['paid_amount'] ?? 0),
                'currency' => $doc['currency'] ?? 'EUR',
                'document_date' => $doc['document_date'] ?? null,
                'due_date' => $doc['due_date'] ?? null,
                'external_paid_at' => $doc['paid_at'] ?? null,
            ]
        );

        return true;
    }
}
