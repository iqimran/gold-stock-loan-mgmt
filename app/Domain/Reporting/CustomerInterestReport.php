<?php

namespace App\Domain\Reporting;

use App\Domain\Interest\InterestPeriodStatusResolver;
use App\Domain\Settings\LoanSettings;
use App\Enums\InterestBase;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customer interest statement, month by month: every interest period of the customer's loans with the
 * principal it was charged on, the interest charged, what was paid (when, which receipts), waived and
 * still unpaid, and its status for the business date. Oldest first, per loan.
 *
 * Figures are the interest engine's stored facts (App\Domain\Interest\InterestScheduleService); nothing is
 * recalculated. The principal shown is the base the engine charged: the loan's principal minus principal
 * repaid by non-reversed payments dated before the period started (or the original principal on a loan
 * using the flat base). Only non-reversed payments are listed as paid.
 */
class CustomerInterestReport
{
    public function __construct(private readonly LoanSettings $settings) {}

    /**
     * @param  array{loan_id?: ?int, from?: ?string, to?: ?string}  $filters  from / to: due date range
     */
    public function paginate(Customer $customer, array $filters, int $perPage = 25, string $pageName = 'page'): LengthAwarePaginator
    {
        $page = $this->query($customer, $filters)->paginate($perPage, ['*'], $pageName)->withQueryString();
        $rows = $this->present($page->getCollection()->all());

        return $page->setCollection(collect($rows));
    }

    /**
     * Every row of the selection (exports).
     *
     * @param  array{loan_id?: ?int, from?: ?string, to?: ?string}  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(Customer $customer, array $filters): array
    {
        return $this->present($this->query($customer, $filters)->get()->all());
    }

    public function count(Customer $customer, array $filters): int
    {
        return $this->query($customer, $filters)->reorder()->count();
    }

    /**
     * Whole-selection totals. "Unpaid" counts only interest already due (today or earlier); the running
     * month's interest is shown per row but is not yet owed.
     *
     * @param  array{loan_id?: ?int, from?: ?string, to?: ?string}  $filters
     * @return array{months: int, charged: string, paid: string, waived: string, unpaid: string, overdue_months: int}
     */
    public function totals(Customer $customer, array $filters): array
    {
        $totals = ['months' => 0, 'charged' => '0.00', 'paid' => '0.00', 'waived' => '0.00', 'unpaid' => '0.00', 'overdue_months' => 0];
        $today = today()->toDateString();

        foreach ($this->query($customer, $filters)->reorder()->get() as $row) {
            $totals['months']++;
            $totals['paid'] = Money::add($totals['paid'], Money::of((string) $row->paid_interest));
            $remaining = Money::max(Money::sub(Money::of((string) $row->expected_interest), Money::of((string) $row->paid_interest)), '0.00');

            if ($row->waived_at !== null) {
                $totals['waived'] = Money::add($totals['waived'], $remaining);
            }

            if (substr((string) $row->due_date, 0, 10) <= $today) {
                $totals['charged'] = Money::add($totals['charged'], Money::of((string) $row->expected_interest));

                if ($row->waived_at === null) {
                    $totals['unpaid'] = Money::add($totals['unpaid'], $remaining);
                }
            }

            if ($this->status($row) === 'overdue') {
                $totals['overdue_months']++;
            }
        }

        return $totals;
    }

    /**
     * @param  array{loan_id?: ?int, from?: ?string, to?: ?string}  $filters
     */
    private function query(Customer $customer, array $filters): Builder
    {
        // Principal repaid (non-reversed payments) before the period started — the engine's reducing base.
        $repaidBefore = DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->whereColumn('p.loan_id', 'ip.loan_id')
            ->whereNull('p.reversed_at')
            ->whereColumn('p.payment_date', '<', 'ip.period_start')
            ->selectRaw('coalesce(sum(a.principal_amount), 0)');

        return DB::table('interest_periods as ip')
            ->join('loans as l', 'l.id', '=', 'ip.loan_id')
            ->where('l.customer_id', $customer->id)
            ->when($filters['loan_id'] ?? null, fn (Builder $q, int $loanId) => $q->where('ip.loan_id', $loanId))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('ip.due_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('ip.due_date', '<=', $to))
            ->select([
                'ip.id', 'ip.period_start', 'ip.period_end', 'ip.due_date', 'ip.expected_interest', 'ip.paid_interest', 'ip.waived_at',
                'l.loan_no', 'l.principal', 'l.interest_rate', 'l.interest_rate_type', 'l.interest_base',
            ])
            ->selectSub($repaidBefore, 'repaid_before')
            ->orderBy('l.start_date')
            ->orderBy('l.id')
            ->orderBy('ip.period_start');
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows): array
    {
        $payments = $this->payments(array_map(fn (object $row) => (int) $row->id, $rows));

        return array_map(function (object $row) use ($payments) {
            $expected = Money::of((string) $row->expected_interest);
            $paid = Money::of((string) $row->paid_interest);
            $remaining = Money::max(Money::sub($expected, $paid), '0.00');
            $waived = $row->waived_at !== null;
            $principal = $row->interest_base === InterestBase::Principal->value
                ? Money::of((string) $row->principal)
                : Money::max(Money::sub(Money::of((string) $row->principal), Money::of((string) $row->repaid_before)), '0.00');

            return [
                'loan_no' => $row->loan_no,
                'month' => substr((string) $row->period_start, 0, 7),
                'period_start' => substr((string) $row->period_start, 0, 10),
                'period_end' => substr((string) $row->period_end, 0, 10),
                'due_date' => substr((string) $row->due_date, 0, 10),
                'principal' => $principal,
                'rate' => rtrim(rtrim((string) $row->interest_rate, '0'), '.').'% '.($row->interest_rate_type === 'yearly' ? 'yearly' : 'monthly'),
                'expected_interest' => $expected,
                'paid_interest' => $paid,
                'waived_interest' => $waived ? $remaining : '0.00',
                // Owed only once due: the month still running shows nothing unpaid yet (as in the totals).
                'unpaid_interest' => $waived || substr((string) $row->due_date, 0, 10) > today()->toDateString() ? '0.00' : $remaining,
                'status' => $this->status($row),
                'paid_on' => implode(', ', array_unique(array_column($payments[(int) $row->id] ?? [], 'date'))),
                'receipts' => implode(', ', array_column($payments[(int) $row->id] ?? [], 'receipt_no')),
            ];
        }, $rows);
    }

    /**
     * Non-reversed payments that paid interest of these periods, oldest first.
     *
     * @param  list<int>  $periodIds
     * @return array<int, list<array{date: string, receipt_no: string}>>
     */
    private function payments(array $periodIds): array
    {
        if ($periodIds === []) {
            return [];
        }

        return DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->whereIn('a.interest_period_id', $periodIds)
            ->whereNull('p.reversed_at')
            ->where('a.interest_amount', '>', 0)
            ->orderBy('p.payment_date')
            ->orderBy('p.id')
            ->get(['a.interest_period_id', 'p.payment_date', 'p.receipt_no'])
            ->groupBy('interest_period_id')
            ->map(fn ($group) => $group->map(fn (object $p) => ['date' => substr((string) $p->payment_date, 0, 10), 'receipt_no' => $p->receipt_no])->values()->all())
            ->all();
    }

    private function status(object $row): string
    {
        return InterestPeriodStatusResolver::resolve(
            Money::of((string) $row->expected_interest),
            Money::of((string) $row->paid_interest),
            $row->waived_at !== null,
            substr((string) $row->due_date, 0, 10),
            today()->toDateString(),
            $this->settings->missedCutoff(),
        )->value;
    }
}
