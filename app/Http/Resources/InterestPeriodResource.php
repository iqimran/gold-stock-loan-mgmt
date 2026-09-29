<?php

namespace App\Http\Resources;

use App\Domain\Interest\InterestPeriodStatusResolver;
use App\Domain\Settings\LoanSettings;
use App\Models\InterestPeriod;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An interest period with its status resolved for the business date (always current, even before the
 * daily run) and the amount still unpaid. Amounts are decimal strings.
 *
 * @mixin InterestPeriod
 */
class InterestPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $due = $this->due_date->toDateString();

        return [
            'loan' => $this->whenLoaded('loan', fn () => [
                'loan_no' => $this->loan->loan_no,
                'status' => $this->loan->status->value,
                'customer' => $this->loan->relationLoaded('customer') ? [
                    'customer_no' => $this->loan->customer->customer_no,
                    'name' => $this->loan->customer->name,
                    'mobile' => $this->loan->customer->mobile,
                ] : null,
            ]),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'due_date' => $due,
            'expected_interest' => $this->expected_interest,
            'paid_interest' => $this->paid_interest,
            'unpaid_interest' => $this->waived_at ? '0.00' : Money::max(Money::sub($this->expected_interest, $this->paid_interest), '0.00'),
            'status' => InterestPeriodStatusResolver::resolve($this->expected_interest, $this->paid_interest, $this->waived_at !== null, $due, today()->toDateString(), app(LoanSettings::class)->missedCutoff())->value,
            'waived_at' => $this->waived_at?->toIso8601String(),
            'loan_consecutive_missed' => $this->when($this->resource->hasAttribute('consecutive_missed'), fn () => (int) $this->consecutive_missed),
        ];
    }
}
