<?php

namespace Platform\Drip\Policies;

use Platform\Core\Models\User;
use Platform\Drip\Models\BankTransaction;

class BankTransactionPolicy
{
    public function view(User $user, BankTransaction $transaction): bool
    {
        return (int) $user->current_team_id === (int) $transaction->team_id;
    }

    public function update(User $user, BankTransaction $transaction): bool
    {
        return (int) $user->current_team_id === (int) $transaction->team_id;
    }

    public function delete(User $user, BankTransaction $transaction): bool
    {
        return (int) $user->current_team_id === (int) $transaction->team_id;
    }
}


