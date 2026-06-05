<?php

namespace Platform\Drip\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankTransaction;
use Platform\Integrations\Exceptions\MossApiException;
use Platform\Integrations\Services\IntegrationConnectionResolver;
use Platform\Integrations\Services\MossApiService;

class MossSyncService
{
    public function __construct(
        protected IntegrationConnectionResolver $connectionResolver,
        protected MossApiService $api,
    ) {}

    /**
     * Sync MOSS wallets + expenses for a team.
     *
     * @return array{accounts_synced: int, transactions_synced: int}
     */
    public function syncForTeam(Team $team): array
    {
        $connection = $this->connectionResolver->resolveForTeam('moss', $team);

        if (!$connection) {
            Log::debug('MossSyncService: No MOSS connection for team', ['team_id' => $team->id]);
            return ['accounts_synced' => 0, 'transactions_synced' => 0];
        }

        $proxyUser = User::find($connection->owner_user_id);

        if (!$proxyUser) {
            Log::warning('MossSyncService: Connection owner not found', [
                'team_id' => $team->id,
                'owner_user_id' => $connection->owner_user_id,
            ]);
            return ['accounts_synced' => 0, 'transactions_synced' => 0];
        }

        $api = $this->api->forConnection($connection->id);

        $accountsSynced = 0;
        $transactionsSynced = 0;

        try {
            $accountsSynced = $this->syncAccounts($team, $api, $proxyUser);
        } catch (MossApiException $e) {
            Log::error('MossSyncService: Account sync failed', [
                'team_id' => $team->id,
                'status' => $e->getHttpStatusCode(),
                'error' => $e->getMessage(),
                'response' => $e->getResponseData(),
            ]);
            throw $e;
        }

        try {
            $transactionsSynced = $this->syncTransactions($team, $api, $proxyUser);
        } catch (MossApiException $e) {
            Log::error('MossSyncService: Transaction sync failed', [
                'team_id' => $team->id,
                'status' => $e->getHttpStatusCode(),
                'error' => $e->getMessage(),
                'response' => $e->getResponseData(),
            ]);
            // Don't throw — accounts were already synced successfully
        }

        return [
            'accounts_synced' => $accountsSynced,
            'transactions_synced' => $transactionsSynced,
        ];
    }

    /**
     * Sync MOSS bank-accounts (wallets) as BankAccounts.
     *
     * MOSS /v1/bank-accounts returns wallets (CREDIT/DEBIT funding type).
     * Each wallet becomes its own BankAccount in Drip.
     */
    public function syncAccounts(Team $team, MossApiService $api, User $proxyUser): int
    {
        $response = $api->getBankAccounts($proxyUser);
        $mossAccounts = $response['data'] ?? $response;

        if (!is_array($mossAccounts)) {
            Log::warning('MossSyncService: Unexpected bank-accounts response', [
                'team_id' => $team->id,
                'response' => $response,
            ]);
            return 0;
        }

        $syncedIds = [];
        $count = 0;

        foreach ($mossAccounts as $ma) {
            $maId = $ma['id'] ?? null;
            if (!$maId) {
                continue;
            }

            // Skip closed wallets
            $status = $ma['status'] ?? null;
            if ($status === 'CLOSED') {
                continue;
            }

            $name = $ma['bankName'] ?? $ma['bank_name'] ?? $ma['name'] ?? 'MOSS Wallet';
            $accountNumber = $ma['accountNumber'] ?? $ma['account_number'] ?? null;
            if ($accountNumber) {
                $name = "{$name} ({$accountNumber})";
            }

            BankAccount::updateOrCreate(
                [
                    'provider' => 'moss',
                    'external_id' => $maId,
                    'team_id' => $team->id,
                ],
                [
                    'name' => $name,
                    'currency' => $ma['currency'] ?? 'EUR',
                    'metadata' => $ma,
                    'last_details_synced_at' => now(),
                ]
            );

            $syncedIds[] = $maId;
            $count++;
        }

        // Close accounts no longer in MOSS
        if (!empty($syncedIds)) {
            BankAccount::where('team_id', $team->id)
                ->where('provider', 'moss')
                ->whereNotIn('external_id', $syncedIds)
                ->whereNull('closed_at')
                ->update(['closed_at' => now()]);
        }

        return $count;
    }

    /**
     * Sync MOSS expenses as BankTransactions.
     *
     * MOSS API uses: expense_date__gte (not date_from), page_size (not per_page).
     * Expenses are assigned to the MOSS wallet. If multiple wallets exist,
     * we fall back to the first active one.
     */
    public function syncTransactions(Team $team, MossApiService $api, User $proxyUser): int
    {
        $accounts = BankAccount::where('team_id', $team->id)
            ->where('provider', 'moss')
            ->whereNull('closed_at')
            ->get()
            ->keyBy('external_id');

        if ($accounts->isEmpty()) {
            return 0;
        }

        // Preload suppliers for counterparty name resolution
        $supplierMap = $this->loadSupplierMap($api, $proxyUser);

        // Delta: oldest sync timestamp across all accounts, or 90 days back
        $oldestSync = $accounts->min('last_transactions_synced_at');
        $dateFrom = $oldestSync
            ? Carbon::parse($oldestSync)->subDay()->format('Y-m-d')
            : now()->subDays(90)->format('Y-m-d');

        $totalCount = 0;
        $page = 1;

        do {
            // MOSS API parameters: expense_date__gte, page_size, page
            $response = $api->getExpenses($proxyUser, [
                'expense_date__gte' => $dateFrom,
                'page' => $page,
                'page_size' => 100,
            ]);

            $expenses = $response['data'] ?? $response;

            if (!is_array($expenses) || empty($expenses)) {
                break;
            }

            foreach ($expenses as $expense) {
                $expenseId = $expense['id'] ?? null;
                if (!$expenseId) {
                    continue;
                }

                // Resolve target BankAccount — fallback to first wallet
                $account = $accounts->first();

                $amount = $this->parseAmount($expense);
                $bookedAt = $expense['expenseTime'] ?? $expense['createTime'] ?? now();
                $currency = $expense['homeAmount']['currency']
                    ?? $expense['currency']
                    ?? $account->currency
                    ?? 'EUR';

                BankTransaction::updateOrCreate(
                    [
                        'transaction_id' => 'moss_' . $expenseId,
                        'bank_account_id' => $account->id,
                    ],
                    [
                        'amount' => $amount,
                        'currency' => $currency,
                        'direction' => 'debit',
                        'booked_at' => $bookedAt,
                        'booking_date' => $bookedAt,
                        'counterparty_name' => $this->parseCounterpartyName($expense, $supplierMap),
                        'reference' => $this->parseReference($expense),
                        'metadata' => $expense,
                        'status' => 'booked',
                    ]
                );

                $totalCount++;
            }

            // MOSS pagination: check total pages from meta
            $meta = $response['meta'] ?? $response['pagination'] ?? null;
            $lastPage = $meta['last_page'] ?? $meta['total_pages'] ?? $meta['totalPages'] ?? $page;
            $page++;
        } while ($page <= $lastPage);

        // Update sync timestamp on all accounts
        BankAccount::where('team_id', $team->id)
            ->where('provider', 'moss')
            ->whereNull('closed_at')
            ->update(['last_transactions_synced_at' => now()]);

        return $totalCount;
    }

    protected function parseAmount(array $expense): float
    {
        // Prefer homeAmount (organisation's home currency)
        if (isset($expense['homeAmount']['amount'])) {
            $amount = (float) $expense['homeAmount']['amount'];
        } else {
            $amount = (float) ($expense['amount'] ?? $expense['total_amount'] ?? 0);
        }

        // Expenses are outflows → negative
        if ($amount > 0) {
            $amount = -$amount;
        }

        return $amount;
    }

    protected function parseCounterpartyName(array $expense, array $supplierMap = []): ?string
    {
        $meta = $expense['expenseMetadata'] ?? [];

        // CARD_TRANSACTION: merchantDetails.name
        $merchant = $meta['merchantDetails']['name'] ?? null;
        if ($merchant) {
            return $merchant;
        }

        // REIMBURSEMENT / INVOICE: resolve supplierId via preloaded map
        $supplierId = $expense['supplierId'] ?? null;
        if ($supplierId && isset($supplierMap[$supplierId])) {
            return $supplierMap[$supplierId];
        }

        return null;
    }

    /**
     * Load all MOSS suppliers into an id → name map.
     */
    protected function loadSupplierMap(MossApiService $api, User $proxyUser): array
    {
        try {
            $response = $api->getSuppliers($proxyUser, ['page_size' => 100]);
            $suppliers = $response['data'] ?? $response;

            if (!is_array($suppliers)) {
                return [];
            }

            $map = [];
            foreach ($suppliers as $s) {
                $id = $s['id'] ?? null;
                $name = $s['name'] ?? $s['companyName'] ?? null;
                if ($id && $name) {
                    $map[$id] = $name;
                }
            }

            return $map;
        } catch (\Exception $e) {
            // Non-critical — counterparty will be null for reimbursements
            return [];
        }
    }

    protected function parseReference(array $expense): ?string
    {
        $meta = $expense['expenseMetadata'] ?? [];

        // Combine all available text fields
        $parts = array_filter([
            $expense['description'] ?? null,
            $expense['bookingText'] ?? null,
            // Line-level bookingText (first line)
            $expense['lines'][0]['bookingText'] ?? null,
            $expense['lines'][0]['description'] ?? null,
        ]);

        // Deduplicate (description sometimes repeats in lines)
        $parts = array_unique($parts);

        return !empty($parts) ? implode(' | ', $parts) : null;
    }
}
