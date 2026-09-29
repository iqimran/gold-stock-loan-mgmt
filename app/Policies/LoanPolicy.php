<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Loan;
use App\Models\User;

/**
 * Permission checks only. State rules (which statuses allow an action) belong to the module's
 * services and are enforced there; Admin passes every check via Gate::before.
 */
class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::LoansView->value);
    }

    public function view(User $user, Loan $loan): bool
    {
        return $user->can(Permission::LoansView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::LoansCreate->value);
    }

    public function update(User $user, Loan $loan): bool
    {
        return $user->can(Permission::LoansUpdate->value);
    }

    public function close(User $user, Loan $loan): bool
    {
        return $user->can(Permission::LoansClose->value);
    }

    public function cancel(User $user, Loan $loan): bool
    {
        return $user->can(Permission::LoansCancel->value);
    }
}
