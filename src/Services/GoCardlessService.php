<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Core\Support\FieldHasher;
use Platform\Drip\Models\{GoCardlessToken, Institution, BankAccount, BankAccountBalance, BankTransaction, Requisition};
use Carbon\Carbon;

class GoCardlessService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

    protected int $teamId;

    public function __construct(int $teamId)
    {
        $this->clientId = config('services.gocardless.client_id');
        $this->clientSecret = config('services.gocardless.client_secret');
        $this->teamId = $teamId;
        
        // Debug: Log constructor values
        Log::info('GoCardlessService Constructor', [
            'teamId' => $this->teamId,
            'clientId' => $this->clientId ? 'SET' : 'MISSING',
            'clientSecret' => $this->clientSecret ? 'SET' : 'MISSING',
            'baseUrl' => $this->baseUrl
        ]);
    }

    public function getAccessToken(): ?string
    {
        Log::info('GoCardlessService: Getting access token', [
            'teamId' => $this->teamId
        ]);
        
        $token = GoCardlessToken::where('team_id', $this->teamId)
            ->orderByDesc('created_at')
            ->first();

        if ($token && Carbon::now()->lt($token->expires_at)) {
            Log::info('GoCardlessService: Using existing token', [
                'expires_at' => $token->expires_at,
                'is_expired' => Carbon::now()->gte($token->expires_at)
            ]);
            return $token->access_token;
        }

        Log::info('GoCardlessService: Requesting new token');
        return $this->requestNewToken();
    }

    protected function requestNewToken(): ?string
    {
        Log::info('GoCardlessService: Requesting new token', [
            'url' => "{$this->baseUrl}/token/new/",
            'clientId' => $this->clientId ? 'SET' : 'MISSING',
            'clientSecret' => $this->clientSecret ? 'SET' : 'MISSING'
        ]);
        
        $response = Http::post("{$this->baseUrl}/token/new/", [
            'secret_id' => $this->clientId,
            'secret_key' => $this->clientSecret,
        ]);

        Log::info('GoCardlessService: Token response', [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => $response->body()
        ]);

        if (!$response->successful()) {
            Log::error('GoCardlessService: Token request failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return null;
        }

        $data = $response->json();
        Log::info('GoCardlessService: Token data', [
            'access' => $data['access'] ?? 'MISSING',
            'refresh' => $data['refresh'] ?? 'MISSING',
            'access_expires' => $data['access_expires'] ?? 'MISSING'
        ]);

        GoCardlessToken::create([
            'team_id' => $this->teamId,
            'access_token' => $data['access'],
            'refresh_token' => $data['refresh'] ?? null,
            'expires_at' => now()->addSeconds($data['access_expires']),
        ]);

        return $data['access'];
    }

    protected function createEndUserAgreement(string $institutionId): ?string
    {
        Log::info('GoCardlessService: Creating end user agreement', [
            'institutionId' => $institutionId
        ]);

        $token = $this->getAccessToken();
        if (!$token) return null;

            // Bank-spezifische Limits ermitteln
            $institution = Institution::where('external_id', $institutionId)->first();
            $maxHistoricalDays = $institution?->transaction_total_days ?? 360; // Fallback: 360 Tage
            $maxAccessDays = $institution?->max_access_valid_for_days ?? 180; // Fallback: 180 Tage

            // IMMER mit den MAX-Zahlen anfragen - das Maximum was die Bank hergibt!
            $historicalDays = $maxHistoricalDays; // Das Maximum was die Bank erlaubt
            $accessDays = $maxAccessDays; // Das Maximum was die Bank erlaubt

        $agreementData = [
            'institution_id' => $institutionId,
            'max_historical_days' => $historicalDays,
            'access_valid_for_days' => $accessDays,
            'access_scope' => ['balances', 'details', 'transactions']
        ];

            Log::info('GoCardlessService: Creating end user agreement with MAX values', [
                'institutionId' => $institutionId,
                'institution' => $institution?->name ?? 'Unknown',
                'maxHistoricalDays' => $maxHistoricalDays,
                'maxAccessDays' => $maxAccessDays,
                'requestingHistoricalDays' => $historicalDays, // Das Maximum was die Bank erlaubt
                'requestingAccessDays' => $accessDays, // Das Maximum was die Bank erlaubt
                'agreementData' => $agreementData
            ]);

        $response = Http::withToken($token)->post("{$this->baseUrl}/agreements/enduser/", $agreementData);

        Log::info('GoCardlessService: End user agreement response', [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => $response->body()
        ]);

        if ($response->successful()) {
            $data = $response->json();
            Log::info('GoCardlessService: Agreement created', [
                'id' => $data['id'] ?? 'MISSING',
                'max_historical_days' => $data['max_historical_days'] ?? 'MISSING',
                'access_valid_for_days' => $data['access_valid_for_days'] ?? 'MISSING'
            ]);
            return $data['id'] ?? null;
        }

        Log::error('GoCardlessService: End user agreement failed', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);
        return null;
    }

    public function getInstitutions(string $country = 'DE'): array
    {
        $token = $this->getAccessToken();
        if (!$token) return [];

        $response = Http::withToken($token)->get("{$this->baseUrl}/institutions/", [
            'country' => $country,
        ]);

        if ($response->successful()) {
            $institutions = $response->json();

            foreach ($institutions as $inst) {
                Institution::updateOrCreate(
                    ['external_id' => $inst['id']],
                    [
                        'name' => $inst['name'],
                        'bic' => $inst['bic'] ?? null,
                        'logo' => $inst['logo'] ?? null,
                        'country' => $inst['countries'][0] ?? null,
                        'transaction_total_days' => $inst['transaction_total_days'] ?? null,
                        'max_access_valid_for_days' => $inst['max_access_valid_for_days'] ?? null,
                    ]
                );
            }

            return $institutions;
        }

        Log::error('Fehler beim Abrufen der Institutionen', ['body' => $response->body()]);
        return [];
    }

    public function createRequisition(string $institutionId, string $redirectUrl): ?string
    {
        Log::info('GoCardlessService: Creating requisition', [
            'institutionId' => $institutionId,
            'redirectUrl' => $redirectUrl,
            'teamId' => $this->teamId
        ]);

        // Prüfen ob bereits eine aktive Requisition für diese Institution existiert
        $existingRequisition = Requisition::where('team_id', $this->teamId)
            ->where('institution_id', Institution::where('external_id', $institutionId)->first()?->id)
            ->where('status', 'CR') // Created/Ready
            ->whereNull('linked_at') // Noch nicht verknüpft
            ->where('created_at', '>', now()->subDays(7)) // Nicht älter als 7 Tage
            ->first();

        if ($existingRequisition) {
            Log::info('GoCardlessService: Using existing requisition', [
                'requisitionId' => $existingRequisition->external_id,
                'reference' => $existingRequisition->reference
            ]);
            return $existingRequisition->redirect;
        }
        
        $token = $this->getAccessToken();
        if (!$token) {
            Log::error('GoCardlessService: No access token available');
            return null;
        }

        // Step 1: Create End User Agreement with extended terms
        $agreementId = $this->createEndUserAgreement($institutionId);
        if (!$agreementId) {
            Log::error('GoCardlessService: Failed to create end user agreement');
            return null;
        }

        $reference = uniqid('ref_');
        Log::info('GoCardlessService: Generated reference', ['reference' => $reference]);

        $response = Http::withToken($token)->post("{$this->baseUrl}/requisitions/", [
            'redirect' => $redirectUrl,
            'institution_id' => $institutionId,
            'reference' => $reference,
            'user_language' => 'DE',
            'agreement' => $agreementId, // Link to our custom agreement
        ]);

        if ($response->successful()) {
            $data = $response->json();
            
            // Debug: Log the response to see what we get
            Log::info('GoCardless Requisition Response', [
                'data' => $data,
                'status' => $data['status'] ?? 'no status',
                'status_short' => $data['status']['short'] ?? 'no status.short'
            ]);

            try {
                $req = Requisition::create([
                    'external_id' => $data['id'],
                    'reference' => $reference,
                    'institution_id' => Institution::where('external_id', $institutionId)->first()?->id,
                    'team_id' => $this->teamId,
                    'status' => $data['status']['short'] ?? 'pending',
                    'redirect' => $data['redirect'] ?? $redirectUrl,
                ]);

                Log::info('GoCardless Requisition Created', [
                    'id' => $req->id,
                    'reference' => $reference,
                    'reference_hash' => $req->reference_hash ?? 'NULL',
                    'exists' => $req->exists,
                ]);
            } catch (\Throwable $e) {
                Log::error('GoCardless Requisition CREATE FAILED', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

            return $data['link'];
        }

        Log::error('Fehler bei Requisition-Erstellung', ['body' => $response->body()]);
        return null;
    }

    public function getAccountsFromRequisitionByRef(string $reference): array
    {
        $hash = FieldHasher::hmacSha256($reference, (string) $this->teamId);

        $allReqs = Requisition::where('team_id', $this->teamId)
            ->select('id', 'reference_hash', 'status', 'created_at')
            ->get();

        $requisition = Requisition::where('reference_hash', $hash)->first();

        if (!$requisition) {
            throw new \Exception(
                "Requisition nicht gefunden.\n" .
                "Reference: {$reference}\n" .
                "Team: {$this->teamId}\n" .
                "Berechneter Hash: {$hash}\n" .
                "Requisitions im Team: {$allReqs->count()}\n" .
                "Gespeicherte Hashes: " . $allReqs->pluck('reference_hash')->implode(', ')
            );
        }

        $token = $this->getAccessToken();
        if (!$token) return [];

        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/requisitions/{$requisition->external_id}/");

        if (!$response->successful()) {
            Log::error('Fehler bei Abruf der Requisition', ['body' => $response->body()]);
            throw new \Exception('Requisition konnte nicht geladen werden.');
        }

        $data = $response->json();

        $rateLimitHit = false;
        foreach ($data['accounts'] ?? [] as $accountId) {
            if ($rateLimitHit) {
                // Wenn Rate Limit erreicht, nur noch minimale Accounts erstellen
                $this->createMinimalAccount($accountId, $data['institution_id'] ?? null);
                continue;
            }
            
            try {
                $this->storeAccountDetails($accountId);
                $this->storeAccountBalances($accountId);
                $this->storeAccountTransactions($accountId, $requisition);
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'Rate limit')) {
                    Log::warning('GoCardlessService: Rate limit hit during account processing', [
                        'accountId' => $accountId,
                        'error' => $e->getMessage()
                    ]);
                    $rateLimitHit = true;
                    $this->createMinimalAccount($accountId, $data['institution_id'] ?? null);
                } else {
                    throw $e;
                }
            }
        }

        // Institution-spezifische Zugriffsdauer ermitteln
        $institution = Institution::where('external_id', $data['institution_id'] ?? null)->first();
        $accessDays = $institution?->max_access_valid_for_days ?? 180; // Fallback: 180 Tage
        
        $requisition->update([
            'accounts' => $data['accounts'] ?? [],
            'status' => $data['status'] ?? null,
            'linked_at' => now(),
            'access_expires_at' => now()->addDays($accessDays),
        ]);

        return $data['accounts'] ?? [];
    }

    /**
     * Verlängert den Zugriff auf eine abgelaufene Bankverbindung
     */
    public function renewAccess(string $institutionId): ?string
    {
        Log::info('GoCardlessService: Renewing access', [
            'institutionId' => $institutionId,
            'teamId' => $this->teamId
        ]);

        // Neue Agreement mit gleichen Parametern erstellen
        $agreementId = $this->createEndUserAgreement($institutionId);
        if (!$agreementId) {
            Log::error('GoCardlessService: Failed to create renewal agreement');
            return null;
        }

        // Neue Requisition für Verlängerung erstellen
        $redirectUrl = route('drip.banks.callback');
        return $this->createRequisition($institutionId, $redirectUrl);
    }

    /**
     * Prüft ob eine Bankverbindung bald abläuft (30 Tage vorher)
     */
    public function getExpiringConnections(): array
    {
        $expiringDate = now()->addDays(30);
        
        return Requisition::where('team_id', $this->teamId)
            ->where('access_expires_at', '<=', $expiringDate)
            ->where('access_expires_at', '>', now())
            ->whereNotNull('linked_at')
            ->with('institution')
            ->get()
            ->toArray();
    }

    /**
     * Löscht eine Requisition bei GoCardless und lokal
     */
    public function deleteRequisition(string $requisitionId): bool
    {
        Log::info('GoCardlessService: Deleting requisition', [
            'requisitionId' => $requisitionId,
            'teamId' => $this->teamId
        ]);

        $token = $this->getAccessToken();
        if (!$token) {
            Log::error('GoCardlessService: No access token available');
            return false;
        }

        // DELETE /api/v2/requisitions/{id}/
        $response = Http::withToken($token)
            ->delete("{$this->baseUrl}/requisitions/{$requisitionId}/");

        Log::info('GoCardlessService: Delete response', [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => $response->body()
        ]);

        if ($response->successful()) {
            // Lokale Requisition als gelöscht markieren
            $updated = Requisition::where('external_id', $requisitionId)
                ->where('team_id', $this->teamId)
                ->update(['deleted_at' => now()]);
            
            Log::info('GoCardlessService: Requisition deleted successfully', [
                'requisitionId' => $requisitionId,
                'updated' => $updated,
                'teamId' => $this->teamId
            ]);
            
            return true;
        }

        Log::error('GoCardlessService: Failed to delete requisition', [
            'requisitionId' => $requisitionId,
            'status' => $response->status(),
            'body' => $response->body()
        ]);

        return false;
    }

    /**
     * Bereinigt abgelaufene Requisitions (für Billing-Optimierung)
     */
    public function cleanupExpiredRequisitions(): array
    {
        Log::info('GoCardlessService: Cleaning up expired requisitions', [
            'teamId' => $this->teamId
        ]);

        $results = [
            'deleted' => 0,
            'errors' => []
        ];

        // Abgelaufene Requisitions finden
        $expiredRequisitions = Requisition::where('team_id', $this->teamId)
            ->where('access_expires_at', '<', now())
            ->whereNotNull('linked_at')
            ->whereNull('deleted_at')
            ->get();

        foreach ($expiredRequisitions as $requisition) {
            try {
                if ($this->deleteRequisition($requisition->external_id)) {
                    $results['deleted']++;
                    Log::info('GoCardlessService: Deleted expired requisition', [
                        'external_id' => $requisition->external_id,
                        'institution' => $requisition->institution->name ?? 'Unknown'
                    ]);
                }
            } catch (\Exception $e) {
                $results['errors'][] = "Requisition {$requisition->external_id}: " . $e->getMessage();
                Log::error('GoCardlessService: Failed to delete requisition', [
                    'external_id' => $requisition->external_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('GoCardlessService: Cleanup completed', $results);
        return $results;
    }

    /**
     * Gibt Billing-Übersicht für ein Team zurück
     */
    public function getBillingOverview(): array
    {
        $activeRequisitions = Requisition::where('team_id', $this->teamId)
            ->whereNotNull('linked_at')
            ->where('access_expires_at', '>', now())
            ->whereNull('deleted_at')
            ->with('institution')
            ->get();

        $expiredRequisitions = Requisition::where('team_id', $this->teamId)
            ->whereNotNull('linked_at')
            ->where('access_expires_at', '<', now())
            ->whereNull('deleted_at')
            ->with('institution')
            ->get();

        return [
            'active_count' => $activeRequisitions->count(),
            'expired_count' => $expiredRequisitions->count(),
            'active_requisitions' => $activeRequisitions->map(function ($req) {
                return [
                    'id' => $req->external_id,
                    'institution' => $req->institution->name ?? 'Unknown',
                    'expires_at' => $req->access_expires_at?->format('Y-m-d H:i:s'),
                    'accounts_count' => count($req->accounts ?? [])
                ];
            }),
            'expired_requisitions' => $expiredRequisitions->map(function ($req) {
                return [
                    'id' => $req->external_id,
                    'institution' => $req->institution->name ?? 'Unknown',
                    'expired_at' => $req->access_expires_at?->format('Y-m-d H:i:s'),
                    'accounts_count' => count($req->accounts ?? [])
                ];
            })
        ];
    }

    /**
     * Aktualisiert alle Bankdaten für ein Team
     * Lädt alle Transaktionen der letzten 48 Stunden
     */
    public function updateAllBankData(bool $skipDetails = false): array
    {
        Log::info('GoCardlessService: Updating all bank data', [
            'teamId' => $this->teamId,
            'skipDetails' => $skipDetails
        ]);

        $results = [
            'accounts_updated' => 0,
            'balances_updated' => 0,
            'transactions_updated' => 0,
            'errors' => []
        ];

        // Alle aktiven Bankverbindungen finden
        $requisitions = Requisition::where('team_id', $this->teamId)
            ->whereNotNull('linked_at')
            ->where('access_expires_at', '>', now())
            ->get();

        foreach ($requisitions as $requisition) {
            $accounts = $requisition->accounts ?? [];
            
            foreach ($accounts as $accountId) {
                try {
                    // Account-Details nur wenn nicht übersprungen
                    if (!$skipDetails) {
                        $this->storeAccountDetails($accountId);
                        $results['accounts_updated']++;
                    }
                    
                    // Salden aktualisieren
                    $this->storeAccountBalances($accountId);
                    $results['balances_updated']++;

                    // Transaktionen aktualisieren
                    $this->updateAccountTransactions($accountId);
                    $results['transactions_updated']++;

                } catch (\Exception $e) {
                    $results['errors'][] = "Account {$accountId}: " . $e->getMessage();
                    Log::error('GoCardlessService: Update error', [
                        'accountId' => $accountId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        Log::info('GoCardlessService: Update completed', $results);
        return $results;
    }

    /**
     * Aktualisiert Transaktionen für ein Konto (echtes Delta)
     */
    protected function updateAccountTransactions(string $accountId): void
    {
        $token = $this->getAccessToken();
        if (!$token) return;

        $account = BankAccount::where('external_id', $accountId)->first();
        if (!$account) return;

        // Intelligente Datums-Logik (accounts ist verschlüsselt, daher In-Memory-Filter)
        $requisition = Requisition::where('team_id', $this->teamId)
            ->whereNotNull('linked_at')
            ->get()
            ->first(fn ($r) => in_array($accountId, $r->accounts ?? []));
            
        // Erste Synchronisation: 90 Tage zurück
        // Delta Updates: Nur seit letztem Sync
        $isFirstSync = !$requisition?->last_sync_at;
        $dateFrom = $isFirstSync 
            ? now()->subDays(90)->format('Y-m-d')  // Erste Sync: 90 Tage
            : ($requisition->last_sync_at ?? now()->subDays(7))->format('Y-m-d');  // Delta: seit letztem Sync
        
        Log::info('GoCardlessService: Transaction sync', [
            'accountId' => $accountId,
            'dateFrom' => $dateFrom,
            'isFirstSync' => $isFirstSync,
            'lastSync' => $requisition?->last_sync_at?->format('Y-m-d H:i:s') ?? 'Never'
        ]);
        
        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/accounts/{$accountId}/transactions/", [
                'date_from' => $dateFrom
            ]);

        if ($response->successful()) {
            $account = BankAccount::where('external_id', $accountId)->first();
            if (!$account) return;

            $transactions = $response->json()['transactions']['booked'] ?? [];

            foreach ($transactions as $tx) {
                $amount = (float)($tx['transactionAmount']['amount'] ?? '0');
                $direction = $amount >= 0 ? 'credit' : 'debit';

                $debtorName = $tx['debtorName'] ?? null;
                $creditorName = $tx['creditorName'] ?? null;
                $debtorIban = $tx['debtorAccount']['iban'] ?? null;
                $creditorIban = $tx['creditorAccount']['iban'] ?? null;
                $debtorAgent = $tx['debtorAgent'] ?? null;
                $creditorAgent = $tx['creditorAgent'] ?? null;
                $remittance = $tx['remittanceInformationUnstructured'] ?? null;
                $additionalInfo = $tx['additionalInformation'] ?? null;

                $parsed = $this->parseAdditionalInformation($additionalInfo);

                if (!$debtorAgent && !$creditorAgent && $parsed['bic']) {
                    if ($direction === 'debit') {
                        $creditorAgent = $parsed['bic'];
                    } else {
                        $debtorAgent = $parsed['bic'];
                    }
                }
                if (!$creditorIban && !$debtorIban && $parsed['iban']) {
                    if ($direction === 'debit') {
                        $creditorIban = $parsed['iban'];
                    } else {
                        $debtorIban = $parsed['iban'];
                    }
                }

                $counterpartyName = $direction === 'debit'
                    ? ($creditorName ?? $parsed['name'] ?? $debtorName)
                    : ($debtorName ?? $parsed['name'] ?? $creditorName);
                $counterpartyIban = $direction === 'debit'
                    ? ($creditorIban ?? $debtorIban)
                    : ($debtorIban ?? $creditorIban);

                BankTransaction::updateOrCreate(
                    ['transaction_id' => $tx['transactionId'] ?? uniqid('tx_')],
                    [
                        'bank_account_id' => $account->id,
                        'booked_at' => $tx['bookingDate'] ?? now()->toDateString(),
                        'booking_date' => $tx['bookingDate'] ?? null,
                        'booking_date_time' => $tx['bookingDateTime'] ?? null,
                        'value_date' => $tx['valueDate'] ?? null,
                        'value_date_time' => $tx['valueDateTime'] ?? null,
                        'amount' => $tx['transactionAmount']['amount'] ?? '0',
                        'currency' => $tx['transactionAmount']['currency'] ?? null,
                        'direction' => $direction,
                        'remittance_information' => $remittance,
                        'remittance_information_structured' => $tx['remittanceInformationStructured'] ?? null,
                        'remittance_information_unstructured' => $tx['remittanceInformationUnstructured'] ?? null,
                        'debtor_name' => $debtorName,
                        'creditor_name' => $creditorName,
                        'debtor_account_iban' => $debtorIban,
                        'creditor_account_iban' => $creditorIban,
                        'debtor_agent' => $debtorAgent,
                        'creditor_agent' => $creditorAgent,
                        'counterparty_name' => $counterpartyName,
                        'counterparty_iban' => $counterpartyIban,
                        'reference' => $remittance ?? $parsed['purpose'] ?? null,
                        'internal_transaction_id' => $tx['internalTransactionId'] ?? null,
                        'end_to_end_id' => $tx['endToEndId'] ?? null,
                        'mandate_id' => $tx['mandateId'] ?? null,
                        'creditor_id' => $tx['creditorId'] ?? null,
                        'additional_information' => $additionalInfo,
                        'team_id' => $this->teamId,
                    ]
                );
            }
        }
    }

    protected function storeAccountDetails(string $accountId): void
    {
        // Prüfen ob Account-Details bereits importiert wurden (einmalig)
        $account = BankAccount::where('external_id', $accountId)->first();
        if ($account && $account->last_details_synced_at) {
            Log::info('GoCardlessService: Skipping account details (already imported)', [
                'accountId' => $accountId,
                'lastSynced' => $account->last_details_synced_at
            ]);
            return;
        }

        $token = $this->getAccessToken();
        if (!$token) return;

        $response = Http::withToken($token)->get("{$this->baseUrl}/accounts/{$accountId}/details/");

        if ($response->successful()) {
            $account = $response->json()['account'] ?? [];
            
            Log::info('GoCardlessService: Account details from API', [
                'accountId' => $accountId,
                'account' => $account,
                'name' => $account['name'] ?? 'MISSING',
                'iban' => $account['iban'] ?? 'MISSING'
            ]);

            $bankAccount = BankAccount::updateOrCreate(
                ['external_id' => $accountId],
                [
                    'team_id' => $this->teamId,
                    'iban' => $account['iban'] ?? null,
                    'bban' => $account['bban'] ?? null,
                    'currency' => $account['currency'] ?? null,
                    'name' => $account['name'] ?? ($account['iban'] ? 'Konto ' . substr($account['iban'], -4) : 'Unbekanntes Konto'), // Intelligenter Fallback
                    'product' => $account['product'] ?? null,
                    'last_details_synced_at' => now(),
                ]
            );
            
            Log::info('GoCardlessService: Account created/updated', [
                'accountId' => $accountId,
                'bankAccountId' => $bankAccount->id,
                'name' => $bankAccount->name
            ]);
        } else {
            Log::error('GoCardlessService: Failed to get account details', [
                'accountId' => $accountId,
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            
            // Bei Rate Limit: Exception werfen für bessere Behandlung
            if ($response->status() === 429) {
                Log::warning('GoCardlessService: Rate limit hit in storeAccountDetails', [
                    'accountId' => $accountId,
                    'retry_after' => '82794 seconds (23+ hours)'
                ]);
                
                throw new \Exception('Rate limit exceeded (429)');
            }
        }
    }

    protected function createMinimalAccount(string $accountId, ?string $institutionId): void
    {
        Log::info('GoCardlessService: Creating minimal account due to rate limit', [
            'accountId' => $accountId,
            'institutionId' => $institutionId
        ]);
        
        BankAccount::updateOrCreate(
            ['external_id' => $accountId],
            [
                'team_id' => $this->teamId,
                'user_id' => auth()->id(),
                'institution_id' => Institution::where('external_id', $institutionId)->first()?->id,
                'name' => 'Konto (Rate Limit)',
                'currency' => 'EUR',
            ]
        );
    }

    protected function storeAccountBalances(string $accountId): void
    {
        $token = $this->getAccessToken();
        if (!$token) return;

        $response = Http::withToken($token)->get("{$this->baseUrl}/accounts/{$accountId}/balances/");

        if ($response->successful()) {
            $account = BankAccount::where('external_id', $accountId)->first();

            foreach ($response->json()['balances'] ?? [] as $balance) {
                BankAccountBalance::updateOrCreate(
                    [
                        'bank_account_id' => $account->id,
                        'balance_type' => $balance['balanceType'] ?? 'booked',
                    ],
                    [
                        'team_id' => $this->teamId,
                        'amount' => $balance['balanceAmount']['amount'] ?? '0',
                        'currency' => $balance['balanceAmount']['currency'] ?? 'EUR',
                        'retrieved_at' => now(),
                        // Legacy fields
                        'as_of_date' => $balance['referenceDate'] ?? now()->toDateString(),
                        'balance' => $balance['balanceAmount']['amount'] ?? '0',
                    ]
                );
            }
        }
    }

    public function storeAccountTransactions(string $accountId, ?Requisition $requisition = null): void
    {
        // Prüfen ob Transaktionen in den letzten 24h bereits abgerufen wurden
        $account = BankAccount::where('external_id', $accountId)->first();
        if ($account && $account->last_transactions_synced_at && $account->last_transactions_synced_at->isAfter(now()->subDay())) {
            Log::info('GoCardlessService: Skipping transactions (synced recently)', [
                'accountId' => $accountId,
                'lastSynced' => $account->last_transactions_synced_at
            ]);
            return;
        }

        $token = $this->getAccessToken();
        if (!$token) return;

        // Delta-Update: Nur Transaktionen seit letztem Sync + 1 Tag Puffer
        $dateFrom = null;
        if ($account && $account->last_transactions_synced_at) {
            $dateFrom = $account->last_transactions_synced_at->subDay()->format('Y-m-d');
        }

        $url = "{$this->baseUrl}/accounts/{$accountId}/transactions/";
        if ($dateFrom) {
            $url .= "?date_from={$dateFrom}";
        }

        $response = Http::withToken($token)->get($url);

        if ($response->successful()) {
            $account = BankAccount::where('external_id', $accountId)->first();
            
            Log::info('GoCardlessService: Looking for account', [
                'accountId' => $accountId,
                'found' => $account ? 'YES' : 'NO',
                'accountId_found' => $account?->id ?? 'NULL'
            ]);
            
            if (!$account) {
                Log::error('GoCardlessService: Account not found for transactions', [
                    'accountId' => $accountId,
                    'all_accounts' => BankAccount::where('team_id', $this->teamId)->pluck('external_id', 'id')->toArray()
                ]);
                return;
            }

            foreach ($response->json()['transactions']['booked'] ?? [] as $tx) {
                $amount = (float)($tx['transactionAmount']['amount'] ?? '0');
                $direction = $amount >= 0 ? 'credit' : 'debit';

                // Direkte API-Felder
                $debtorName = $tx['debtorName'] ?? null;
                $creditorName = $tx['creditorName'] ?? null;
                $debtorIban = $tx['debtorAccount']['iban'] ?? null;
                $creditorIban = $tx['creditorAccount']['iban'] ?? null;
                $debtorAgent = $tx['debtorAgent'] ?? null;
                $creditorAgent = $tx['creditorAgent'] ?? null;
                $remittance = $tx['remittanceInformationUnstructured'] ?? null;
                $additionalInfo = $tx['additionalInformation'] ?? null;

                // additional_information parsen (Commerzbank-Format: Name\nBIC\nIBAN\nZweck\n...)
                $parsed = $this->parseAdditionalInformation($additionalInfo);

                // Fehlende Felder aus additional_information ergänzen
                if (!$debtorAgent && !$creditorAgent && $parsed['bic']) {
                    if ($direction === 'debit') {
                        $creditorAgent = $parsed['bic'];
                    } else {
                        $debtorAgent = $parsed['bic'];
                    }
                }
                if (!$creditorIban && !$debtorIban && $parsed['iban']) {
                    if ($direction === 'debit') {
                        $creditorIban = $parsed['iban'];
                    } else {
                        $debtorIban = $parsed['iban'];
                    }
                }

                // Counterparty ableiten (bei debit = creditor, bei credit = debtor)
                $counterpartyName = $direction === 'debit'
                    ? ($creditorName ?? $parsed['name'] ?? $debtorName)
                    : ($debtorName ?? $parsed['name'] ?? $creditorName);
                $counterpartyIban = $direction === 'debit'
                    ? ($creditorIban ?? $debtorIban)
                    : ($debtorIban ?? $creditorIban);

                // Reference aus remittance ableiten
                $reference = $remittance ?? $parsed['purpose'] ?? null;

                BankTransaction::updateOrCreate(
                [
                    'transaction_id' => $tx['transactionId'] ?? uniqid('tx_'),
                ],
                [
                    'bank_account_id' => $account->id,
                    'booked_at' => $tx['bookingDate'] ?? now()->toDateString(),
                    'booking_date' => $tx['bookingDate'] ?? null,
                    'booking_date_time' => $tx['bookingDateTime'] ?? null,
                    'value_date' => $tx['valueDate'] ?? null,
                    'value_date_time' => $tx['valueDateTime'] ?? null,
                    'amount' => $tx['transactionAmount']['amount'] ?? '0',
                    'currency' => $tx['transactionAmount']['currency'] ?? null,
                    'direction' => $direction,

                    // Verwendungszweck
                    'remittance_information' => $remittance,
                    'remittance_information_structured' => $tx['remittanceInformationStructured'] ?? null,
                    'remittance_information_structured_array' => $tx['remittanceInformationStructuredArray'] ?? null,
                    'remittance_information_unstructured' => $tx['remittanceInformationUnstructured'] ?? null,
                    'remittance_information_unstructured_array' => $tx['remittanceInformationUnstructuredArray'] ?? null,

                    // Beteiligte Konten und Namen
                    'debtor_name' => $debtorName,
                    'creditor_name' => $creditorName,
                    'debtor_account_iban' => $debtorIban,
                    'creditor_account_iban' => $creditorIban,
                    'debtor_agent' => $debtorAgent,
                    'creditor_agent' => $creditorAgent,

                    // Abgeleitete Felder
                    'counterparty_name' => $counterpartyName,
                    'counterparty_iban' => $counterpartyIban,
                    'reference' => $reference,

                    // Typen und Codes
                    'transaction_type' => $tx['transactionType'] ?? null,
                    'bank_transaction_code' => $tx['bankTransactionCode'] ?? null,
                    'proprietary_bank_transaction_code' => $tx['proprietaryBankTransactionCode'] ?? null,
                    'internal_transaction_id' => $tx['internalTransactionId'] ?? null,
                    'entry_reference' => $tx['entryReference'] ?? null,
                    'end_to_end_id' => $tx['endToEndId'] ?? null,
                    'mandate_id' => $tx['mandateId'] ?? null,
                    'merchant_category_code' => $tx['merchantCategoryCode'] ?? null,
                    'check_id' => $tx['checkId'] ?? null,
                    'creditor_id' => $tx['creditorId'] ?? null,
                    'purpose_code' => $tx['purposeCode'] ?? null,
                    'ultimate_creditor' => $tx['ultimateCreditor'] ?? null,
                    'ultimate_debtor' => $tx['ultimateDebtor'] ?? null,

                    // Weitere optionale Felder
                    'currency_exchange' => $tx['currencyExchange'] ?? null,
                    'balance_after_transaction' => $tx['balanceAfterTransaction'] ?? null,
                    'additional_data_structured' => $tx['additionalDataStructured'] ?? null,
                    'additional_information' => $additionalInfo,
                    'additional_information_structured' => $tx['additionalInformationStructured'] ?? null,
                ]
            );
                }

            // Sync-Timestamp für Account aktualisieren
            if ($account) {
                $account->update(['last_transactions_synced_at' => now()]);
            }

            // Sync-Timestamp für Requisition aktualisieren
            if ($requisition) {
                $requisition->update(['last_sync_at' => now()]);
            }
        }
    }

    /**
     * Parst das additional_information Feld (Commerzbank-Format).
     * Typisches Format: "Name\nBIC\nIBAN\nZweck\nWeitere Infos"
     */
    protected function parseAdditionalInformation(?string $info): array
    {
        $result = ['name' => null, 'bic' => null, 'iban' => null, 'purpose' => null];

        if (!$info) {
            return $result;
        }

        $lines = preg_split('/\r?\n/', trim($info));
        $remaining = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;

            // IBAN erkennen (DE + 20 Zeichen oder anderes Länderformat)
            if (!$result['iban'] && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $line)) {
                $result['iban'] = $line;
                continue;
            }

            // BIC erkennen (8 oder 11 Zeichen, endet typisch auf XXX)
            if (!$result['bic'] && preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $line)) {
                $result['bic'] = $line;
                continue;
            }

            $remaining[] = $line;
        }

        // Erste verbleibende Zeile = Name, Rest = Zweck
        if (!empty($remaining)) {
            $result['name'] = array_shift($remaining);
        }
        if (!empty($remaining)) {
            $result['purpose'] = implode(' ', $remaining);
        }

        return $result;
    }
}
