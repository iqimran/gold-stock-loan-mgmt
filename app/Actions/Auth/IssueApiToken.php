<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class IssueApiToken
{
    /**
     * Issue a Sanctum personal access token for valid, active credentials.
     *
     * @throws ValidationException
     */
    public function handle(string $email, string $password, string $deviceName): string
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->is_active || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        User::whereKey($user->getKey())->toBase()->update(['last_login_at' => now()]);

        return $user->createToken($deviceName)->plainTextToken;
    }
}
