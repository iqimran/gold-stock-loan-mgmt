<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How part of a payment was applied: to one interest period, to principal, or as a fee.
 * total_amount = principal_amount + interest_amount + fee_amount (enforced by a CHECK constraint).
 *
 * @property ?int $interest_period_id
 * @property string $principal_amount
 * @property string $interest_amount
 * @property string $fee_amount
 * @property string $total_amount
 */
class PaymentAllocation extends Model
{
    protected $fillable = ['payment_id', 'interest_period_id', 'principal_amount', 'interest_amount', 'fee_amount', 'total_amount'];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<InterestPeriod, $this>
     */
    public function interestPeriod(): BelongsTo
    {
        return $this->belongsTo(InterestPeriod::class);
    }
}
