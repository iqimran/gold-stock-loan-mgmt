<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower((string) config('shop.admin.email'));
        $password = config('shop.admin.password');

        if (User::where('email', $email)->exists()) {
            return;
        }

        if (blank($password)) {
            if (! app()->environment(['local', 'testing'])) {
                $this->command?->warn('ADMIN_PASSWORD is not set; skipping initial admin creation.');

                return;
            }

            $password = 'password';
            $this->command?->warn("Created {$email} with the development password \"password\". Change it after first login.");
        }

        $admin = User::create([
            'name' => config('shop.admin.name'),
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);

        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->assignRole(SystemRole::Admin->value);
    }
}
