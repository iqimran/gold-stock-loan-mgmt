<?php

namespace App\Http\Requests\Collateral;

use App\Models\CollateralItem;
use App\Models\Loan;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates collateral intake (POST /loans/{loan}/collateral) and correction (PATCH /collateral/{item}).
 * The loan and status are never accepted from input. Whether a reason is required, and whether the
 * item may still change, is decided by App\Domain\Collateral\CollateralService.
 */
class CollateralRequest extends FormRequest
{
    /** DECIMAL(12,3) */
    public const MAX_WEIGHT = '999999999.999';

    public function authorize(): bool
    {
        $item = $this->route('collateralItem');

        if ($item instanceof CollateralItem) {
            return $this->user()->can('update', $item);
        }

        /** @var Loan $loan */
        $loan = $this->route('loan');

        return $this->user()->can('create', CollateralItem::class) && $this->user()->can('view', $loan);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->route('collateralItem') instanceof CollateralItem ? 'sometimes' : 'required';

        return [
            'type' => [$required, 'string', Rule::in(config('loans.collateral.types'))],
            'weight_grams' => [$required, 'numeric', 'decimal:0,3', 'gt:0', 'max:'.self::MAX_WEIGHT],
            'karat' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.config('loans.collateral.max_karat')],
            'estimated_value' => [$required, ...array_slice(MoneyRules::positive(), 1)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'received_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            'reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:500'],
            'loan' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'The collateral type must be one of: '.implode(', ', config('loans.collateral.types')).'.',
            'weight_grams.gt' => 'The weight must be greater than zero.',
            'weight_grams.decimal' => 'The weight may have at most 3 decimal places.',
            'karat.max' => 'The karat cannot exceed '.config('loans.collateral.max_karat').'.',
            'estimated_value.decimal' => 'The estimated value may have at most 2 decimal places.',
            'loan.prohibited' => 'Collateral cannot be moved to another loan.',
            'status.prohibited' => 'Use the release action to change the status.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $details = $this->safe()->except(['reason', 'loan', 'status']);

        // Keep decimals as strings from the request onwards.
        foreach (['weight_grams', 'karat', 'estimated_value'] as $field) {
            if (isset($details[$field])) {
                $details[$field] = (string) $details[$field];
            }
        }

        return $details;
    }
}
