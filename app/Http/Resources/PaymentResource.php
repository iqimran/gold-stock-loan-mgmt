<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payments are identified by receipt_no; internal ids are not exposed. The allocation is the server's.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $allocations = $this->relationLoaded('allocations') ? $this->allocations : null;
        $sum = fn (string $field) => $allocations?->reduce(fn (string $total, PaymentAllocation $a) => Money::add($total, $a->{$field}), '0.00');

        return [
            'receipt_no' => $this->receipt_no,
            'loan' => $this->whenLoaded('loan', fn () => ['loan_no' => $this->loan->loan_no, 'status' => $this->loan->status->value]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'customer_no' => $this->customer->customer_no,
                'name' => $this->customer->name,
                'mobile' => $this->customer->mobile,
            ]),
            'type' => $this->type->value,
            'amount' => $this->amount,
            'method' => $this->method,
            'payment_date' => $this->payment_date->toDateString(),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'allocation' => $this->when($allocations !== null, fn () => [
                'interest' => $sum('interest_amount'),
                'principal' => $sum('principal_amount'),
                'fee' => $sum('fee_amount'),
                'periods' => $allocations->whereNotNull('interest_period_id')->map(fn (PaymentAllocation $a) => [
                    'period_start' => $a->interestPeriod?->period_start->toDateString(),
                    'period_end' => $a->interestPeriod?->period_end->toDateString(),
                    'due_date' => $a->interestPeriod?->due_date->toDateString(),
                    'interest' => $a->interest_amount,
                ])->values(),
            ]),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
