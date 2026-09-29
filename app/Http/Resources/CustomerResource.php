<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customers are identified by customer_no; the internal id is not exposed.
 * The summary block is present when the query applied App\Domain\Customer\CustomerSummary.
 *
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'customer_no' => $this->customer_no,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'nid' => $this->nid,
            'address' => $this->address,
            'status' => $this->status->value,
            'image_url' => $this->image_path ? route('api.v1.customers.image', $this->resource) : null,
            'summary' => $this->when($this->resource->hasAttribute('total_interest_due'), fn () => [
                'active_loans' => $this->active_loans_count,
                'total_interest_due' => $this->total_interest_due,
                'consecutive_missed' => $this->consecutive_missed ?? 0,
                'next_due_date' => $this->next_due_date?->toDateString(),
                'last_payment' => $this->last_payment_date ? [
                    'date' => $this->last_payment_date->toDateString(),
                    'amount' => $this->last_payment_amount,
                ] : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
