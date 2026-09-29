<?php

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'status' => $this->status->value,
            'threshold' => $this->threshold,
            'message' => $this->message,
            'loan' => $this->whenLoaded('loan', fn () => $this->loan ? ['loan_no' => $this->loan->loan_no, 'status' => $this->loan->status->value] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'customer_no' => $this->customer->customer_no,
                'name' => $this->customer->name,
                'mobile' => $this->customer->mobile,
            ] : null),
            'triggering_period' => $this->whenLoaded('interestPeriod', fn () => $this->interestPeriod ? [
                'period_start' => $this->interestPeriod->period_start->toDateString(),
                'period_end' => $this->interestPeriod->period_end->toDateString(),
                'due_date' => $this->interestPeriod->due_date->toDateString(),
            ] : null),
            'triggered_at' => $this->triggered_at->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
