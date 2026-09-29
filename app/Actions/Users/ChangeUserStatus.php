<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeUserStatus
{
    public function __construct(private readonly EnsureAdminRemains $ensureAdminRemains) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $user, bool $active): User
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['is_active' => 'You cannot change the status of your own account.']);
        }

        return DB::transaction(function () use ($user, $active): User {
            if (! $active) {
                $this->ensureAdminRemains->handle($user, 'is_active');
                // Revoke API access immediately; web sessions are ended by EnsureUserIsActive.
                $user->tokens()->delete();
            }

            $user->is_active = $active;
            $user->save();

            return $user;
        });
    }
}
