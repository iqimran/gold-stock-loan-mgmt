<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a customer's statement. Append-only: written by App\Domain\Ledger\CustomerLedgerService,
 * never edited; corrections are compensating entries.
 *
 * @property LedgerEntryType $entry_type
 * @property string $debit
 * @property string $credit
 * @property string $balance_after
 * @property Carbon $entry_date
 */
class LedgerEntry extends Model
{
    protected $fillable = [
        'customer_id', 'loan_id', 'payment_id', 'interest_period_id', 'entry_type', 'debit', 'credit',
        'balance_after', 'entry_date', 'description', 'reference', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => LedgerEntryType::class,
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'entry_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
