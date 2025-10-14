<?php

namespace Platform\Drip\Policies;

use Platform\Core\Models\User;
use Platform\Drip\Models\BankAccount;

class BankAccountPolicy
{
    public function view(User $user, BankAccount $account): bool
    {
        return (int) $user->current_team_id === (int) $account->team_id;
    }

    public function update(User $user, BankAccount $account): bool
    {
        return (int) $user->current_team_id === (int) $account->team_id;
    }

    public function delete(User $user, BankAccount $account): bool
    {
        return (int) $user->current_team_id === (int) $account->team_id;
    }
}


