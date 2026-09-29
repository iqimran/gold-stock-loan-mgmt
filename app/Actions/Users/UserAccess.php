<?php

namespace App\Actions\Users;

use App\Models\User;

/**
 * A user's account and access as recorded in the audit log: identity, status, role and direct
 * permissions (sorted, so a re-save in another order is not a change). Never the password.
 */
final class UserAccess
{
    /**
     * @return array{name: string, email: string, is_active: bool, role: ?string, permissions: list<string>}
     */
    public static function snapshot(User $user): array
    {
        $permissions = $user->getDirectPermissions()->pluck('name')->sort()->values()->all();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'role' => $user->getRoleNames()->first(),
            'permissions' => $permissions,
        ];
    }
}
