<?php

namespace App\Domain\Payment;

use App\Domain\Interest\InterestScheduleService;
use App\Domain\Ledger\CustomerLedgerService;
use App\Domain\Loan\LoanHistory;
use App\Domain\Loan\LoanService;
use App\Enums\LedgerEntryType;
use App\Enums\LoanEventType;
use App\Enums\PaymentStatus;
use App\Models\InterestPeriod;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses (voids) a posted payment (docs/08 Payments 3, docs/11 "Payment reversal"). In ONE transaction:
 *
 *   1. lock the loan, then the payment; refuse a second reversal and payments on loans that are not open
 *   2. refuse a principal reversal once a newer interest period has started (its interest was charged on
 *      the reduced principal — business-decided, like the backdating limit)
 *   3. take the payment's interest back off each period it paid, restore its principal
 *   4. mark the payment reversed with actor, time and reason — its amount and allocation rows stay
 *      untouched, so the original remains visible in history
 *   5. post compensating ledger entries (payment_reversed; fee_reversed for a fee)
 *   6. refresh period statuses, next due date and the loan's overdue status; record the audit event
 *
 * Customer figures (interest due, missed periods, last payment) are derived from non-reversed payments,
 * so they are corrected by the same commit.
 */
class PaymentReversalService
{
    public function __construct(
        private readonly InterestScheduleService $schedule,
        private readonly LoanService $loans,
        private readonly CustomerLedgerService $ledger,
        private readonly LoanHistory $history,
    ) {}

    public function reverse(Payment $payment, User $actor, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $reason): Payment {
            /** @var Loan $loan */
            $loan = Loan::query()->whereKey($payment->loan_id)->lockForUpdate()->firstOrFail();
            /** @var Payment $locked */
            $locked = Payment::query()->with('allocations')->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            $this->guard($locked, $loan);

            $interest = $principal = $fee = '0.00';
            $periods = [];

            foreach ($locked->allocations as $allocation) {
                $interest = Money::add($interest, $allocation->interest_amount);
                $principal = Money::add($principal, $allocation->principal_amount);
                $fee = Money::add($fee, $allocation->fee_amount);

                if ($allocation->interest_period_id !== null && Money::isPositive($allocation->interest_amount)) {
                    $periods[] = $this->restoreInterest($allocation);
                }
            }

            if (Money::isPositive($principal)) {
                $restored = Money::add($loan->outstanding_principal, $principal);

                if (Money::cmp($restored, $loan->principal) > 0) {
                    // Would only happen if the stored balances were already inconsistent: never paper over it.
                    throw new \LogicException("Reversing {$locked->receipt_no} would exceed the loan principal.");
                }

                $loan->update(['outstanding_principal' => $restored]);
            }

            $locked->forceFill([
                'status' => PaymentStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            $links = ['loan' => $loan, 'payment' => $locked, 'actor' => $actor];
            $this->ledger->post($loan->customer_id, LedgerEntryType::PaymentReversed, $locked->amount, today(),
                "Payment {$locked->receipt_no} reversed: {$reason}", $locked->receipt_no, $links);

            if (Money::isPositive($fee)) {
                $this->ledger->post($loan->customer_id, LedgerEntryType::FeeReversed, $fee, today(),
                    "Fee of reversed payment {$locked->receipt_no}", $locked->receipt_no, $links);
            }

            $this->schedule->sync($loan);
            $this->loans->syncOverdueStatus($loan);

            $this->history->record($loan, LoanEventType::PaymentReversed, $actor, [
                'receipt_no' => $locked->receipt_no,
                'amount' => $locked->amount,
                'reason' => $reason,
                'interest' => $interest,
                'principal' => $principal,
                'fee' => $fee,
                'periods' => $periods,
            ]);

            return $payment->setRawAttributes($locked->getAttributes(), true)->load('allocations');
        });
    }

    private function guard(Payment $payment, Loan $loan): void
    {
        if ($payment->status === PaymentStatus::Reversed) {
            throw ValidationException::withMessages(['status' => "Payment {$payment->receipt_no} has already been reversed."]);
        }

        if (! $loan->status->isOpen()) {
            throw ValidationException::withMessages([
                'status' => "Payments can only be reversed on active or overdue loans (loan {$loan->loan_no} is {$loan->status->value}).",
            ]);
        }

        $principal = $payment->allocations->reduce(fn (string $sum, PaymentAllocation $a) => Money::add($sum, $a->principal_amount), '0.00');
        $newerPeriod = InterestPeriod::query()
            ->where('loan_id', $loan->id)
            ->whereDate('period_start', '>', $payment->payment_date->toDateString())
            ->min('period_start');

        if (Money::isPositive($principal) && $newerPeriod !== null) {
            $since = substr((string) $newerPeriod, 0, 10);

            throw ValidationException::withMessages([
                'status' => "Payment {$payment->receipt_no} reduced principal and a newer interest period started on {$since}; its interest was charged on the reduced principal, so the payment can no longer be reversed.",
            ]);
        }
    }

    /**
     * Takes the allocation's interest back off its period.
     *
     * @return array{period_start: string, interest: string}
     */
    private function restoreInterest(PaymentAllocation $allocation): array
    {
        /** @var InterestPeriod $period */
        $period = InterestPeriod::query()->whereKey($allocation->interest_period_id)->lockForUpdate()->firstOrFail();
        $paid = Money::sub($period->paid_interest, $allocation->interest_amount);

        if (Money::isNegative($paid)) {
            throw new \LogicException("Interest paid on period {$period->period_start->toDateString()} would become negative.");
        }

        $period->update([
            'paid_interest' => $paid,
            'paid_at' => Money::cmp($paid, $period->expected_interest) >= 0 ? $period->paid_at : null,
        ]);

        return ['period_start' => $period->period_start->toDateString(), 'interest' => $allocation->interest_amount];
    }
}
