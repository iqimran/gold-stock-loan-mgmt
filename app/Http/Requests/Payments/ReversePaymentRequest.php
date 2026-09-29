<?php

namespace App\Http\Requests\Payments;

use App\Models\Payment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversing a payment is a sensitive action: payments.reverse and a reason are required (docs/05, docs/11).
 */
class ReversePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Payment $payment */
        $payment = $this->route('payment');

        return $this->user()->can('reverse', $payment);
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
