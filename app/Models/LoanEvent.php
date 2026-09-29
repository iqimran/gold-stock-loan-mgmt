<?php

namespace App\Models;

use App\Enums\LoanEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a loan's history. Append-only: written by App\Domain\Loan\LoanService.
 *
 * @property LoanEventType $event_type
 * @property array<string, mixed> $payload
 */
class LoanEvent extends Model
{
    protected $fillable = ['loan_id', 'event_type', 'event_date', 'payload', 'actor_id'];

    protected function casts(): array
    {
        return [
            'event_type' => LoanEventType::class,
            'event_date' => 'datetime',
            'payload' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
