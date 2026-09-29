<?php

namespace App\Http\Requests\Loans;

use App\Models\Loan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * activate / close / cancel. Cancelling is a sensitive action and requires a reason (docs/05);
 * closing accepts an optional note. Authorization uses the policy ability named like the action.
 */
class LoanStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Loan $loan */
        $loan = $this->route('loan');

        return $this->user()->can($this->action(), $loan);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return match ($this->action()) {
            'cancel' => ['reason' => ['required', 'string', 'min:3', 'max:500']],
            'close' => ['note' => ['nullable', 'string', 'max:500']],
            default => [],
        };
    }

    public function action(): string
    {
        return $this->route()->getActionMethod();
    }
}
