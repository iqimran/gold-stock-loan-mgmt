<?php

namespace App\Http\Requests\Customers;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Customer::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', Rule::in([...CustomerStatus::values(), 'all'])],
            'registered_from' => ['nullable', 'date_format:Y-m-d'],
            'registered_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:registered_from'],
            'overdue' => ['nullable', 'boolean'],
            'min_missed' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{q: string, status: string, registered_from: ?string, registered_to: ?string, overdue: bool, min_missed: ?int}
     */
    public function filters(): array
    {
        return [
            'q' => trim((string) $this->validated('q', '')),
            'status' => $this->validated('status') ?? CustomerStatus::Active->value,
            'registered_from' => $this->validated('registered_from'),
            'registered_to' => $this->validated('registered_to'),
            'overdue' => $this->boolean('overdue'),
            'min_missed' => $this->validated('min_missed') ? (int) $this->validated('min_missed') : null,
        ];
    }
}
