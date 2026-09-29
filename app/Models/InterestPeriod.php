<?php

namespace App\Models;

use App\Enums\InterestPeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One explicit interest period of a loan. Created by App\Domain\Interest\InterestScheduleService;
 * expected_interest is a historical fact once generated. paid_interest is written by the payment module.
 *
 * @property int $loan_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $due_date
 * @property string $expected_interest
 * @property string $paid_interest
 * @property InterestPeriodStatus $status
 * @property ?Carbon $paid_at
 * @property ?Carbon $waived_at
 */
class InterestPeriod extends Model
{
    protected $fillable = [
        'loan_id', 'period_start', 'period_end', 'due_date', 'expected_interest', 'paid_interest', 'status',
        'paid_at', 'waived_at', 'waived_by', 'waiver_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'expected_interest' => 'decimal:2',
            'paid_interest' => 'decimal:2',
            'status' => InterestPeriodStatus::class,
            'paid_at' => 'datetime',
            'waived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
