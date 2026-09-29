<?php

namespace App\Actions\Users;

use App\Domain\Audit\AuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateUser
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array{name: string, email: string, password: string, role: string, permissions?: list<string>, is_active?: bool}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $user->syncRoles([$data['role']]);
            $user->syncPermissions($user->isAdmin() ? [] : ($data['permissions'] ?? []));

            // The password itself is never logged.
            $this->audit->record('user.created', $user, [], UserAccess::snapshot($user), "User {$user->email} created");

            return $user;
        });
    }
}
