<?php

namespace Platform\Drip\Observers;

use Platform\Drip\Models\BankAccount;
use Platform\Drip\Services\TransactionService;

class BankAccountObserver
{
    public function updated(BankAccount $account): void
    {
        if ($account->wasChanged('group_id')) {
            // Nach Gruppenwechsel Transaktionen des Kontos normalisieren
            app(TransactionService::class)->normalizeAccounts(
                teamId: (int) $account->team_id,
                accountIds: [$account->id],
                since: null,
            );
        }
    }
}


