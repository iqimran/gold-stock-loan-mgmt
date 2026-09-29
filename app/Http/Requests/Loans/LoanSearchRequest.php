<?php

namespace App\Http\Requests\Loans;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoanSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Loan::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:191'],
            'customer' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::in([...LoanStatus::values(), 'open'])],
            'started_from' => ['nullable', 'date_format:Y-m-d'],
            'started_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:started_from'],
            'due_by' => ['nullable', 'date_format:Y-m-d'],
            'overdue' => ['nullable', 'boolean'],
            'rate_min' => MoneyRules::rate(false),
            'rate_max' => [...MoneyRules::rate(false), Rule::when($this->filled('rate_min'), 'gte:rate_min')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{q: string, customer: ?string, status: ?string, started_from: ?string, started_to: ?string, due_by: ?string, overdue: bool, rate_min: ?string, rate_max: ?string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'customer' => $validated['customer'] ?? null,
            'status' => $validated['status'] ?? null,
            'started_from' => $validated['started_from'] ?? null,
            'started_to' => $validated['started_to'] ?? null,
            'due_by' => $validated['due_by'] ?? null,
            'overdue' => $this->boolean('overdue'),
            'rate_min' => isset($validated['rate_min']) ? (string) $validated['rate_min'] : null,
            'rate_max' => isset($validated['rate_max']) ? (string) $validated['rate_max'] : null,
        ];
    }
}
