<?php

namespace App\Domain\Alert;

use App\Enums\LoanStatus;
use App\Models\InterestPeriod;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Due / overdue interest periods of open loans (dashboard "due interest" and "overdue accounts",
 * docs/10 "Due Report"). Unsettled periods only (not fully paid, not waived). Every condition is derived
 * from the facts for the business date, exactly like the period status resolver and the missed rule:
 *
 *   upcoming        due after today, nothing paid
 *   due             due today
 *   partially_paid  something paid, not yet overdue
 *   overdue         due before today (partly paid included)
 *   unpaid          any of the above (default)
 *
 * Each row carries its loan's consecutive-missed count, which also backs the "missed threshold" filter.
 */
class DueInterestQuery
{
    public const STATUSES = ['unpaid', 'upcoming', 'due', 'partially_paid', 'overdue'];

    /**
     * @param  array{status?: ?string, due_from?: ?string, due_to?: ?string, customer?: ?string, loan?: ?string, min_missed?: ?int}  $filters
     * @return Builder<InterestPeriod>
     */
    public function query(array $filters, ?CarbonInterface $today = null): Builder
    {
        $today = ($today ?? today())->toDateString();

        $query = InterestPeriod::query()
            ->with(['loan:id,loan_no,status,customer_id,next_due_date', 'loan.customer:id,customer_no,name,mobile'])
            ->select('interest_periods.*')
            ->selectSub($this->consecutiveMissed($today), 'consecutive_missed')
            ->whereNull('interest_periods.waived_at')
            ->whereColumn('interest_periods.paid_interest', '<', 'interest_periods.expected_interest')
            ->whereHas('loan', fn (Builder $loan) => $loan->whereIn('status', LoanStatus::open()));

        match ($filters['status'] ?? 'unpaid') {
            'upcoming' => $query->where('interest_periods.due_date', '>', $today)->where('interest_periods.paid_interest', '=', 0),
            'due' => $query->where('interest_periods.due_date', '=', $today),
            'partially_paid' => $query->where('interest_periods.due_date', '>=', $today)->where('interest_periods.paid_interest', '>', 0),
            'overdue' => $query->where('interest_periods.due_date', '<', $today),
            default => null,
        };

        return $query
            ->when($filters['due_from'] ?? null, fn (Builder $q, string $from) => $q->where('interest_periods.due_date', '>=', $from))
            ->when($filters['due_to'] ?? null, fn (Builder $q, string $to) => $q->where('interest_periods.due_date', '<=', $to))
            ->when($filters['loan'] ?? null, fn (Builder $q, string $loanNo) => $q->whereHas('loan', fn (Builder $l) => $l->where('loan_no', $loanNo)))
            ->when($filters['customer'] ?? null, fn (Builder $q, string $customerNo) => $q->whereHas('loan.customer', fn (Builder $c) => $c->where('customer_no', $customerNo)))
            ->when($filters['min_missed'] ?? null, fn (Builder $q, int $min) => $q->where($this->consecutiveMissed($today), '>=', $min))
            ->orderBy('interest_periods.due_date')
            ->orderBy('interest_periods.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, InterestPeriod>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * Unpaid interest and number of periods matching the filters (same conditions as the list).
     *
     * @param  array<string, mixed>  $filters
     * @return array{amount: string, periods: int, expected: string, paid: string, loans: int}
     */
    public function totals(array $filters, ?CarbonInterface $today = null): array
    {
        $row = $this->query($filters, $today)->reorder()->toBase()
            ->select([])
            ->selectRaw('coalesce(sum(interest_periods.expected_interest - interest_periods.paid_interest), 0) as amount, count(*) as periods')
            ->selectRaw('coalesce(sum(interest_periods.expected_interest), 0) as expected, coalesce(sum(interest_periods.paid_interest), 0) as paid')
            ->selectRaw('count(distinct interest_periods.loan_id) as loans')
            ->first();

        return [
            'amount' => Money::of((string) $row->amount),
            'periods' => (int) $row->periods,
            'expected' => Money::of((string) $row->expected),
            'paid' => Money::of((string) $row->paid),
            'loans' => (int) $row->loans,
        ];
    }

    /**
     * Correlated subquery: a loan's unpaid interest due today or earlier (the "due interest" rule of this
     * class, per loan). $loanIdColumn is the outer query's loan id column, e.g. "loans.id".
     */
    public function dueToDateForLoan(string $loanIdColumn, ?CarbonInterface $today = null): QueryBuilder
    {
        return DB::table('interest_periods as d')
            ->whereColumn('d.loan_id', $loanIdColumn)
            ->where('d.due_date', '<=', ($today ?? today())->toDateString())
            ->whereNull('d.waived_at')
            ->whereColumn('d.paid_interest', '<', 'd.expected_interest')
            ->selectRaw('coalesce(sum(d.expected_interest - d.paid_interest), 0)');
    }

    /**
     * Overdue interest per loan, oldest overdue first (dashboard "overdue accounts").
     *
     * @return list<array{loan_id: int, overdue_interest: string, overdue_periods: int, oldest_due_date: string, consecutive_missed: int}>
     */
    public function overdueByLoan(int $limit, ?CarbonInterface $today = null): array
    {
        $todayString = ($today ?? today())->toDateString();

        return $this->query(['status' => 'overdue'], $today)->reorder()->toBase()
            ->select('interest_periods.loan_id')
            ->selectRaw('sum(interest_periods.expected_interest - interest_periods.paid_interest) as overdue_interest, count(*) as overdue_periods, min(interest_periods.due_date) as oldest_due_date')
            ->selectSub($this->consecutiveMissed($todayString), 'consecutive_missed')
            ->groupBy('interest_periods.loan_id')
            ->orderBy('oldest_due_date')
            ->orderBy('interest_periods.loan_id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => [
                'loan_id' => (int) $row->loan_id,
                'overdue_interest' => Money::of((string) $row->overdue_interest),
                'overdue_periods' => (int) $row->overdue_periods,
                'oldest_due_date' => substr((string) $row->oldest_due_date, 0, 10),
                'consecutive_missed' => (int) $row->consecutive_missed,
            ])
            ->all();
    }

    /**
     * The row's loan's consecutive-missed count (same rule as App\Domain\Interest\MissedPeriodStreak):
     * missed periods after the loan's last settled past-due period.
     */
    private function consecutiveMissed(string $today): QueryBuilder
    {
        return DB::table('interest_periods as m')
            ->whereColumn('m.loan_id', 'interest_periods.loan_id')
            ->where('m.due_date', '<', $today)
            ->whereNull('m.waived_at')
            ->whereColumn('m.paid_interest', '<', 'm.expected_interest')
            ->whereNotExists(fn (QueryBuilder $settled) => $settled
                ->selectRaw('1')
                ->from('interest_periods as s')
                ->whereColumn('s.loan_id', 'm.loan_id')
                ->whereColumn('s.due_date', '>', 'm.due_date')
                ->where('s.due_date', '<', $today)
                ->where(fn (QueryBuilder $paid) => $paid->whereNotNull('s.waived_at')->orWhereColumn('s.paid_interest', '>=', 's.expected_interest')))
            ->selectRaw('count(*)');
    }
}
