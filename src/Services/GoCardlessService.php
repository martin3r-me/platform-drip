<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Drip\Models\{GoCardlessToken, Institution, BankAccount, BankAccountBalance, BankTransaction, Requisition};
use Carbon\Carbon;

class GoCardlessService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

    protected int $userId;
    protected int $teamId;

    public function __construct(int $userId, int $teamId)
    {
        $this->clientId = config('services.gocardless.client_id');
        $this->clientSecret = config('services.gocardless.client_secret');
        $this->userId = $userId;
        $this->teamId = $teamId;
    }

    public function getAccessToken(): ?string
    {
        $token = GoCardlessToken::where('user_id', $this->userId)
            ->where('team_id', $this->teamId)
            ->orderByDesc('created_at')
            ->first();

        if ($token && Carbon::now()->lt($token->expires_at)) {
            return $token->access_token;
        }

        return $this->requestNewToken();
    }

    protected function requestNewToken(): ?string
    {
        $response = Http::post("{$this->baseUrl}/token/new/", [
            'secret_id' => $this->clientId,
            'secret_key' => $this->clientSecret,
        ]);

        if (!$response->successful()) {
            Log::error('Fehler beim Abrufen des Tokens von GoCardless', ['body' => $response->body()]);
            return null;
        }

        $data = $response->json();

        GoCardlessToken::create([
            'user_id' => $this->userId,
            'team_id' => $this->teamId,
            'access_token' => $data['access'],
            'refresh_token' => $data['refresh'] ?? null,
            'expires_at' => now()->addSeconds($data['access_expires']),
        ]);

        return $data['access'];
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
        $token = $this->getAccessToken();
        if (!$token) return null;

        $reference = uniqid('ref_');

        $response = Http::withToken($token)->post("{$this->baseUrl}/requisitions/", [
            'redirect' => $redirectUrl,
            'institution_id' => $institutionId,
            'reference' => $reference,
            'user_language' => 'DE',
        ]);

        if ($response->successful()) {
            $data = $response->json();

            Requisition::create([
                'external_id' => $data['id'],
                'reference' => $reference,
                'institution_id' => Institution::where('external_id', $institutionId)->first()?->id,
                'user_id' => $this->userId,
                'team_id' => $this->teamId,
                'status' => $data['status']['short'] ?? null,
                'redirect' => $data['redirect'] ?? $redirectUrl,
            ]);

            return $data['link'];
        }

        Log::error('Fehler bei Requisition-Erstellung', ['body' => $response->body()]);
        return null;
    }

    public function getAccountsFromRequisitionByRef(string $reference): array
    {
        $requisition = Requisition::where('reference', $reference)->firstOrFail();

        $token = $this->getAccessToken();
        if (!$token) return [];

        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/requisitions/{$requisition->external_id}/");

        if (!$response->successful()) {
            Log::error('Fehler bei Abruf der Requisition', ['body' => $response->body()]);
            throw new \Exception('Requisition konnte nicht geladen werden.');
        }

        $data = $response->json();

        foreach ($data['accounts'] ?? [] as $accountId) {
            $this->storeAccountDetails($accountId);
            $this->storeAccountBalances($accountId);
        }

        $requisition->update([
            'accounts' => $data['accounts'] ?? [],
            'status' => $data['status'] ?? null,
            'linked_at' => now(),
        ]);

        return $data['accounts'] ?? [];
    }

    protected function storeAccountDetails(string $accountId): void
    {
        $token = $this->getAccessToken();
        if (!$token) return;

        $response = Http::withToken($token)->get("{$this->baseUrl}/accounts/{$accountId}/details/");

        if ($response->successful()) {
            $account = $response->json()['account'] ?? [];

            BankAccount::updateOrCreate(
                ['external_id' => $accountId],
                [
                    'user_id' => $this->userId,
                    'team_id' => $this->teamId,
                    'iban' => $account['iban'] ?? null,
                    'bban' => $account['bban'] ?? null,
                    'currency' => $account['currency'] ?? null,
                    'name' => $account['name'] ?? null,
                    'product' => $account['product'] ?? null,
                ]
            );
        }
    }

    protected function storeAccountBalances(string $accountId): void
    {
        $token = $this->getAccessToken();
        if (!$token) return;

        $response = Http::withToken($token)->get("{$this->baseUrl}/accounts/{$accountId}/balances/");

        if ($response->successful()) {
            $account = BankAccount::where('external_id', $accountId)->first();

            foreach ($response->json()['balances'] ?? [] as $balance) {
                BankAccountBalance::create([
                    'bank_account_id' => $account->id,
                    'balance_type' => $balance['balanceType'] ?? 'unknown',
                    'amount' => $balance['balanceAmount']['amount'] ?? '0',
                    'currency' => $balance['balanceAmount']['currency'] ?? null,
                    'retrieved_at' => now(),
                ]);
            }
        }
    }

    public function storeAccountTransactions(string $accountId): void
    {
        $token = $this->getAccessToken();
        if (!$token) return;

        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/accounts/{$accountId}/transactions/");

        if ($response->successful()) {
            $account = BankAccount::where('external_id', $accountId)->first();

            foreach ($response->json()['transactions']['booked'] ?? [] as $tx) {
                BankTransaction::updateOrCreate(
                [
                    'transaction_id' => $tx['transactionId'] ?? uniqid('tx_'),
                ],
                [
                    'bank_account_id' => $account->id,
                    'booking_date' => $tx['bookingDate'] ?? null,
                    'booking_date_time' => $tx['bookingDateTime'] ?? null,
                    'value_date' => $tx['valueDate'] ?? null,
                    'value_date_time' => $tx['valueDateTime'] ?? null,
                    'amount' => $tx['transactionAmount']['amount'] ?? '0',
                    'currency' => $tx['transactionAmount']['currency'] ?? null,

                    // Verwendungszweck und Infos
                    'remittance_information' => $tx['remittanceInformationUnstructured'] ?? null,
                    'remittance_information_structured' => $tx['remittanceInformationStructured'] ?? null,
                    'remittance_information_structured_array' => $tx['remittanceInformationStructuredArray'] ?? null,
                    'remittance_information_unstructured' => $tx['remittanceInformationUnstructured'] ?? null,
                    'remittance_information_unstructured_array' => $tx['remittanceInformationUnstructuredArray'] ?? null,

                    // Beteiligte Konten und Namen
                    'debtor_name' => $tx['debtorName'] ?? null,
                    'creditor_name' => $tx['creditorName'] ?? null,
                    'debtor_account_iban' => $tx['debtorAccount']['iban'] ?? null,
                    'creditor_account_iban' => $tx['creditorAccount']['iban'] ?? null,
                    'debtor_agent' => $tx['debtorAgent'] ?? null,
                    'creditor_agent' => $tx['creditorAgent'] ?? null,

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
                    'additional_information' => $tx['additionalInformation'] ?? null,
                    'additional_information_structured' => $tx['additionalInformationStructured'] ?? null,
                ]
            );
            }
        }
    }
}
