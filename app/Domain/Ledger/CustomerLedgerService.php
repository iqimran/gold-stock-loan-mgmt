<?php

namespace App\Domain\Ledger;

use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\InterestPeriod;
use App\Models\LedgerEntry;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The customer's statement (docs/07 "CustomerLedgerService"), business-decided as a full statement:
 * debits are what the customer owes (principal disbursed, interest as it falls due, fees), credits are
 * money received. balance_after is what the customer owes after each entry.
 *
 * Append-only: entries are never edited; corrections are compensating entries. The running balance is
 * computed under a row lock on the customer, so concurrent postings can never interleave. Callers lock
 * the loan first, then this service locks the customer (one lock order everywhere).
 */
class CustomerLedgerService
{
    /**
     * @param  array{loan?: ?Loan, payment?: ?Payment, period?: ?InterestPeriod, actor?: ?User}  $links
     */
    public function post(
        Customer|int $customer,
        LedgerEntryType $type,
        string $amount,
        CarbonInterface|string $date,
        string $description,
        string $reference,
        array $links = [],
    ): LedgerEntry {
        $amount = Money::of($amount);

        if (Money::isNegative($amount)) {
            throw new InvalidArgumentException('Ledger amounts are never negative; post a compensating entry instead.');
        }

        $customerId = $customer instanceof Customer ? $customer->id : $customer;

        return DB::transaction(function () use ($customerId, $type, $amount, $date, $description, $reference, $links): LedgerEntry {
            Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();

            $previous = (string) (LedgerEntry::query()->where('customer_id', $customerId)->orderByDesc('id')->value('balance_after') ?? '0.00');
            [$debit, $credit] = $type->isDebit() ? [$amount, '0.00'] : ['0.00', $amount];

            return LedgerEntry::create([
                'customer_id' => $customerId,
                'loan_id' => $links['loan']?->id ?? null,
                'payment_id' => $links['payment']?->id ?? null,
                'interest_period_id' => $links['period']?->id ?? null,
                'entry_type' => $type,
                'debit' => $debit,
                'credit' => $credit,
                'balance_after' => Money::sub(Money::add(Money::of($previous), $debit), $credit),
                'entry_date' => $date instanceof CarbonInterface ? $date->toDateString() : $date,
                'description' => $description,
                'reference' => $reference,
                'created_by' => $links['actor']?->id ?? null,
            ]);
        });
    }

    /**
     * Debit: principal handed over when the loan is activated (dated the loan's start date).
     */
    public function disburse(Loan $loan, ?User $actor): LedgerEntry
    {
        return $this->post($loan->customer_id, LedgerEntryType::LoanDisbursed, $loan->principal, $loan->start_date,
            "Loan {$loan->loan_no} disbursed", $loan->loan_no, ['loan' => $loan, 'actor' => $actor]);
    }

    /**
     * Debit each period's interest once it falls due (dated its due date). Idempotent: a period is charged
     * at most once (checked here, and unique interest_period_id + entry_type in the database). Waived and
     * zero-interest periods are not charged.
     *
     * @return int number of periods charged
     */
    public function chargeDueInterest(Loan $loan, CarbonInterface|string $today): int
    {
        $today = $today instanceof CarbonInterface ? $today->toDateString() : $today;
        $charged = 0;

        foreach ($this->uncharged($loan)->whereDate('due_date', '<=', $today)->get() as $period) {
            if (Money::isPositive($period->expected_interest)) {
                $this->chargeInterest($loan, $period, $period->expected_interest, $period->due_date, 'interest');
                $charged++;
            }
        }

        return $charged;
    }

    /**
     * On closure: periods that are not yet due but for which interest was already collected are charged
     * for the amount collected, so the statement of a closed loan balances.
     */
    public function chargeCollectedInterest(Loan $loan, CarbonInterface|string $date): void
    {
        foreach ($this->uncharged($loan)->where('paid_interest', '>', 0)->get() as $period) {
            $this->chargeInterest($loan, $period, $period->paid_interest, $date, 'interest collected for the final period');
        }
    }

    /**
     * Credit: an activated loan was cancelled, so whatever it still shows as owed is cleared.
     */
    public function clearCancelledLoan(Loan $loan, ?User $actor, CarbonInterface|string $date): ?LedgerEntry
    {
        $net = LedgerEntry::query()->where('loan_id', $loan->id)->selectRaw('coalesce(sum(debit), 0) - coalesce(sum(credit), 0) as net')->value('net');
        $net = Money::of((string) ($net ?? '0'));

        if (! Money::isPositive($net)) {
            return null;
        }

        return $this->post($loan->customer_id, LedgerEntryType::LoanCancelled, $net, $date,
            "Loan {$loan->loan_no} cancelled", $loan->loan_no, ['loan' => $loan, 'actor' => $actor]);
    }

    /**
     * What the customer owes according to the ledger (latest balance).
     */
    public function balance(Customer|int $customer): string
    {
        $customerId = $customer instanceof Customer ? $customer->id : $customer;

        return Money::of((string) (LedgerEntry::query()->where('customer_id', $customerId)->orderByDesc('id')->value('balance_after') ?? '0'));
    }

    /**
     * @return Builder<InterestPeriod>
     */
    private function uncharged(Loan $loan)
    {
        return InterestPeriod::query()
            ->where('loan_id', $loan->id)
            ->whereNull('waived_at')
            ->whereNotExists(fn ($entries) => $entries
                ->selectRaw('1')
                ->from('ledger_entries')
                ->whereColumn('ledger_entries.interest_period_id', 'interest_periods.id')
                ->where('ledger_entries.entry_type', LedgerEntryType::InterestCharged->value))
            ->orderBy('due_date')
            ->orderBy('period_start');
    }

    private function chargeInterest(Loan $loan, InterestPeriod $period, string $amount, CarbonInterface|string $date, string $label): void
    {
        $from = $period->period_start->toDateString();
        $to = $period->period_end->toDateString();

        $this->post($loan->customer_id, LedgerEntryType::InterestCharged, $amount, $date,
            "Loan {$loan->loan_no} {$label} {$from} – {$to}", $loan->loan_no, ['loan' => $loan, 'period' => $period]);
    }
}
