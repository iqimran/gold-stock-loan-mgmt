<?php

namespace App\Domain\Payment;

use App\Domain\Audit\AuditTrail;
use App\Domain\Interest\InterestScheduleService;
use App\Domain\Ledger\CustomerLedgerService;
use App\Domain\Loan\LoanHistory;
use App\Domain\Loan\LoanService;
use App\Domain\Settings\LoanSettings;
use App\Enums\LedgerEntryType;
use App\Enums\LoanEventType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\InterestPeriod;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Posts a payment atomically (docs/02 "Transactions", docs/07 "PaymentService"). In ONE transaction:
 *
 *   1. lock the loan (serializes every balance change on it) and validate loan, customer and date
 *   2. bring the loan's interest periods up to date (the daily run may not have happened yet)
 *   3. lock its unpaid periods and allocate the amount (server-side; client figures are never used)
 *   4. create the payment (with a generated receipt number) and its allocation rows
 *   5. add interest to the periods, reduce outstanding principal
 *   6. post the ledger entries (fee charged, payment received)
 *   7. refresh period statuses, next due date and the loan's overdue status
 *   8. record the audit event in the loan history
 *
 * Anything failing rolls everything back — the receipt number included. A payment is never edited
 * afterwards; reversal is a separate action. Idempotent per idempotency key.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentAllocator $allocator,
        private readonly InterestScheduleService $schedule,
        private readonly LoanService $loans,
        private readonly CustomerLedgerService $ledger,
        private readonly LoanHistory $history,
        private readonly DocumentNumberGenerator $numbers,
        private readonly LoanSettings $settings,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{type: string, amount: string, method: string, payment_date: string, reference?: ?string, notes?: ?string, customer?: ?string}  $data
     */
    public function post(Loan $loan, array $data, User $actor, ?string $idempotencyKey = null): Payment
    {
        if ($idempotencyKey !== null && ($existing = $this->replay($idempotencyKey, $loan, $data, $actor))) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->postLocked($loan, $data, $actor, $idempotencyKey));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same idempotency key committed first.
            if ($idempotencyKey !== null && ($existing = $this->replay($idempotencyKey, $loan, $data, $actor))) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postLocked(Loan $loan, array $data, User $actor, ?string $idempotencyKey): Payment
    {
        $today = today();
        /** @var Loan $locked */
        $locked = Loan::query()->with('customer')->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();
        $type = PaymentType::from($data['type']);

        $this->validate($locked, $data, $today);

        // Periods that have started by today exist (and statuses are current) before allocating.
        $this->schedule->sync($locked, $today);

        $openPeriods = InterestPeriod::query()
            ->where('loan_id', $locked->id)
            ->whereNull('waived_at')
            ->whereColumn('paid_interest', '<', 'expected_interest')
            ->orderBy('due_date')
            ->orderBy('period_start')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $allocation = $this->allocator->allocate(
            $type,
            (string) $data['amount'],
            $openPeriods->map(fn (InterestPeriod $p) => ['id' => $p->id, 'expected' => $p->expected_interest, 'paid' => $p->paid_interest])->values()->all(),
            $locked->outstanding_principal,
        );

        $payment = new Payment([
            'receipt_no' => $this->numbers->nextIn($this->settings->numbering('receipt')),
            'idempotency_key' => $idempotencyKey,
            'customer_id' => $locked->customer_id,
            'loan_id' => $locked->id,
            'type' => $type,
            'amount' => $allocation->total(),
            'method' => $data['method'],
            'payment_date' => $data['payment_date'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => PaymentStatus::Posted,
        ]);
        // The poster, explicitly (not whoever happens to be signed in): replays are scoped to them.
        $payment->created_by = $actor->id;
        $payment->save();

        foreach ($allocation->periods as $periodId => $interest) {
            $period = $openPeriods[$periodId];
            $paid = Money::add($period->paid_interest, $interest);
            $period->update(['paid_interest' => $paid, 'paid_at' => Money::cmp($paid, $period->expected_interest) >= 0 ? now() : $period->paid_at]);
            $payment->allocations()->create(['interest_period_id' => $periodId, 'interest_amount' => $interest, 'total_amount' => $interest]);
        }

        if (Money::isPositive($allocation->principal)) {
            $locked->update(['outstanding_principal' => Money::sub($locked->outstanding_principal, $allocation->principal)]);
            $payment->allocations()->create(['principal_amount' => $allocation->principal, 'total_amount' => $allocation->principal]);
        }

        if (Money::isPositive($allocation->fee)) {
            $payment->allocations()->create(['fee_amount' => $allocation->fee, 'total_amount' => $allocation->fee]);
            $this->ledger->post($locked->customer_id, LedgerEntryType::FeeCharged, $allocation->fee, $payment->payment_date,
                "Fee on loan {$locked->loan_no}", $payment->receipt_no, ['loan' => $locked, 'payment' => $payment, 'actor' => $actor]);
        }

        $this->ledger->post($locked->customer_id, LedgerEntryType::PaymentReceived, $payment->amount, $payment->payment_date,
            "Payment {$payment->receipt_no} ({$type->value}) on loan {$locked->loan_no}", $payment->receipt_no,
            ['loan' => $locked, 'payment' => $payment, 'actor' => $actor]);

        // Statuses, next due date, interest now due, and the loan's overdue status reflect the payment.
        $this->schedule->sync($locked, $today);
        $this->loans->syncOverdueStatus($locked);

        $this->audit->record('payment.created', $payment, [], [
            'receipt_no' => $payment->receipt_no,
            'loan_no' => $locked->loan_no,
            'type' => $type->value,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'payment_date' => $payment->payment_date->toDateString(),
            'reference' => $payment->reference,
            'interest' => $allocation->interest(),
            'principal' => $allocation->principal,
            'fee' => $allocation->fee,
            'outstanding_principal_after' => $locked->fresh()->outstanding_principal,
        ], "Payment {$payment->receipt_no} of {$payment->amount} on loan {$locked->loan_no}", $actor->id);

        $this->history->record($locked, LoanEventType::PaymentPosted, $actor, [
            'receipt_no' => $payment->receipt_no,
            'type' => $type->value,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'payment_date' => $payment->payment_date->toDateString(),
            'interest' => $allocation->interest(),
            'principal' => $allocation->principal,
            'fee' => $allocation->fee,
            'periods' => $openPeriods->only(array_keys($allocation->periods))
                ->map(fn (InterestPeriod $p) => ['period_start' => $p->period_start->toDateString(), 'interest' => $allocation->periods[$p->id]])
                ->values()->all(),
        ]);

        return $payment->load('allocations');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(Loan $loan, array $data, CarbonInterface $today): void
    {
        if (! $loan->status->isOpen()) {
            throw ValidationException::withMessages(['loan' => "Payments can only be posted on active or overdue loans (this loan is {$loan->status->value})."]);
        }

        if (! empty($data['customer']) && $data['customer'] !== $loan->customer->customer_no) {
            throw ValidationException::withMessages(['customer' => "Loan {$loan->loan_no} does not belong to customer {$data['customer']}."]);
        }

        $date = (string) $data['payment_date'];
        $earliest = $this->earliestPaymentDate($loan);

        if ($date > $today->toDateString()) {
            throw ValidationException::withMessages(['payment_date' => 'The payment date cannot be in the future.']);
        }

        if ($date < $earliest) {
            throw ValidationException::withMessages([
                'payment_date' => "The payment date cannot be before {$earliest} (start of the loan's current interest period).",
            ]);
        }
    }

    /**
     * Business-decided backdating limit: not before the start of the latest generated interest period
     * (so interest that was already charged can never be invalidated), nor before the loan starts.
     */
    private function earliestPaymentDate(Loan $loan): string
    {
        $latestStart = InterestPeriod::query()->where('loan_id', $loan->id)->max('period_start');

        return $latestStart ? substr((string) $latestStart, 0, 10) : $loan->start_date->toDateString();
    }

    /**
     * The payment already posted with this key, or null. The same key with different content is a conflict.
     *
     * @param  array<string, mixed>  $data
     */
    private function replay(string $key, Loan $loan, array $data, User $actor): ?Payment
    {
        $existing = Payment::query()->with('allocations')->where('idempotency_key', $key)->first();

        if ($existing === null) {
            return null;
        }

        // A key belongs to the user who used it: another user's retry never returns (or reveals) their payment.
        if ($existing->created_by !== $actor->id) {
            throw new ConflictHttpException('This idempotency key has already been used.');
        }

        $same = $existing->loan_id === $loan->id
            && $existing->type->value === $data['type']
            && Money::cmp($existing->amount, Money::of((string) $data['amount'])) === 0;

        if (! $same) {
            throw new ConflictHttpException('This idempotency key was already used for a different payment.');
        }

        return $existing;
    }
}
