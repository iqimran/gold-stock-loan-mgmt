<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UsersCreate->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permission::UsersUpdate->value);
    }

    public function changeStatus(User $user, User $model): bool
    {
        return $user->can(Permission::UsersDeactivate->value) && ! $user->is($model);
    }
}
