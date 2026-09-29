<?php

namespace App\Domain\Interest;

use App\Domain\Alert\MissedPaymentAlertService;
use App\Domain\Ledger\CustomerLedgerService;
use App\Enums\InterestBase;
use App\Enums\InterestPeriodStatus;
use App\Models\Loan;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creates and maintains a loan's explicit interest periods (docs/07 "InterestScheduleService").
 *
 * Idempotent: every period that has started by the business date exists exactly once (unique
 * loan + start + end, inserted with insertOrIgnore under a row lock on the loan), so running the
 * scheduler any number of times — or concurrently — never duplicates a period. Expected interest
 * is computed once, when a period is created, and never recalculated.
 */
class InterestScheduleService
{
    public function __construct(
        private readonly InterestCalculationService $calculator,
        private readonly InterestSettings $settings,
        private readonly CustomerLedgerService $ledger,
        private readonly MissedPaymentAlertService $alerts,
    ) {}

    /**
     * Brings one loan's periods up to date for $today (business date, application time zone):
     * creates missing periods, refreshes statuses and the loan's next due date, charges the
     * interest of periods that have fallen due to the customer ledger (each period once), and
     * raises/resolves missed-interest alerts. Only open (active/overdue) loans have a running schedule.
     */
    public function sync(Loan $loan, ?CarbonInterface $today = null): InterestSyncResult
    {
        $today = ($today ?? today())->toDateString();

        return DB::transaction(function () use ($loan, $today): InterestSyncResult {
            /** @var Loan $locked */
            $locked = Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                $this->alerts->sync($locked, $today); // a closed/cancelled loan has no open alerts

                return new InterestSyncResult(0, 0);
            }

            $calendar = new InterestPeriodCalendar($this->settings->due);
            $created = $this->createMissingPeriods($locked, $calendar, $today);
            $updated = $this->refreshStatuses($locked, $today);
            $this->updateNextDueDate($locked, $calendar, $today);
            $this->ledger->chargeDueInterest($locked, $today);
            $this->alerts->sync($locked, $today);

            return new InterestSyncResult($created, $updated);
        });
    }

    /**
     * Status of every period of the loan, recalculated from its facts (e.g. after a payment).
     *
     * @return int number of periods whose status changed
     */
    public function refreshStatuses(Loan $loan, string $today): int
    {
        $changed = 0;

        foreach ($loan->interestPeriods()->get() as $period) {
            $status = InterestPeriodStatusResolver::resolve(
                $period->expected_interest,
                $period->paid_interest,
                $period->waived_at !== null,
                $period->due_date->toDateString(),
                $today,
            );

            if ($period->status !== $status) {
                $period->update(['status' => $status]);
                $changed++;
            }
        }

        return $changed;
    }

    private function createMissingPeriods(Loan $loan, InterestPeriodCalendar $calendar, string $today): int
    {
        $existing = $loan->interestPeriods()->pluck('period_start')->map(fn ($date) => substr((string) $date, 0, 10))->flip();
        $rows = [];

        foreach ($calendar->startedBy($loan->start_date->toDateString(), $today) as $period) {
            if ($existing->has($period->start)) {
                continue;
            }

            $expected = $this->calculator->expectedInterest(
                $this->base($loan, $period->start),
                $loan->interest_rate,
                $loan->interest_rate_type,
                $period,
            );

            $rows[] = [
                'loan_id' => $loan->id,
                'period_start' => $period->start,
                'period_end' => $period->end,
                'due_date' => $period->due,
                'expected_interest' => $expected,
                'paid_interest' => '0.00',
                'status' => InterestPeriodStatusResolver::resolve($expected, '0.00', false, $period->due, $today)->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows === [] ? 0 : DB::table('interest_periods')->insertOrIgnore($rows);
    }

    /**
     * The amount the period's interest is charged on. Reducing balance: the principal outstanding at
     * the period's start, i.e. principal minus principal repaid by (non-reversed) payments dated
     * before that day — deterministic however late the period is generated.
     */
    private function base(Loan $loan, string $periodStart): string
    {
        if ($this->settings->base === InterestBase::Principal) {
            return $loan->principal;
        }

        $repaid = (string) DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where('p.loan_id', $loan->id)
            ->whereNull('p.reversed_at')
            ->where('p.payment_date', '<', $periodStart)
            ->sum('a.principal_amount');

        return Money::max(Money::sub($loan->principal, Money::of($repaid)), '0.00');
    }

    /**
     * The next date interest falls due: the earliest unsettled period due today or later, else the
     * due date of the first period not yet generated. Overdue periods are reported separately.
     */
    private function updateNextDueDate(Loan $loan, InterestPeriodCalendar $calendar, string $today): void
    {
        $next = $loan->interestPeriods()
            ->where('due_date', '>=', $today)
            ->whereNotIn('status', [InterestPeriodStatus::Paid->value, InterestPeriodStatus::Waived->value])
            ->min('due_date');

        if ($next === null) {
            // Every generated period is settled (or already past due): the next due date belongs to
            // the first period that has not started yet.
            $next = $calendar->period($loan->start_date->toDateString(), $loan->interestPeriods()->count())->due;
        }

        $next = substr((string) $next, 0, 10);

        if ($loan->next_due_date?->toDateString() !== $next) {
            // A derived schedule field: written directly, without touching userstamps.
            Loan::query()->whereKey($loan->getKey())->toBase()->update(['next_due_date' => $next]);
        }
    }
}
