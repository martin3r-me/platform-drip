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
 * Spiegelt easybill-Belege in den lokalen `drip_invoices`-Mirror (Basis für die
 * Belege-View + Zahlungsabgleich) — BEIDE Richtungen:
 *   - Ausgangsrechnungen (`/documents`, direction=outgoing) → Bank-Eingänge
 *   - Eingangsbelege     (`/incoming-documents`, direction=incoming) → Bank-Abgänge
 *
 * Bewusst lose gekoppelt an die Integrations-Ebene — bricht nicht, wenn keine
 * easybill-Verbindung besteht.
 *
 * Der Re-Sync aktualisiert NUR die easybill-Felder; die drip-seitigen Match-
 * Felder (match_status, matched_transaction_id, …) bleiben erhalten.
 */
class InvoiceSyncService
{
    /** Geldrelevante Ausgangs-Belegtypen — Angebote/Lieferscheine/Recurring interessieren hier nicht. */
    private const RELEVANT_TYPES = ['INVOICE', 'STORNO', 'CREDIT'];

    /** Eingangsbeleg-Typen, die als Verbindlichkeit zählen (Gutschriften negativ, s. Folge-Ticket). */
    private const RELEVANT_INCOMING_TYPES = ['INVOICE', 'CREDIT'];

    private const MAX_PAGES = 50;

    public function __construct(
        protected IntegrationConnectionResolver $connectionResolver,
        protected EasybillApiService $api,
    ) {}

    /**
     * Spiegelt Ausgangs- UND Eingangsbelege eines Teams.
     *
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

        $out = $this->syncOutgoing($team, $api, $proxyUser);
        $in = $this->syncIncoming($team, $api, $proxyUser);

        return [
            'synced' => $out['synced'] + $in['synced'],
            'skipped' => $out['skipped'] + $in['skipped'],
        ];
    }

    /**
     * Ausgangsrechnungen aus `/documents` (direction=outgoing).
     *
     * @return array{synced:int, skipped:int}
     */
    protected function syncOutgoing(Team $team, EasybillApiService $api, User $proxyUser): array
    {
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
     * Eingangsbelege aus `/incoming-documents` (direction=incoming).
     * easybill liefert diese seit v1.100.0 read-only; IDs sind UUID-Strings.
     *
     * @return array{synced:int, skipped:int}
     */
    protected function syncIncoming(Team $team, EasybillApiService $api, User $proxyUser): array
    {
        $synced = 0;
        $skipped = 0;
        $page = 1;

        try {
            do {
                $resp = $api->listIncomingDocuments($proxyUser, [
                    'limit' => 100,
                    'page' => $page,
                ]);

                $items = $resp['items'] ?? [];
                foreach ($items as $doc) {
                    if ($this->upsertIncoming($team, $doc)) {
                        $synced++;
                    } else {
                        $skipped++;
                    }
                }

                $pages = (int) ($resp['pages'] ?? 1);
                $page++;
            } while ($page <= $pages && $page <= self::MAX_PAGES);
        } catch (EasybillApiException $e) {
            Log::error('InvoiceSyncService: Eingangsbeleg-Sync fehlgeschlagen', [
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
                'direction' => 'outgoing',
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

    /**
     * Legt einen Eingangsbeleg an bzw. aktualisiert die easybill-Felder. Gibt
     * false zurück (übersprungen), wenn der Beleg unfertig ist (OCR-Status nicht
     * DONE, kein geldrelevanter Typ oder ohne Bruttobetrag).
     *
     * Eigenheiten der `/incoming-documents`-Struktur:
     *   - `id` ist eine UUID (String) → external_ref, external_id bleibt null
     *   - Beträge sind bereits Integer-Cent
     *   - `number` = Lieferanten-Rechnungsnr. (external_number), denn genau die
     *     steht beim Bezahlen in UNSEREM Verwendungszweck → matchrelevant
     *   - der Lieferant belegt die customer_*-Felder (Name/IBAN)
     */
    protected function upsertIncoming(Team $team, array $doc): bool
    {
        $status = strtoupper((string) ($doc['status'] ?? ''));
        $type = strtoupper((string) ($doc['document_type'] ?? ''));
        $gross = $doc['total_gross_amount'] ?? null;

        if ($status !== 'DONE' || !in_array($type, self::RELEVANT_INCOMING_TYPES, true) || $gross === null) {
            return false;
        }

        $supplier = $doc['supplier'] ?? [];
        $number = $doc['external_number'] ?? $doc['document_number'] ?? null;

        DripInvoice::updateOrCreate(
            [
                'team_id' => $team->id,
                'provider' => 'easybill',
                'external_ref' => (string) $doc['id'],
            ],
            [
                'external_id' => null,
                'number' => $number,
                'type' => $type,
                'direction' => 'incoming',
                'external_status' => $doc['status'] ?? null,
                'is_draft' => false,
                'customer_external_id' => null,   // Supplier-ID ist UUID → in metadata
                'customer_name' => $supplier['name'] ?? null,
                'customer_iban' => $supplier['iban'] ?? null,
                'amount_gross_cents' => (int) $gross,
                'amount_net_cents' => isset($doc['total_net_amount']) ? (int) $doc['total_net_amount'] : null,
                'paid_amount_cents' => !empty($doc['is_paid']) ? (int) $gross : 0,
                'currency' => $doc['currency_code'] ?? 'EUR',
                'document_date' => $doc['issue_date'] ?? null,
                'due_date' => $doc['due_date'] ?? null,
                'external_paid_at' => null,
                'metadata' => [
                    'supplier' => $supplier,
                    'document_number' => $doc['document_number'] ?? null,
                    'external_number' => $doc['external_number'] ?? null,
                    'accounting_positions' => $doc['accounting_positions'] ?? [],
                ],
            ]
        );

        return true;
    }
}
