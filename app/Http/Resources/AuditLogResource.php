<?php

namespace App\Http\Resources;

use App\Domain\Audit\AuditTrail;
use App\Models\AuditLog;
use App\Models\CollateralItem;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An audit entry: who (null = system), when, what, which record (by its public number), the
 * before/after values and the request it came from. Internal ids of business records are not exposed.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'event_label' => AuditTrail::EVENTS[$this->event] ?? $this->event,
            'description' => $this->description,
            'user' => $this->user ? ['name' => $this->user->name, 'email' => $this->user->email] : null,
            'entity' => $this->entity(),
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{type: string, reference: ?string, url: ?string}|null
     */
    private function entity(): ?array
    {
        $model = $this->auditable;

        return match (true) {
            $model instanceof Loan => ['type' => 'Loan', 'reference' => $model->loan_no, 'url' => route('loans.show', $model)],
            $model instanceof Payment => ['type' => 'Payment', 'reference' => $model->receipt_no, 'url' => route('payments.show', $model)],
            $model instanceof CollateralItem => ['type' => 'Collateral', 'reference' => $model->collateral_no, 'url' => null],
            $model instanceof Customer => ['type' => 'Customer', 'reference' => $model->customer_no, 'url' => route('customers.show', $model)],
            $model instanceof User => ['type' => 'User', 'reference' => $model->email, 'url' => null],
            $model instanceof Role => ['type' => 'Role', 'reference' => $model->name, 'url' => null],
            // A deleted role: the name is kept in the entry itself.
            $this->auditable_type === Role::class => ['type' => 'Role', 'reference' => $this->old_values['name'] ?? null, 'url' => null],
            str_starts_with($this->event, 'settings.') => ['type' => 'Settings', 'reference' => null, 'url' => null],
            default => null,
        };
    }
}
