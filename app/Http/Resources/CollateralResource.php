<?php

namespace App\Http\Resources;

use App\Enums\CollateralStatus;
use App\Enums\LoanStatus;
use App\Models\CollateralItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Collateral items are identified by collateral_no; internal ids are not exposed.
 * Weight (3 decimals), karat and value are decimal strings.
 *
 * @mixin CollateralItem
 */
class CollateralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $held = $this->status === CollateralStatus::Held;

        return [
            'collateral_no' => $this->collateral_no,
            'loan' => $this->whenLoaded('loan', fn () => [
                'loan_no' => $this->loan->loan_no,
                'status' => $this->loan->status->value,
                'customer' => $this->loan->relationLoaded('customer') ? [
                    'customer_no' => $this->loan->customer->customer_no,
                    'name' => $this->loan->customer->name,
                ] : null,
            ]),
            'type' => $this->type,
            'weight_grams' => $this->weight_grams,
            'karat' => $this->karat,
            'estimated_value' => $this->estimated_value,
            'description' => $this->description,
            'status' => $this->status->value,
            'received_at' => $this->received_at->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'released_by' => $this->whenLoaded('releaser', fn () => $this->releaser?->name),
            // UI hints only; the server re-checks permission and the loan-status rules.
            'actions' => [
                'update' => $held && ($user?->can('update', $this->resource) ?? false),
                'release' => $held
                    && $this->relationLoaded('loan')
                    && in_array($this->loan->status, [LoanStatus::Closed, LoanStatus::Cancelled], true)
                    && ($user?->can('release', $this->resource) ?? false),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
