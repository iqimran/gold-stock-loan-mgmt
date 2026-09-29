<?php

namespace App\Actions\Auth;

use App\Domain\Audit\AuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class IssueApiToken
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Issue a Sanctum personal access token for valid, active credentials.
     *
     * @throws ValidationException
     */
    public function handle(string $email, string $password, string $deviceName): string
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->is_active || ! Hash::check($password, $user->password)) {
            $this->audit->record('auth.token_failed', $user, [], ['email' => $email, 'device' => $deviceName], "Failed API sign-in for {$email}", system: true);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        User::whereKey($user->getKey())->toBase()->update(['last_login_at' => now()]);

        $token = $user->createToken($deviceName);
        // The token itself is a secret and is never logged.
        $this->audit->record('auth.token_issued', $user, [], ['device' => $deviceName, 'token_id' => $token->accessToken->getKey()], "API token issued to {$user->email}", $user->getKey());

        return $token->plainTextToken;
    }
}
