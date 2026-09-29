<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Payment;
use App\Models\User;

/**
 * Permission checks only. State rules (which statuses allow an action) belong to the module's
 * services and are enforced there; Admin passes every check via Gate::before.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PaymentsView->value);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can(Permission::PaymentsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PaymentsCreate->value);
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return $user->can(Permission::PaymentsReverse->value);
    }
}
