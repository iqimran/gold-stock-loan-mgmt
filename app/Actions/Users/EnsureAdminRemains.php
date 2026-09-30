<?php

namespace App\Actions\Users;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Guards against removing the last active Admin (which would lock everyone out of administration).
 */
class EnsureAdminRemains
{
    /**
     * Must be called inside a database transaction; admin rows are locked until it commits.
     *
     * @throws ValidationException
     */
    public function handle(User $user, string $field): void
    {
        if (! $user->isAdmin()) {
            return;
        }

        // Lock the rows, then count them: PostgreSQL rejects FOR UPDATE on an aggregate (COUNT) query.
        $otherActiveAdmins = User::role(SystemRole::Admin->value)
            ->active()
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->pluck('users.id')
            ->count();

        if ($otherActiveAdmins === 0) {
            throw ValidationException::withMessages([
                $field => 'At least one active Admin account must remain.',
            ]);
        }
    }
}
