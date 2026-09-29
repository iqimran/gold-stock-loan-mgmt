<?php

namespace Tests\Feature\Interest;

use App\Domain\Interest\InterestScheduleService;
use App\Domain\Loan\LoanService;
use App\Enums\InterestPeriodStatus;
use App\Enums\InterestRateType;
use App\Enums\LoanEventType;
use App\Enums\LoanStatus;
use App\Models\InterestPeriod;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Interest engine against the database: generation, idempotency, statuses, overdue detection.
 */
class InterestScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(): InterestScheduleService
    {
        return app(InterestScheduleService::class);
    }

    private function activeLoan(array $attributes = []): Loan
    {
        return Loan::factory()->active()->create([
            'principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000',
            'interest_rate_type' => InterestRateType::Monthly, 'start_date' => '2026-01-15', ...$attributes,
        ]);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}> [start, end, due, expected, status]
     */
    private function periodsOf(Loan $loan): array
    {
        return $loan->interestPeriods()->get()->map(fn (InterestPeriod $p) => [
            $p->period_start->toDateString(), $p->period_end->toDateString(), $p->due_date->toDateString(), $p->expected_interest, $p->status->value,
        ])->all();
    }

    private function principalPayment(Loan $loan, string $date, string $principal, bool $reversed = false): void
    {
        $user = User::factory()->create();
        $paymentId = DB::table('payments')->insertGetId([
            'receipt_no' => 'R-'.uniqid(), 'customer_id' => $loan->customer_id, 'loan_id' => $loan->id, 'type' => 'principal', 'amount' => $principal,
            'method' => 'cash', 'payment_date' => $date, 'status' => $reversed ? 'reversed' : 'posted',
            'reversed_at' => $reversed ? now() : null, 'reversed_by' => $reversed ? $user->id : null, 'reversal_reason' => $reversed ? 'Wrong amount' : null,
        ]);
        DB::table('payment_allocations')->insert(['payment_id' => $paymentId, 'principal_amount' => $principal, 'total_amount' => $principal]);
    }

    private function pay(Loan $loan, string $periodStart, string $amount): void
    {
        $loan->interestPeriods()->whereDate('period_start', $periodStart)->update(['paid_interest' => $amount]);
    }

    // ── generation ──────────────────────────────────────────────────────────────────────────

    public function test_every_started_period_is_generated_with_due_date_and_expected_interest(): void
    {
        $loan = $this->activeLoan();

        $result = $this->schedule()->sync($loan, Carbon::parse('2026-03-20'));

        $this->assertSame(3, $result->periodsCreated);
        $this->assertSame([
            ['2026-01-15', '2026-02-14', '2026-02-14', '200.00', 'overdue'],
            ['2026-02-15', '2026-03-14', '2026-03-14', '200.00', 'overdue'],
            ['2026-03-15', '2026-04-14', '2026-04-14', '200.00', 'upcoming'],
        ], $this->periodsOf($loan));
        $this->assertSame('2026-04-14', $loan->fresh()->next_due_date->toDateString());
    }

    public function test_no_periods_before_the_loan_starts_or_for_loans_that_are_not_open(): void
    {
        $future = $this->activeLoan(['start_date' => '2026-06-01']);
        $this->schedule()->sync($future, Carbon::parse('2026-05-31'));
        $this->assertSame(0, $future->interestPeriods()->count());

        foreach ([LoanStatus::Draft, LoanStatus::Closed, LoanStatus::Cancelled] as $status) {
            $loan = $this->activeLoan(['status' => $status]);
            $this->assertSame(0, $this->schedule()->sync($loan, Carbon::parse('2026-12-31'))->periodsCreated);
            $this->assertSame(0, $loan->interestPeriods()->count(), $status->value);
        }
    }

    // ── idempotency ─────────────────────────────────────────────────────────────────────────

    public function test_repeated_generation_never_duplicates_periods(): void
    {
        $loan = $this->activeLoan();
        $today = Carbon::parse('2026-06-01');

        $this->assertSame(5, $this->schedule()->sync($loan, $today)->periodsCreated);

        foreach (range(1, 5) as $run) {
            $result = $this->schedule()->sync($loan, $today);
            $this->assertSame(0, $result->periodsCreated, "Run {$run} created periods");
            $this->assertSame(0, $result->statusesChanged, "Run {$run} changed statuses");
        }

        $this->assertSame(5, $loan->interestPeriods()->count());
        $this->assertSame(5, InterestPeriod::query()->distinct()->count('period_start'));
    }

    public function test_generation_fills_gaps_and_keeps_existing_periods(): void
    {
        $loan = $this->activeLoan();
        // A period that already exists (e.g. created by a concurrent run) with its own figures.
        DB::table('interest_periods')->insert([
            'loan_id' => $loan->id, 'period_start' => '2026-02-15', 'period_end' => '2026-03-14', 'due_date' => '2026-03-14',
            'expected_interest' => '199.00', 'paid_interest' => '0.00', 'status' => 'upcoming',
        ]);

        $this->assertSame(2, $this->schedule()->sync($loan, Carbon::parse('2026-03-20'))->periodsCreated);
        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], array_column($this->periodsOf($loan), 0));
        $this->assertSame('199.00', $loan->interestPeriods()->whereDate('period_start', '2026-02-15')->value('expected_interest'));
    }

    public function test_the_daily_command_is_idempotent(): void
    {
        $loans = [$this->activeLoan(), $this->activeLoan(['start_date' => '2026-02-01'])];

        foreach (range(1, 3) as $run) {
            $this->artisan('loans:process-interest', ['--date' => '2026-04-10'])->assertSuccessful();
        }

        $this->assertSame(3, $loans[0]->interestPeriods()->count());
        $this->assertSame(3, $loans[1]->interestPeriods()->count());
        $this->assertSame(1, $loans[0]->events()->where('event_type', LoanEventType::MarkedOverdue)->count());
    }

    // ── expected interest ───────────────────────────────────────────────────────────────────

    public function test_reducing_balance_uses_principal_outstanding_at_each_period_start(): void
    {
        $loan = $this->activeLoan();
        $this->principalPayment($loan, '2026-02-01', '2500.00');         // before period 2 → 7,500
        $this->principalPayment($loan, '2026-03-15', '2500.00');         // ON period 3's start: not yet before it
        $this->principalPayment($loan, '2026-03-01', '1000.00', true);   // reversed: ignored

        $this->schedule()->sync($loan, Carbon::parse('2026-04-20'));

        $this->assertSame(['200.00', '150.00', '150.00', '100.00'], array_column($this->periodsOf($loan), 3));
    }

    public function test_catch_up_generation_gives_the_same_figures_as_timely_generation(): void
    {
        $timely = $this->activeLoan();
        $late = $this->activeLoan();

        foreach ([$timely, $late] as $loan) {
            $this->principalPayment($loan, '2026-02-20', '4000.00');
        }

        foreach (['2026-01-15', '2026-02-15', '2026-03-15', '2026-04-15'] as $day) {
            $this->schedule()->sync($timely, Carbon::parse($day));
        }
        $this->schedule()->sync($late, Carbon::parse('2026-04-15'));

        $this->assertSame(array_column($this->periodsOf($timely), 3), array_column($this->periodsOf($late), 3));
        $this->assertSame(['200.00', '200.00', '120.00', '120.00'], array_column($this->periodsOf($late), 3));
    }

    public function test_generated_interest_is_never_recalculated(): void
    {
        $loan = $this->activeLoan();
        $this->schedule()->sync($loan, Carbon::parse('2026-02-01'));

        $this->principalPayment($loan, '2026-01-10', '5000.00'); // back-dated after generation
        $loan->update(['interest_rate' => '9.0000']);
        $this->schedule()->sync($loan, Carbon::parse('2026-02-01'));

        $this->assertSame('200.00', $loan->interestPeriods()->value('expected_interest'));
    }

    public function test_flat_base_charges_the_original_principal(): void
    {
        config(['loans.interest.base' => 'principal']);
        $loan = $this->activeLoan();
        $this->principalPayment($loan, '2026-02-01', '5000.00');

        $this->schedule()->sync($loan, Carbon::parse('2026-03-20'));

        $this->assertSame(['200.00', '200.00', '200.00'], array_column($this->periodsOf($loan), 3));
    }

    public function test_yearly_rate_conversion_is_configurable(): void
    {
        $twelfths = $this->activeLoan(['interest_rate' => '36.5000', 'interest_rate_type' => InterestRateType::Yearly, 'start_date' => '2026-02-01']);
        $this->schedule()->sync($twelfths, Carbon::parse('2026-02-01'));

        config(['loans.interest.yearly_conversion' => 'actual_days']);
        $actualDays = $this->activeLoan(['interest_rate' => '36.5000', 'interest_rate_type' => InterestRateType::Yearly, 'start_date' => '2026-02-01']);
        $this->schedule()->sync($actualDays, Carbon::parse('2026-02-01'));

        $this->assertSame('304.17', $twelfths->interestPeriods()->value('expected_interest'));  // 10,000 × 36.5% ÷ 12
        $this->assertSame('280.00', $actualDays->interestPeriods()->value('expected_interest')); // 10,000 × 36.5% × 28 ÷ 365
    }

    // ── due dates and statuses ──────────────────────────────────────────────────────────────

    public function test_status_moves_from_upcoming_to_due_to_overdue_with_the_date(): void
    {
        $loan = $this->activeLoan();

        $status = function (string $today) use ($loan) {
            $this->schedule()->sync($loan, Carbon::parse($today));

            return $loan->interestPeriods()->first()->status;
        };

        $this->assertSame(InterestPeriodStatus::Upcoming, $status('2026-02-13'));
        $this->assertSame(InterestPeriodStatus::Due, $status('2026-02-14'));
        $this->assertSame(InterestPeriodStatus::Overdue, $status('2026-02-15'));
    }

    public function test_payments_and_waivers_are_reflected_in_statuses(): void
    {
        $loan = $this->activeLoan();
        $this->schedule()->sync($loan, Carbon::parse('2026-03-20'));

        $this->pay($loan, '2026-01-15', '200.00');
        $this->pay($loan, '2026-02-15', '50.00');
        $this->pay($loan, '2026-03-15', '50.00');
        // Period 1 overdue → paid, period 3 upcoming → partially paid; period 2 stays overdue (partial after due).
        $this->assertSame(2, $this->schedule()->sync($loan, Carbon::parse('2026-03-20'))->statusesChanged);
        $this->assertSame(['paid', 'overdue', 'partially_paid'], array_column($this->periodsOf($loan), 4));

        $loan->interestPeriods()->whereDate('period_start', '2026-02-15')->update(['waived_at' => now(), 'waived_by' => User::factory()->create()->id, 'waiver_reason' => 'Goodwill']);
        $this->schedule()->sync($loan, Carbon::parse('2026-03-20'));
        $this->assertSame(['paid', 'waived', 'partially_paid'], array_column($this->periodsOf($loan), 4));
    }

    public function test_next_due_date_skips_settled_periods(): void
    {
        $loan = $this->activeLoan();
        $this->schedule()->sync($loan, Carbon::parse('2026-01-20'));
        $this->assertSame('2026-02-14', $loan->fresh()->next_due_date->toDateString());

        $this->pay($loan, '2026-01-15', '200.00'); // paid in advance
        $this->schedule()->sync($loan, Carbon::parse('2026-01-20'));
        $this->assertSame('2026-03-14', $loan->fresh()->next_due_date->toDateString());
    }

    public function test_interest_due_at_period_start_is_configurable(): void
    {
        config(['loans.interest.due' => 'period_start']);
        $loan = $this->activeLoan();

        $this->schedule()->sync($loan, Carbon::parse('2026-01-15'));

        $this->assertSame([['2026-01-15', '2026-02-14', '2026-01-15', '200.00', 'due']], $this->periodsOf($loan));
        $this->assertSame('2026-01-15', $loan->fresh()->next_due_date->toDateString());
    }

    // ── overdue detection ───────────────────────────────────────────────────────────────────

    public function test_loan_becomes_overdue_with_any_overdue_period_and_active_again_when_none(): void
    {
        $loan = $this->activeLoan();

        $this->artisan('loans:process-interest', ['--date' => '2026-02-14'])->assertSuccessful();
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status); // due today, not yet overdue

        $this->artisan('loans:process-interest', ['--date' => '2026-02-15'])->assertSuccessful();
        $this->assertSame(LoanStatus::Overdue, $loan->fresh()->status);

        $this->pay($loan, '2026-01-15', '200.00');
        $this->artisan('loans:process-interest', ['--date' => '2026-02-16'])->assertSuccessful();
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);

        $this->assertSame(
            [LoanEventType::MarkedOverdue, LoanEventType::OverdueCleared],
            $loan->events()->orderBy('id')->pluck('event_type')->all(),
        );
    }

    public function test_activating_a_backdated_loan_generates_periods_and_detects_overdue(): void
    {
        Carbon::setTestNow('2026-03-20 09:00:00');
        $loan = Loan::factory()->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-01-15']);

        app(LoanService::class)->activate($loan, User::factory()->create());

        $this->assertSame(3, $loan->interestPeriods()->count());
        $this->assertSame(LoanStatus::Overdue, $loan->fresh()->status);
        $this->assertSame('2026-04-14', $loan->fresh()->next_due_date->toDateString());
    }

    // ── date policy and command ─────────────────────────────────────────────────────────────

    public function test_business_date_follows_the_application_time_zone(): void
    {
        $original = date_default_timezone_get();
        config(['app.timezone' => 'Asia/Dhaka']);
        date_default_timezone_set('Asia/Dhaka');

        try {
            // 14 Feb 20:00 UTC is already 15 Feb (02:00) in Dhaka: the first period is overdue there.
            Carbon::setTestNow(Carbon::parse('2026-02-14 20:00:00', 'UTC'));
            $loan = $this->activeLoan();

            $this->artisan('loans:process-interest')->assertSuccessful();

            $this->assertSame(InterestPeriodStatus::Overdue, $loan->interestPeriods()->first()->status);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_command_rejects_invalid_dates_and_reports_totals(): void
    {
        $this->activeLoan();

        $this->artisan('loans:process-interest', ['--date' => '2026-02-30'])->assertFailed();
        $this->artisan('loans:process-interest', ['--date' => '2026-03-20'])
            ->expectsOutputToContain('Interest processed for 2026-03-20: 3 period(s) created')
            ->assertSuccessful();
    }

    public function test_the_interest_run_is_scheduled_daily_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'loans:process-interest'));

        $this->assertNotNull($event);
        $this->assertSame('5 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
