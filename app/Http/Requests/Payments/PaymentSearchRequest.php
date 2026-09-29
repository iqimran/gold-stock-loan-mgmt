<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Payment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Payment::class);
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
            'type' => ['nullable', Rule::in(PaymentType::values())],
            'method' => ['nullable', Rule::in(config('loans.payment_methods'))],
            'status' => ['nullable', Rule::in(PaymentStatus::values())],
            'paid_from' => ['nullable', 'date_format:Y-m-d'],
            'paid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:paid_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_merge(
            ['q' => '', 'loan' => null, 'customer' => null, 'type' => null, 'method' => null, 'status' => null, 'paid_from' => null, 'paid_to' => null],
            array_filter($this->safe()->except('per_page'), fn ($value) => $value !== null && $value !== ''),
        );
    }
}
