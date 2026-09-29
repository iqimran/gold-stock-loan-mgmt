<?php

namespace App\Listeners;

use App\Domain\Audit\AuditTrail;
use App\Models\User;
use Illuminate\Auth\Events\Login;

class RecordSuccessfulLogin
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            // Direct query: a login is not a profile edit, so leave updated_at/updated_by untouched.
            User::whereKey($event->user->getKey())->toBase()->update(['last_login_at' => now()]);
            $this->audit->record('auth.login', $event->user, [], ['guard' => $event->guard, 'remember' => $event->remember], "{$event->user->email} signed in", $event->user->getKey());
        }
    }
}
