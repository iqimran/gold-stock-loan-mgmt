<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Concerns\HasUserstamps;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money received against a loan. Posted only through App\Domain\Payment\PaymentService; its amount and
 * allocation are never edited afterwards (a reversal is a separate, audited action). Addressed publicly
 * by receipt_no. Authorized by App\Policies\PaymentPolicy.
 *
 * @property int $id
 * @property string $receipt_no
 * @property ?string $idempotency_key
 * @property int $customer_id
 * @property int $loan_id
 * @property PaymentType $type
 * @property string $amount
 * @property string $method
 * @property Carbon $payment_date
 * @property ?string $reference
 * @property ?string $notes
 * @property PaymentStatus $status
 * @property ?Carbon $reversed_at
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUserstamps;

    protected $fillable = [
        'receipt_no', 'idempotency_key', 'customer_id', 'loan_id', 'type', 'amount', 'method', 'payment_date',
        'reference', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'status' => PaymentStatus::class,
            'reversed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'receipt_no';
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
     * @return BelongsTo<User, $this>
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }
}
