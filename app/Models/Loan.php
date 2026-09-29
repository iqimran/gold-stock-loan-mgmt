<?php

namespace App\Models;

use App\Domain\Interest\InterestSettings;
use App\Enums\InterestPeriodUnit;
use App\Enums\InterestRateType;
use App\Enums\LoanStatus;
use App\Models\Concerns\HasUserstamps;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Collateralised loan. Authorized by App\Policies\LoanPolicy; every change goes through
 * App\Domain\Loan\LoanService (status rules: LoanStatusTransitions). Addressed publicly by loan_no.
 *
 * @property int $id
 * @property string $loan_no
 * @property int $customer_id
 * @property string $principal
 * @property string $outstanding_principal
 * @property string $interest_rate
 * @property InterestRateType $interest_rate_type
 * @property InterestPeriodUnit $interest_period_unit
 * @property LoanStatus $status
 * @property Carbon $start_date
 * @property ?Carbon $next_due_date
 * @property ?Carbon $closed_at
 * @property ?string $notes
 */
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use HasFactory, HasUserstamps;

    protected $fillable = [
        'loan_no', 'customer_id', 'principal', 'outstanding_principal', 'interest_rate', 'interest_rate_type',
        'interest_period_unit', 'status', 'start_date', 'next_due_date', 'closed_at', 'notes',
        // The loan's interest method, fixed at creation (App\Domain\Interest\InterestSettings::forLoan).
        'interest_base', 'interest_due_timing', 'yearly_rate_conversion',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'outstanding_principal' => 'decimal:2',
            'interest_rate' => 'decimal:4',
            'interest_rate_type' => InterestRateType::class,
            'interest_period_unit' => InterestPeriodUnit::class,
            'status' => LoanStatus::class,
            'start_date' => 'date',
            'next_due_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Every new loan takes the interest method in force at creation (Settings); later settings changes
     * never reach an existing loan (business-decided).
     */
    protected static function booted(): void
    {
        static::creating(function (Loan $loan): void {
            foreach (app(InterestSettings::class)->toLoanAttributes() as $attribute => $value) {
                $loan->{$attribute} ??= $value;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'loan_no';
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<CollateralItem, $this>
     */
    public function collateralItems(): HasMany
    {
        return $this->hasMany(CollateralItem::class);
    }

    /**
     * @return HasMany<InterestPeriod, $this>
     */
    public function interestPeriods(): HasMany
    {
        return $this->hasMany(InterestPeriod::class)->orderBy('period_start');
    }

    /**
     * @return HasMany<LoanEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(LoanEvent::class);
    }
}
