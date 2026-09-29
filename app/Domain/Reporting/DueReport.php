<?php

namespace App\Domain\Reporting;

use App\Domain\Alert\DueInterestQuery;
use App\Models\InterestPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Due Report (docs/10): upcoming, due and overdue interest of open loans. Exactly the rows and rules of
 * App\Domain\Alert\DueInterestQuery (status, due date range, customer, loan, missed threshold — the
 * missed-period rule decided by the business), with sorting and whole-set totals.
 */
class DueReport
{
    public const SORTS = ['due_date' => 'interest_periods.due_date', 'balance' => 'balance_due', 'missed' => 'consecutive_missed'];

    public function __construct(private readonly DueInterestQuery $due) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<InterestPeriod>
     */
    public function query(array $filters): Builder
    {
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $this->due->query($filters)
            ->selectRaw('(interest_periods.expected_interest - interest_periods.paid_interest) as balance_due')
            ->reorder()
            ->orderBy(self::SORTS[$filters['sort'] ?? 'due_date'] ?? self::SORTS['due_date'], $direction)
            ->orderBy('interest_periods.id', $direction);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{periods: int, loans: int, expected_interest: string, paid_interest: string, balance_due: string}
     */
    public function totals(array $filters): array
    {
        $totals = $this->due->totals($filters);

        return [
            'periods' => $totals['periods'],
            'loans' => $totals['loans'],
            'expected_interest' => $totals['expected'],
            'paid_interest' => $totals['paid'],
            'balance_due' => $totals['amount'],
        ];
    }
}
