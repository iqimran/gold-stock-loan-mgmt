<?php

namespace App\Domain\Loan;

use App\Models\Loan;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Business-decided conditions for closing and cancelling a loan (docs/07 "Closing a loan").
 */
final class LoanSettlement
{
    /**
     * Why the loan cannot be closed yet; empty when it can. Closing requires outstanding principal = 0.00
     * and no interest period due today or earlier that is unpaid or partly paid (waived ones don't count).
     *
     * @return list<string>
     */
    public function closeBlockers(Loan $loan, ?CarbonInterface $today = null): array
    {
        $blockers = [];

        if (! Money::isZero($loan->outstanding_principal)) {
            $blockers[] = "Outstanding principal is {$loan->outstanding_principal}; it must be 0.00 to close the loan.";
        }

        $unpaid = DB::table('interest_periods')
            ->where('loan_id', $loan->id)
            ->where('due_date', '<=', ($today ?? today())->toDateString())
            ->whereNull('waived_at')
            ->whereColumn('paid_interest', '<', 'expected_interest')
            ->count();

        if ($unpaid > 0) {
            $blockers[] = "{$unpaid} interest period(s) due to date are unpaid or partly paid.";
        }

        return $blockers;
    }

    /**
     * An open loan can only be cancelled while no payment has been posted against it
     * (a reversed payment does not count).
     */
    public function hasPostedPayments(Loan $loan): bool
    {
        return DB::table('payments')->where('loan_id', $loan->id)->whereNull('reversed_at')->exists();
    }
}
