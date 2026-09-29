<?php

namespace App\Listeners;

use App\Domain\Audit\AuditTrail;
use App\Models\User;
use Illuminate\Auth\Events\Failed;

/**
 * Audits failed sign-in attempts (the email tried, never the password).
 */
class RecordFailedLogin
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(Failed $event): void
    {
        $email = (string) ($event->credentials['email'] ?? '');

        $this->audit->record('auth.login_failed', $event->user instanceof User ? $event->user : null, [], ['email' => $email, 'guard' => $event->guard],
            "Failed sign-in for {$email}", system: true);
    }
}
