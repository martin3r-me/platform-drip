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
     * Sync MOSS expense-accounts + expenses for a team.
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
     * Sync MOSS expense-accounts as BankAccounts.
     *
     * Each expense-account (card/virtual card/wallet) becomes its own
     * BankAccount so it can be assigned to a group independently.
     */
    public function syncAccounts(Team $team, MossApiService $api, User $proxyUser): int
    {
        $response = $api->getExpenseAccounts($proxyUser);
        $expenseAccounts = $response['data'] ?? $response;

        if (!is_array($expenseAccounts)) {
            Log::warning('MossSyncService: Unexpected expense-accounts response', [
                'team_id' => $team->id,
                'response' => $response,
            ]);
            return 0;
        }

        $syncedIds = [];
        $count = 0;

        foreach ($expenseAccounts as $ea) {
            $eaId = $ea['id'] ?? null;
            if (!$eaId) {
                continue;
            }

            $name = $ea['name'] ?? $ea['account_name'] ?? 'MOSS Konto';
            $ownerName = $ea['owner']['name']
                ?? $ea['owner_name']
                ?? $ea['cardholder_name']
                ?? $ea['cardholder']['name']
                ?? null;
            if ($ownerName) {
                $name = "{$name} – {$ownerName}";
            }

            BankAccount::updateOrCreate(
                [
                    'provider' => 'moss',
                    'external_id' => $eaId,
                    'team_id' => $team->id,
                ],
                [
                    'name' => $name,
                    'currency' => $ea['currency'] ?? 'EUR',
                    'metadata' => $ea,
                    'last_details_synced_at' => now(),
                ]
            );

            $syncedIds[] = $eaId;
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
     * Fetches all expenses and assigns each to the matching BankAccount
     * via expense_account_id. Expenses without a matching account are
     * skipped (the account must exist from syncAccounts).
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

        // Use the oldest last_transactions_synced_at across all accounts for the API call
        $oldestSync = $accounts->min('last_transactions_synced_at');
        $dateFrom = $oldestSync
            ? Carbon::parse($oldestSync)->subDay()->format('Y-m-d')
            : now()->subDays(90)->format('Y-m-d');

        $totalCount = 0;
        $page = 1;

        do {
            $response = $api->getExpenses($proxyUser, [
                'date_from' => $dateFrom,
                'page' => $page,
                'per_page' => 100,
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

                // Find the matching BankAccount via expense_account_id
                $eaId = $expense['expense_account_id']
                    ?? $expense['account_id']
                    ?? $expense['card_id']
                    ?? null;

                $account = $eaId ? $accounts->get($eaId) : null;

                // Fallback: if only one account, assign everything to it
                if (!$account && $accounts->count() === 1) {
                    $account = $accounts->first();
                }

                if (!$account) {
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

                $totalCount++;
            }

            $meta = $response['meta'] ?? $response['pagination'] ?? null;
            $lastPage = $meta['last_page'] ?? $meta['total_pages'] ?? $page;
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
        $amount = (float) ($expense['amount'] ?? $expense['total_amount'] ?? 0);

        // MOSS amounts are positive; expenses are outflows → negative
        if ($amount > 0) {
            $amount = -$amount;
        }

        return $amount;
    }
}
