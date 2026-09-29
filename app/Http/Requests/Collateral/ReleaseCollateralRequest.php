<?php

namespace App\Http\Requests\Collateral;

use App\Models\CollateralItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Releasing collateral is a sensitive action: it needs collateral.release and a reason (docs/05, docs/11).
 */
class ReleaseCollateralRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CollateralItem $item */
        $item = $this->route('collateralItem');

        return $this->user()->can('release', $item);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
