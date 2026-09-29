<?php

namespace App\Http\Requests\Collateral;

use App\Domain\Settings\LoanSettings;
use App\Enums\CollateralStatus;
use App\Models\CollateralItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollateralSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', CollateralItem::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:191'],
            'loan' => ['nullable', 'string', 'max:30'],
            'customer' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', Rule::in(app(LoanSettings::class)->collateralTypes())],
            'status' => ['nullable', Rule::in(CollateralStatus::values())],
            'received_from' => ['nullable', 'date_format:Y-m-d'],
            'received_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:received_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{q: string, loan: ?string, customer: ?string, type: ?string, status: ?string, received_from: ?string, received_to: ?string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'loan' => $validated['loan'] ?? null,
            'customer' => $validated['customer'] ?? null,
            'type' => $validated['type'] ?? null,
            'status' => $validated['status'] ?? null,
            'received_from' => $validated['received_from'] ?? null,
            'received_to' => $validated['received_to'] ?? null,
        ];
    }
}
