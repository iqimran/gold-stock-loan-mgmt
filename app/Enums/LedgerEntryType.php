<?php

namespace App\Enums;

/**
 * Customer ledger entry types (business-decided full statement). Debits increase what the customer
 * owes, credits reduce it; balance_after is what the customer owes after the entry.
 */
enum LedgerEntryType: string
{
    /** Debit: principal handed over when the loan is activated. */
    case LoanDisbursed = 'loan_disbursed';

    /** Debit: a period's interest, posted when it falls due (or, on closure, the interest collected for a final period). */
    case InterestCharged = 'interest_charged';

    /** Debit: a fee collected at the counter (paired with its payment). */
    case FeeCharged = 'fee_charged';

    /** Credit: money received. */
    case PaymentReceived = 'payment_received';

    /** Credit: an activated loan was cancelled; clears what the loan still showed as owed. */
    case LoanCancelled = 'loan_cancelled';

    public function isDebit(): bool
    {
        return in_array($this, [self::LoanDisbursed, self::InterestCharged, self::FeeCharged], true);
    }
}
