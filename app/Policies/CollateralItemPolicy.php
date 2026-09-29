<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CollateralItem;
use App\Models\User;

/**
 * Permission checks only. State rules (which statuses allow an action) belong to the module's
 * services and are enforced there; Admin passes every check via Gate::before.
 */
class CollateralItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CollateralView->value);
    }

    public function view(User $user, CollateralItem $collateralItem): bool
    {
        return $user->can(Permission::CollateralView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CollateralCreate->value);
    }

    public function update(User $user, CollateralItem $collateralItem): bool
    {
        return $user->can(Permission::CollateralUpdate->value);
    }

    public function release(User $user, CollateralItem $collateralItem): bool
    {
        return $user->can(Permission::CollateralRelease->value);
    }
}
