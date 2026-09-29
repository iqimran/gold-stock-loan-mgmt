<?php

namespace App\Http\Resources;

use App\Domain\Loan\LoanStatusTransitions;
use App\Enums\LoanStatus;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Loans are identified by loan_no; internal ids are not exposed. Amounts are decimal strings.
 *
 * @mixin Loan
 */
class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'loan_no' => $this->loan_no,
            'customer' => $this->whenLoaded('customer', fn () => [
                'customer_no' => $this->customer->customer_no,
                'name' => $this->customer->name,
                'mobile' => $this->customer->mobile,
            ]),
            'principal' => $this->principal,
            'outstanding_principal' => $this->outstanding_principal,
            'interest_rate' => $this->interest_rate,
            'interest_rate_type' => $this->interest_rate_type->value,
            'interest_period_unit' => $this->interest_period_unit->value,
            'status' => $this->status->value,
            'start_date' => $this->start_date->toDateString(),
            'next_due_date' => $this->next_due_date?->toDateString(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'notes' => $this->notes,
            // UI hints: actions the status allows and the user may attempt. The server re-checks
            // everything, including the settlement rules for closing and cancelling.
            'actions' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'edit_terms' => $this->status === LoanStatus::Draft,
                'activate' => $this->allows(LoanStatus::Active) && $this->status === LoanStatus::Draft && ($user?->can('activate', $this->resource) ?? false),
                'close' => $this->allows(LoanStatus::Closed) && ($user?->can('close', $this->resource) ?? false),
                'cancel' => $this->allows(LoanStatus::Cancelled) && ($user?->can('cancel', $this->resource) ?? false),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function allows(LoanStatus $to): bool
    {
        return LoanStatusTransitions::allows($this->status, $to);
    }
}
