<?php

namespace App\Actions\Users;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateUser
{
    public function __construct(private readonly EnsureAdminRemains $ensureAdminRemains) {}

    /**
     * @param  array{name: string, email: string, password?: ?string, role: string, permissions?: list<string>}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, User $user, array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data): User {
            $roleChanged = ! $user->hasRole($data['role']);

            if ($roleChanged && $actor->is($user)) {
                throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
            }

            if ($roleChanged && $data['role'] !== SystemRole::Admin->value) {
                $this->ensureAdminRemains->handle($user, 'role');
            }

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }

            $user->save();

            $user->syncRoles([$data['role']]);
            $user->syncPermissions($user->isAdmin() ? [] : ($data['permissions'] ?? []));

            return $user;
        });
    }
}
