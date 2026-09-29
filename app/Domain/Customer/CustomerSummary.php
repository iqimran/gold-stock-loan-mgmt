<?php

namespace App\Domain\Customer;

use App\Enums\LoanStatus;
use App\Models\Customer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Derived customer figures, computed from the facts on every read (docs/03 "Derived values": nothing
 * is cached, so there is nothing to invalidate). Only open loans (LoanStatus::open) count.
 *
 * Missed interest period (docs/08 Interest 3–5; the reset condition was decided by the business):
 * due date before today, not waived, and paid_interest < expected_interest. A partial payment does not
 * reset the streak; only a fully paid or waived past-due period does. A loan's consecutive-missed count
 * is the run of missed periods after its last settled past-due period; a customer's is the worst
 * (maximum) over their open loans. No grace period is applied until Settings defines one.
 *
 * Columns added: active_loans_count, total_interest_due, consecutive_missed, next_due_date,
 * last_payment_date, last_payment_amount.
 */
class CustomerSummary
{
    /** Casts for the added columns; decimal casts keep money exact on every driver. */
    public const CASTS = [
        'active_loans_count' => 'integer',
        'total_interest_due' => 'decimal:2',
        'consecutive_missed' => 'integer',
        'next_due_date' => 'date',
        'last_payment_date' => 'date',
        'last_payment_amount' => 'decimal:2',
    ];

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function apply(Builder $query, ?CarbonInterface $today = null): Builder
    {
        $today = ($today ?? today())->toDateString();

        return $query
            ->select('customers.*')
            ->selectSub($this->activeLoansCount(), 'active_loans_count')
            ->selectSub($this->totalInterestDue($today), 'total_interest_due')
            ->selectSub($this->consecutiveMissed($today), 'consecutive_missed')
            ->selectSub($this->nextDueDate(), 'next_due_date')
            ->selectSub($this->lastPayment('payment_date'), 'last_payment_date')
            ->selectSub($this->lastPayment('amount'), 'last_payment_amount')
            ->withCasts(self::CASTS);
    }

    /**
     * Customers with at least one missed (past-due, unpaid or partially paid) interest period.
     *
     * @param  Builder<Customer>  $query
     */
    public function whereOverdue(Builder $query, ?CarbonInterface $today = null): void
    {
        $today = ($today ?? today())->toDateString();

        $query->whereExists(fn (QueryBuilder $periods) => $this->missedPeriods($periods->selectRaw('1'), $today));
    }

    /**
     * Customers whose consecutive-missed count (worst open loan) is at least $threshold.
     *
     * @param  Builder<Customer>  $query
     */
    public function whereConsecutiveMissedAtLeast(Builder $query, int $threshold, ?CarbonInterface $today = null): void
    {
        $query->where($this->consecutiveMissed(($today ?? today())->toDateString()), '>=', $threshold);
    }

    private function openLoans(): QueryBuilder
    {
        return DB::table('loans as l')
            ->whereColumn('l.customer_id', 'customers.id')
            ->whereIn('l.status', LoanStatus::open());
    }

    private function activeLoansCount(): QueryBuilder
    {
        return $this->openLoans()->selectRaw('count(*)');
    }

    private function nextDueDate(): QueryBuilder
    {
        return $this->openLoans()->selectRaw('min(l.next_due_date)');
    }

    /**
     * Interest due today or earlier and not yet paid (partially paid periods count their remainder).
     */
    private function totalInterestDue(string $today): QueryBuilder
    {
        return $this->openLoans()
            ->join('interest_periods as p', 'p.loan_id', '=', 'l.id')
            ->where('p.due_date', '<=', $today)
            ->whereNull('p.waived_at')
            ->whereColumn('p.paid_interest', '<', 'p.expected_interest')
            ->selectRaw('coalesce(sum(p.expected_interest - p.paid_interest), 0)');
    }

    /**
     * Worst open loan's trailing run of missed periods; null (no missed periods) is presented as 0.
     */
    private function consecutiveMissed(string $today): QueryBuilder
    {
        return $this->missedPeriods(DB::query(), $today)
            ->whereNotExists(fn (QueryBuilder $settled) => $settled
                ->selectRaw('1')
                ->from('interest_periods as s')
                ->whereColumn('s.loan_id', 'p.loan_id')
                ->whereColumn('s.due_date', '>', 'p.due_date')
                ->where('s.due_date', '<', $today)
                ->where(fn (QueryBuilder $paid) => $paid
                    ->whereNotNull('s.waived_at')
                    ->orWhereColumn('s.paid_interest', '>=', 's.expected_interest')))
            ->groupBy('p.loan_id')
            ->selectRaw('count(*)')
            ->orderByRaw('count(*) desc')
            ->limit(1);
    }

    /**
     * Missed periods (see class doc) on the customer's open loans.
     */
    private function missedPeriods(QueryBuilder $query, string $today): QueryBuilder
    {
        return $query
            ->from('interest_periods as p')
            ->join('loans as l', 'l.id', '=', 'p.loan_id')
            ->whereColumn('l.customer_id', 'customers.id')
            ->whereIn('l.status', LoanStatus::open())
            ->where('p.due_date', '<', $today)
            ->whereNull('p.waived_at')
            ->whereColumn('p.paid_interest', '<', 'p.expected_interest');
    }

    /**
     * Latest payment that has not been reversed (reversal is recorded by reversed_at).
     */
    private function lastPayment(string $column): QueryBuilder
    {
        return DB::table('payments as pay')
            ->whereColumn('pay.customer_id', 'customers.id')
            ->whereNull('pay.reversed_at')
            ->orderByDesc('pay.payment_date')
            ->orderByDesc('pay.id')
            ->limit(1)
            ->select("pay.{$column}");
    }
}
