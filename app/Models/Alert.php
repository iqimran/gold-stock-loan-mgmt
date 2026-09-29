<?php

namespace App\Models;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A missed-interest alert. Written only by App\Domain\Alert\MissedPaymentAlertService; information only
 * (never changes loan, collateral or customer state).
 *
 * @property AlertType $type
 * @property AlertStatus $status
 * @property int $threshold
 * @property Carbon $triggered_at
 * @property ?Carbon $resolved_at
 */
class Alert extends Model
{
    protected $fillable = ['customer_id', 'loan_id', 'interest_period_id', 'type', 'threshold', 'message', 'status', 'triggered_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'status' => AlertStatus::class,
            'threshold' => 'integer',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The period that made the streak reach the threshold.
     *
     * @return BelongsTo<InterestPeriod, $this>
     */
    public function interestPeriod(): BelongsTo
    {
        return $this->belongsTo(InterestPeriod::class);
    }
}
