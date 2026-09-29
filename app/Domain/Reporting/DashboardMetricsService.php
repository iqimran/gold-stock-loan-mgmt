<?php

namespace App\Domain\Reporting;

use App\Domain\Alert\AlertQuery;
use App\Domain\Alert\DueInterestQuery;
use App\Domain\Loan\LoanSearch;
use App\Domain\Payment\PaymentSearch;
use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Dashboard metrics (docs/01 "Dashboard", docs/07 "DashboardMetricsService"). Every figure reuses the
 * query that defines its rule elsewhere — LoanSearch (overdue), DueInterestQuery (due interest),
 * AlertQuery (alerts), PaymentSearch (payments) — so the dashboard always agrees with the lists, filters
 * and (later) the reports. Amounts are decimal strings; nothing is calculated in the browser.
 *
 * Date semantics (docs/01: "a defined date range and timezone"):
 *  - "today" is the business date in the application time zone (APP_TIMEZONE), as everywhere else;
 *  - balances (active loans, outstanding, due interest, overdue accounts, alerts) are as of today;
 *  - collections cover one calendar month of payment dates (payment_date is a business date, so no
 *    time-zone shift applies), the current month by default; reversed payments are excluded.
 */
class DashboardMetricsService
{
    public function __construct(
        private readonly LoanSearch $loans,
        private readonly DueInterestQuery $due,
        private readonly AlertQuery $alerts,
        private readonly PaymentSearch $payments,
        private readonly CollectionReport $collections,
    ) {}

    /**
     * Balances of open loans as of today.
     *
     * @return array{active_loans: int, overdue_loans: int, draft_loans: int, outstanding_principal: string, due_interest: string, due_periods: int, overdue_interest: string, overdue_accounts: int, overdue_customers: int}
     */
    public function loanSummary(): array
    {
        $open = Loan::query()->whereIn('status', LoanStatus::open());
        $overdueAccounts = $this->loans->query(['overdue' => true])->reorder();
        $dueToDate = $this->due->totals(['status' => 'unpaid', 'due_to' => today()->toDateString()]);

        return [
            'active_loans' => (clone $open)->count(),
            'overdue_loans' => (clone $open)->where('status', LoanStatus::Overdue)->count(),
            'draft_loans' => Loan::query()->where('status', LoanStatus::Draft)->count(),
            'outstanding_principal' => Money::of((string) (clone $open)->sum('outstanding_principal')),
            // Interest due today or earlier and not yet paid (partly paid periods count their remainder).
            'due_interest' => $dueToDate['amount'],
            'due_periods' => $dueToDate['periods'],
            'overdue_interest' => $this->due->totals(['status' => 'overdue'])['amount'],
            // Loans with at least one missed period (the missed-period rule), and the customers concerned.
            'overdue_accounts' => (clone $overdueAccounts)->count(),
            'overdue_customers' => (clone $overdueAccounts)->distinct()->count('loans.customer_id'),
        ];
    }

    /**
     * Money collected in a calendar month, split by what it paid. Revenue = interest + fees.
     *
     * @return array{month: string, from: string, to: string, total: string, payments: int, interest: string, principal: string, fees: string, revenue: string}
     */
    public function collections(CarbonImmutable $month): array
    {
        $from = $month->startOfMonth()->toDateString();
        $to = $month->endOfMonth()->toDateString();

        // The Collection Report's totals, for one month: the dashboard and the report always agree.
        $totals = $this->collections->totals(['paid_from' => $from, 'paid_to' => $to]);

        return [
            'month' => $month->format('Y-m'),
            'from' => $from,
            'to' => $to,
            'total' => $totals['gross'],
            'payments' => $totals['payments'],
            'interest' => $totals['interest'],
            'principal' => $totals['principal'],
            'fees' => $totals['fees'],
            'revenue' => $totals['revenue'],
        ];
    }

    /**
     * Loans with the oldest overdue interest first, with what is overdue on each.
     *
     * @return list<array<string, mixed>>
     */
    public function overdueAccounts(int $limit = 5): array
    {
        $rows = $this->due->overdueByLoan($limit);
        $loans = Loan::query()->with('customer:id,customer_no,name,mobile')->whereKey(array_column($rows, 'loan_id'))->get()->keyBy('id');

        return array_map(fn (array $row) => [
            'loan_no' => $loans[$row['loan_id']]->loan_no,
            'status' => $loans[$row['loan_id']]->status->value,
            'customer' => [
                'customer_no' => $loans[$row['loan_id']]->customer->customer_no,
                'name' => $loans[$row['loan_id']]->customer->name,
                'mobile' => $loans[$row['loan_id']]->customer->mobile,
            ],
            'overdue_interest' => $row['overdue_interest'],
            'overdue_periods' => $row['overdue_periods'],
            'oldest_due_date' => $row['oldest_due_date'],
            'consecutive_missed' => $row['consecutive_missed'],
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentPayments(int $limit = 5): array
    {
        return $this->payments->query([])->reorder()->orderByDesc('payments.id')->limit($limit)->get()
            ->map(fn (Payment $payment) => [
                'receipt_no' => $payment->receipt_no,
                'payment_date' => $payment->payment_date->toDateString(),
                'type' => $payment->type->value,
                'amount' => $payment->amount,
                'status' => $payment->status->value,
                'customer' => $payment->customer?->name,
                'loan_no' => $payment->loan?->loan_no,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentLoans(int $limit = 5): array
    {
        return Loan::query()->with('customer:id,customer_no,name')->latest('id')->limit($limit)->get()
            ->map(fn (Loan $loan) => [
                'loan_no' => $loan->loan_no,
                'status' => $loan->status->value,
                'principal' => $loan->principal,
                'start_date' => $loan->start_date->toDateString(),
                'customer' => $loan->customer->name,
                'created_at' => $loan->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Open missed-interest alerts and the configured threshold.
     *
     * @return array{open: int, customers: int, threshold: int}
     */
    public function alerts(): array
    {
        return $this->alerts->summary() + ['threshold' => (int) config('loans.alerts.missed_period_threshold')];
    }
}
