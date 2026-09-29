<?php

namespace App\Domain\Loan;

use App\Enums\LoanEventType;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\User;

/**
 * Appends entries to a loan's history (loan_events): who did what, when, with a structured payload.
 * The single writer for loan, collateral and (later) payment events.
 */
final class LoanHistory
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Loan $loan, LoanEventType $type, ?User $actor, array $payload = []): LoanEvent
    {
        return $loan->events()->create([
            'event_type' => $type,
            'event_date' => now(),
            'payload' => $payload,
            'actor_id' => $actor?->id,
        ]);
    }
}
