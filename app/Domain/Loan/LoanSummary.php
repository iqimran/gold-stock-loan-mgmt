<?php

namespace App\Domain\Loan;

use App\Domain\Interest\InterestPeriodStatusResolver;
use App\Domain\Interest\MissedPeriodStreak;
use App\Domain\Settings\LoanSettings;
use App\Enums\InterestPeriodStatus;
use App\Models\InterestPeriod;
use App\Models\Loan;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Server-authoritative figures for a loan's detail screen. Everything is derived from the facts
 * (interest periods, non-reversed payments and their allocations) with decimal-safe arithmetic;
 * the screen only displays these values.
 *
 * Period statuses are resolved live for the business date with the interest engine's resolver, so
 * the figures are correct even before the daily interest run has refreshed the stored statuses.
 * "Consecutive missed" follows the business-decided rule (trailing overdue periods since the last
 * settled past-due period).
 */
class LoanSummary
{
    public function __construct(private readonly LoanSettings $settings) {}

    /**
     * @return array{
     *     principal: string, outstanding_principal: string, principal_repaid: string,
     *     interest_charged: string, interest_paid: string, interest_waived: string, interest_due: string,
     *     fees_paid: string, payments_total: string, payments_count: int,
     *     overdue_periods: int, consecutive_missed: int, next_due_date: ?string,
     *     last_payment: ?array{date: string, amount: string},
     *     periods: list<array{period_start: string, period_end: string, due_date: string, expected_interest: string, paid_interest: string, status: string}>
     * }
     */
    public function for(Loan $loan, ?CarbonInterface $today = null): array
    {
        $cutoff = $this->settings->missedCutoff($today);
        $today = ($today ?? today())->toDateString();

        $periods = $loan->interestPeriods()->get()->map(fn (InterestPeriod $period) => [
            'period_start' => $period->period_start->toDateString(),
            'period_end' => $period->period_end->toDateString(),
            'due_date' => $period->due_date->toDateString(),
            'expected_interest' => $period->expected_interest,
            'paid_interest' => $period->paid_interest,
            'status' => InterestPeriodStatusResolver::resolve(
                $period->expected_interest, $period->paid_interest, $period->waived_at !== null, $period->due_date->toDateString(), $today, $cutoff,
            )->value,
        ])->all();

        $charged = $paid = $waived = $due = '0.00';

        foreach ($periods as $period) {
            $paid = Money::add($paid, $period['paid_interest']);

            if ($period['status'] === InterestPeriodStatus::Waived->value) {
                $waived = Money::add($waived, Money::max(Money::sub($period['expected_interest'], $period['paid_interest']), '0.00'));

                continue;
            }

            if ($period['due_date'] <= $today) {
                $charged = Money::add($charged, $period['expected_interest']);
                $due = Money::add($due, Money::max(Money::sub($period['expected_interest'], $period['paid_interest']), '0.00'));
            }
        }

        $payments = DB::table('payments')->where('loan_id', $loan->id)->whereNull('reversed_at');
        $allocations = DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where('p.loan_id', $loan->id)
            ->whereNull('p.reversed_at')
            ->selectRaw('coalesce(sum(a.principal_amount), 0) as principal, coalesce(sum(a.fee_amount), 0) as fees')
            ->first();
        $last = (clone $payments)->orderByDesc('payment_date')->orderByDesc('id')->first(['payment_date', 'amount']);

        return [
            'principal' => $loan->principal,
            'outstanding_principal' => $loan->outstanding_principal,
            'principal_repaid' => Money::of((string) $allocations->principal),
            'interest_charged' => $charged,
            'interest_paid' => $paid,
            'interest_waived' => $waived,
            'interest_due' => $due,
            'fees_paid' => Money::of((string) $allocations->fees),
            'payments_total' => Money::of((string) (clone $payments)->sum('amount')),
            'payments_count' => (clone $payments)->count(),
            'overdue_periods' => count(array_filter($periods, fn ($period) => $period['status'] === InterestPeriodStatus::Overdue->value)),
            'consecutive_missed' => count(MissedPeriodStreak::of($periods, $cutoff)),
            'next_due_date' => $loan->next_due_date?->toDateString(),
            'last_payment' => $last ? ['date' => substr((string) $last->payment_date, 0, 10), 'amount' => Money::of((string) $last->amount)] : null,
            'periods' => $periods,
        ];
    }
}
