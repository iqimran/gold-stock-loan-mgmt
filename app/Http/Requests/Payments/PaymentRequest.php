<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentType;
use App\Models\Loan;
use App\Models\Payment;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a payment submission. Only what the cashier enters is accepted (loan, amount, type, method,
 * date, reference); balances and the allocation are always computed by App\Domain\Payment\PaymentService.
 * An optional idempotency key (field or Idempotency-Key header) makes retries safe.
 */
class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Payment::class);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'loan' => ['required', 'string', Rule::exists(Loan::class, 'loan_no')],
            'customer' => ['nullable', 'string', 'max:30'],
            'type' => ['required', Rule::in(PaymentType::values())],
            'amount' => MoneyRules::positive(),
            'method' => ['required', Rule::in(config('loans.payment_methods'))],
            // Future dates are refused here for immediate feedback; the backdating limit is checked by PaymentService.
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.decimal' => 'The amount may have at most 2 decimal places.',
            'method.in' => 'The payment method must be one of: '.implode(', ', config('loans.payment_methods')).'.',
        ];
    }

    public function loan(): Loan
    {
        return Loan::where('loan_no', $this->validated('loan'))->firstOrFail();
    }

    /**
     * @return array{type: string, amount: string, method: string, payment_date: string, reference: ?string, notes: ?string, customer: ?string}
     */
    public function payment(): array
    {
        $validated = $this->validated();

        return [
            'type' => $validated['type'],
            'amount' => (string) $validated['amount'],
            'method' => $validated['method'],
            'payment_date' => $validated['payment_date'],
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'customer' => $validated['customer'] ?? null,
        ];
    }
}
