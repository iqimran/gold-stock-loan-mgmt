<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;

/**
 * Permission checks only. State rules (which statuses allow an action) belong to the module's
 * services and are enforced there; Admin passes every check via Gate::before.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CustomersView->value);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CustomersCreate->value);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersUpdate->value);
    }

    public function archive(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersArchive->value);
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersArchive->value);
    }
}
