<?php

namespace App\Http\Requests\Loans;

use App\Enums\CustomerStatus;
use App\Enums\InterestPeriodUnit;
use App\Enums\InterestRateType;
use App\Models\Customer;
use App\Models\Loan;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates loan create (POST) and update (PUT/PATCH). Status is never accepted here: it changes only
 * through the activate/close/cancel actions. Which fields may change in which status is enforced by
 * App\Domain\Loan\LoanService (terms are locked after activation).
 */
class LoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $loan instanceof Loan
            ? $this->user()->can('update', $loan)
            : $this->user()->can('create', Loan::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = ! $this->route('loan') instanceof Loan;
        $required = $creating ? 'required' : 'sometimes';

        return [
            // New loans only for active (not archived) customers; the customer cannot be changed later.
            'customer' => $creating
                ? ['required', 'string', Rule::exists(Customer::class, 'customer_no')->where('status', CustomerStatus::Active->value)]
                : ['prohibited'],
            'principal' => [$required, ...array_slice(MoneyRules::positive(), 1)],
            'interest_rate' => [$required, ...array_slice(MoneyRules::rate(), 1)],
            'interest_rate_type' => [$required, Rule::in(InterestRateType::values())],
            'interest_period_unit' => [$required, Rule::in(InterestPeriodUnit::values())],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer.exists' => 'The selected customer does not exist or is archived.',
            'customer.prohibited' => 'The customer of a loan cannot be changed.',
            'principal.decimal' => 'The principal may have at most 2 decimal places.',
            'interest_rate.decimal' => 'The interest rate may have at most 4 decimal places.',
        ];
    }

    public function customer(): Customer
    {
        return Customer::where('customer_no', $this->validated('customer'))->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public function terms(): array
    {
        $terms = $this->safe()->except('customer');

        // Keep amounts as strings: decimal-safe from the request onwards.
        foreach (['principal', 'interest_rate'] as $field) {
            if (array_key_exists($field, $terms)) {
                $terms[$field] = (string) $terms[$field];
            }
        }

        return $terms;
    }
}
