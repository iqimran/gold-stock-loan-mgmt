<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes the audit trail for financial and sensitive changes (docs/02 "Audit", Task 019): actor, time,
 * action (event), entity (auditable type + id), before/after values, and the reason where one is required
 * (kept in new_values.reason and the description).
 *
 * Called explicitly from the domain service that performs the change, inside its transaction, so an
 * audit entry exists exactly when the change is committed. Events:
 *
 *   loan.created, loan.updated, loan.status_changed            App\Domain\Loan\LoanService
 *   payment.created, payment.reversed                           PaymentService, PaymentReversalService
 *   collateral.created, collateral.updated, collateral.released App\Domain\Collateral\CollateralService
 *   customer.created, customer.updated, customer.archived, customer.restored   customer actions
 *   settings.loans_updated                                      App\Domain\Settings\LoanSettings
 *   user.created, user.updated, user.activated, user.deactivated   user actions
 *   role.created, role.updated, role.deleted                    role actions
 *   auth.login, auth.login_failed, auth.token_issued, auth.token_failed   sign-ins
 *
 * Secrets (passwords, tokens) are never stored: such attributes are replaced by "[changed]".
 */
class AuditTrail
{
    /** Every audited action with its label (the audit log screen's filter). */
    public const EVENTS = [
        'loan.created' => 'Loan created',
        'loan.updated' => 'Loan terms changed',
        'loan.status_changed' => 'Loan status changed',
        'payment.created' => 'Payment recorded',
        'payment.reversed' => 'Payment reversed',
        'collateral.created' => 'Collateral added',
        'collateral.updated' => 'Collateral corrected',
        'collateral.released' => 'Collateral released',
        'customer.created' => 'Customer created',
        'customer.updated' => 'Customer updated',
        'customer.archived' => 'Customer archived',
        'customer.restored' => 'Customer restored',
        'settings.loans_updated' => 'Loan settings changed',
        'user.created' => 'User created',
        'user.updated' => 'User updated',
        'user.activated' => 'User activated',
        'user.deactivated' => 'User deactivated',
        'role.created' => 'Role created',
        'role.updated' => 'Role updated',
        'role.deleted' => 'Role deleted',
        'auth.login' => 'Signed in',
        'auth.login_failed' => 'Sign-in failed',
        'auth.token_issued' => 'API token issued',
        'auth.token_failed' => 'API sign-in failed',
    ];

    /** Attribute names whose values are never stored. */
    private const SECRET = ['password', 'password_confirmation', 'current_password', 'remember_token', 'token', 'plain_text_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(string $event, ?Model $subject = null, array $old = [], array $new = [], ?string $description = null, ?int $userId = null, bool $system = false): AuditLog
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::create([
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'description' => $description !== null ? Str::limit($description, 250) : null,
            'old_values' => $old === [] ? null : $this->clean($old),
            'new_values' => $new === [] ? null : $this->clean($new),
            // A system action (scheduler / automatic status) has no actor, even inside a user's request.
            'user_id' => $system ? null : ($userId ?? Auth::id()),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }

    /**
     * Record only the attributes that actually changed; nothing is written when nothing changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordChanges(string $event, ?Model $subject, array $before, array $after, ?string $description = null): ?AuditLog
    {
        $before = $this->clean($before);
        $after = $this->clean($after);
        $changed = array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return null;
        }

        return $this->record(
            $event,
            $subject,
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)),
            $description,
        );
    }

    /**
     * Comparable, storable snapshot of a model's attributes.
     *
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    public function snapshot(Model $model, array $attributes): array
    {
        $values = [];

        foreach ($attributes as $attribute) {
            $values[$attribute] = $model->getAttribute($attribute);
        }

        return $this->clean($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (in_array($key, self::SECRET, true)) {
                $clean[$key] = '[changed]';

                continue;
            }

            $clean[$key] = $this->value($value);
        }

        return $clean;
    }

    /**
     * JSON-safe, comparable value (nested arrays are cleaned recursively).
     */
    private function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value), is_int($value), $value === null => $value,
            is_array($value) => array_map(fn ($item) => $this->value($item), $value),
            default => (string) $value,
        };
    }
}
