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
     * Sync MOSS accounts + transactions for a team.
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

    public function syncAccounts(Team $team, MossApiService $api, User $proxyUser): int
    {
        $response = $api->getBankAccounts($proxyUser);
        $mossAccounts = $response['data'] ?? $response;

        if (!is_array($mossAccounts)) {
            Log::warning('MossSyncService: Unexpected bank accounts response', [
                'team_id' => $team->id,
                'response' => $response,
            ]);
            return 0;
        }

        $syncedIds = [];
        $count = 0;

        foreach ($mossAccounts as $mossAccount) {
            $mossAccountId = $mossAccount['id'] ?? null;
            if (!$mossAccountId) {
                continue;
            }

            $name = $mossAccount['account_name'] ?? $mossAccount['name'] ?? 'MOSS Konto';
            $cardholderName = $mossAccount['cardholder_name'] ?? $mossAccount['cardholder']['name'] ?? null;
            if ($cardholderName) {
                $name = "{$name} – {$cardholderName}";
            }

            BankAccount::updateOrCreate(
                [
                    'provider' => 'moss',
                    'external_id' => $mossAccountId,
                    'team_id' => $team->id,
                ],
                [
                    'name' => $name,
                    'currency' => $mossAccount['currency'] ?? 'EUR',
                    'metadata' => $mossAccount,
                    'last_details_synced_at' => now(),
                ]
            );

            $syncedIds[] = $mossAccountId;
            $count++;
        }

        // Soft-delete accounts no longer in MOSS
        if (!empty($syncedIds)) {
            BankAccount::where('team_id', $team->id)
                ->where('provider', 'moss')
                ->whereNotIn('external_id', $syncedIds)
                ->whereNull('closed_at')
                ->update(['closed_at' => now()]);
        }

        Log::info('MossSyncService: Accounts synced', [
            'team_id' => $team->id,
            'count' => $count,
        ]);

        return $count;
    }

    public function syncTransactions(Team $team, MossApiService $api, User $proxyUser): int
    {
        $accounts = BankAccount::where('team_id', $team->id)
            ->where('provider', 'moss')
            ->whereNull('closed_at')
            ->get();

        $totalCount = 0;

        foreach ($accounts as $account) {
            $count = $this->syncTransactionsForAccount($account, $api, $proxyUser);
            $totalCount += $count;

            $account->update(['last_transactions_synced_at' => now()]);
        }

        return $totalCount;
    }

    protected function syncTransactionsForAccount(BankAccount $account, MossApiService $api, User $proxyUser): int
    {
        $dateFrom = $account->last_transactions_synced_at
            ? $account->last_transactions_synced_at->copy()->subDay()->format('Y-m-d')
            : now()->subDays(90)->format('Y-m-d');

        $count = 0;
        $page = 1;

        do {
            $filters = [
                'date_from' => $dateFrom,
                'page' => $page,
                'per_page' => 100,
            ];

            $response = $api->getExpenses($proxyUser, $filters);

            $expenses = $response['data'] ?? $response;

            if (!is_array($expenses) || empty($expenses)) {
                break;
            }

            foreach ($expenses as $expense) {
                $expenseId = $expense['id'] ?? null;
                if (!$expenseId) {
                    continue;
                }

                $amount = $this->parseAmount($expense);
                $supplierName = $expense['supplier']['name']
                    ?? $expense['supplier_name']
                    ?? $expense['merchant_name']
                    ?? null;

                BankTransaction::updateOrCreate(
                    [
                        'transaction_id' => 'moss_' . $expenseId,
                        'bank_account_id' => $account->id,
                    ],
                    [
                        'amount' => $amount,
                        'currency' => $expense['currency'] ?? $account->currency ?? 'EUR',
                        'direction' => 'debit',
                        'booked_at' => $expense['date'] ?? $expense['created_at'] ?? now(),
                        'booking_date' => $expense['date'] ?? $expense['created_at'] ?? now(),
                        'counterparty_name' => $supplierName,
                        'reference' => $expense['description'] ?? $expense['note'] ?? null,
                        'metadata' => $expense,
                        'status' => 'booked',
                    ]
                );

                $count++;
            }

            // Pagination: check if there are more pages
            $meta = $response['meta'] ?? $response['pagination'] ?? null;
            $lastPage = $meta['last_page'] ?? $meta['total_pages'] ?? $page;
            $page++;
        } while ($page <= $lastPage);

        Log::info('MossSyncService: Transactions synced for account', [
            'account_id' => $account->id,
            'external_id' => $account->external_id,
            'count' => $count,
        ]);

        return $count;
    }

    protected function parseAmount(array $expense): float
    {
        $amount = (float) ($expense['amount'] ?? $expense['total_amount'] ?? 0);

        // MOSS amounts are positive; card transactions are expenses → negative
        if ($amount > 0) {
            $amount = -$amount;
        }

        return $amount;
    }
}
