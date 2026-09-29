<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alert\AlertSettings;
use App\Domain\Loan\LoanService;
use App\Domain\Payment\PaymentReversalService;
use App\Domain\Payment\PaymentService;
use App\Enums\AlertStatus;
use App\Enums\CollateralStatus;
use App\Enums\LoanEventType;
use App\Enums\LoanStatus;
use App\Enums\Permission;
use App\Models\Alert;
use App\Models\CollateralItem;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Missed-interest alerts. Scenario: 10,000.00 at 2% a month started 2026-01-01; periods run Jan, Feb, Mar…
 * and are due on the month's last day (200.00 each). The daily interest run is simulated month by month.
 */
class MissedPaymentAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create();
        $this->cashier->givePermissionTo([Permission::LoansView->value, Permission::PaymentsCreate->value, Permission::PaymentsReverse->value]);
    }

    private function threshold(int $threshold): void
    {
        config(['loans.alerts.missed_period_threshold' => $threshold]);
    }

    private function loan(): Loan
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        $loan = Loan::factory()->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-01-01']);
        CollateralItem::factory()->for($loan)->create();

        return app(LoanService::class)->activate($loan, $this->cashier);
    }

    /** The daily interest run on the given business date. */
    private function runOn(string $date): void
    {
        Carbon::setTestNow("{$date} 00:05:00");
        $this->artisan('loans:process-interest')->assertSuccessful();
    }

    private function pay(Loan $loan, string $type, string $amount): Payment
    {
        return app(PaymentService::class)->post($loan->fresh(), ['type' => $type, 'amount' => $amount, 'method' => 'cash', 'payment_date' => today()->toDateString()], $this->cashier);
    }

    private function alerts(Loan $loan)
    {
        return Alert::query()->where('loan_id', $loan->id)->orderBy('id')->get();
    }

    private function open(Loan $loan): ?Alert
    {
        return Alert::query()->where('loan_id', $loan->id)->where('status', AlertStatus::Open)->with('interestPeriod')->first();
    }

    // ── thresholds ──────────────────────────────────────────────────────────────────────────

    public function test_threshold_1_alerts_on_the_first_missed_period(): void
    {
        $this->threshold(1);
        $loan = $this->loan();

        $this->runOn('2026-01-31'); // Jan due today: not missed yet
        $this->assertNull($this->open($loan));

        $this->runOn('2026-02-01'); // Jan missed → 1
        $alert = $this->open($loan);
        $this->assertNotNull($alert);
        $this->assertSame(1, $alert->threshold);
        $this->assertSame('2026-01-01', $alert->interestPeriod->period_start->toDateString());
        $this->assertSame($loan->customer_id, $alert->customer_id);
        $this->assertStringContainsString('1 consecutive missed interest period(s), reaching the alert threshold of 1', $alert->message);
    }

    public function test_threshold_2_alerts_on_the_second_consecutive_missed_period_only_once(): void
    {
        $this->threshold(2);
        $loan = $this->loan();

        $this->runOn('2026-02-01'); // Jan missed → 1
        $this->assertCount(0, $this->alerts($loan));

        $this->runOn('2026-03-01'); // Feb missed → 2 → alert
        $this->assertCount(1, $this->alerts($loan));
        $this->assertSame('2026-02-01', $this->open($loan)->interestPeriod->period_start->toDateString());

        $this->runOn('2026-04-01'); // Mar missed → 3: same streak, no new alert
        $this->assertCount(1, $this->alerts($loan));
        $this->assertSame(1, $loan->events()->where('event_type', LoanEventType::AlertRaised)->count());
    }

    public function test_threshold_3_alerts_on_the_third_consecutive_missed_period(): void
    {
        $this->threshold(3);
        $loan = $this->loan();

        $this->runOn('2026-03-01');
        $this->assertCount(0, $this->alerts($loan));

        $this->runOn('2026-04-01');
        $this->assertSame('2026-03-01', $this->open($loan)->interestPeriod->period_start->toDateString());
        $this->assertSame(3, $this->open($loan)->threshold);
    }

    public function test_a_backdated_loan_that_is_already_past_the_threshold_gets_one_alert(): void
    {
        $this->threshold(2);
        Carbon::setTestNow('2026-05-10 09:00:00');
        $loan = Loan::factory()->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-01-01']);

        app(LoanService::class)->activate($loan, $this->cashier); // Jan–Apr missed at once

        $this->assertCount(1, $this->alerts($loan));
        $this->assertSame('2026-02-01', $this->open($loan)->interestPeriod->period_start->toDateString()); // the 2nd missed period
    }

    public function test_the_threshold_is_configurable_and_never_zero(): void
    {
        $this->threshold(5);
        $this->assertSame(5, app(AlertSettings::class)->missedPeriodThreshold);

        $this->expectException(InvalidArgumentException::class);
        new AlertSettings(0);
    }

    // ── duplicate prevention ────────────────────────────────────────────────────────────────

    public function test_repeated_runs_never_duplicate_an_alert(): void
    {
        $this->threshold(2);
        $loan = $this->loan();

        foreach (range(1, 4) as $ignored) {
            $this->runOn('2026-03-01');
        }

        $this->assertCount(1, $this->alerts($loan));
        $this->assertSame(1, $loan->events()->where('event_type', LoanEventType::AlertRaised)->count());
    }

    public function test_paying_only_the_oldest_period_keeps_the_same_alert(): void
    {
        $this->threshold(2);
        $loan = $this->loan();
        $this->runOn('2026-04-01'); // Jan, Feb, Mar missed

        $this->pay($loan, 'interest', '200'); // Jan paid → Feb, Mar still missed (2 ≥ 2)

        $this->assertCount(1, $this->alerts($loan));
        $this->assertSame(AlertStatus::Open, $this->alerts($loan)->first()->status);
    }

    // ── resetting ───────────────────────────────────────────────────────────────────────────

    public function test_a_payment_resets_the_count_and_resolves_the_alert(): void
    {
        $this->threshold(2);
        $loan = $this->loan();
        $this->runOn('2026-03-01');
        $alert = $this->open($loan);

        $this->pay($loan, 'interest', '400'); // Jan + Feb: resolved in the same transaction

        $alert->refresh();
        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame('no missed periods', $loan->events()->where('event_type', LoanEventType::AlertResolved)->sole()->payload['reason']);

        // A new streak later raises a new alert; the resolved one stays as history.
        $this->runOn('2026-05-01'); // Mar, Apr missed
        $alerts = $this->alerts($loan);
        $this->assertCount(2, $alerts);
        $this->assertSame([AlertStatus::Resolved, AlertStatus::Open], $alerts->pluck('status')->all());
    }

    public function test_a_partial_payment_does_not_reset_the_count(): void
    {
        $this->threshold(2);
        $loan = $this->loan();
        $this->runOn('2026-03-01');

        $this->pay($loan, 'interest', '150'); // Jan partly paid: still missed

        $this->assertSame(AlertStatus::Open, $this->open($loan)?->status);
        $this->assertCount(1, $this->alerts($loan));
    }

    public function test_a_waiver_resets_the_count(): void
    {
        $this->threshold(2);
        $loan = $this->loan();
        $this->runOn('2026-03-01');

        $loan->interestPeriods()->whereDate('period_start', '2026-02-01')->update(['waived_at' => now(), 'waived_by' => $this->cashier->id, 'waiver_reason' => 'Goodwill']);
        $this->runOn('2026-03-02');

        $this->assertNull($this->open($loan));
    }

    public function test_a_reversal_reopens_the_same_alert(): void
    {
        $this->threshold(2);
        $loan = $this->loan();
        $this->runOn('2026-03-01');
        $payment = $this->pay($loan, 'interest', '400');
        $this->assertNull($this->open($loan));

        app(PaymentReversalService::class)->reverse($payment, $this->cashier, 'Cheque bounced');

        $alerts = $this->alerts($loan);
        $this->assertCount(1, $alerts); // reopened, not duplicated
        $this->assertSame(AlertStatus::Open, $alerts->first()->status);
        $this->assertNull($alerts->first()->resolved_at);
        $this->assertTrue($loan->events()->where('event_type', LoanEventType::AlertRaised)->orderByDesc('id')->first()->payload['reopened']);
    }

    public function test_raising_the_threshold_resolves_alerts_below_it(): void
    {
        $this->threshold(2);
        $loan = $this->loan();
        $this->runOn('2026-03-01');

        $this->threshold(3);
        $this->runOn('2026-03-02');
        $this->assertNull($this->open($loan));

        $this->runOn('2026-04-01');
        $this->assertSame(3, $this->open($loan)->threshold);
        $this->assertCount(2, $this->alerts($loan));
    }

    public function test_closing_or_cancelling_a_loan_resolves_its_alerts(): void
    {
        $this->threshold(1);
        $loan = $this->loan();
        $this->runOn('2026-02-01');
        $this->assertNotNull($this->open($loan));

        app(LoanService::class)->cancel($loan->fresh(), $this->cashier, 'Pledged by mistake');

        $this->assertNull($this->open($loan));
    }

    // ── no automatic consequences ───────────────────────────────────────────────────────────

    public function test_alerts_never_default_close_seize_or_penalise(): void
    {
        $this->threshold(1);
        $loan = $this->loan();

        $this->runOn('2026-07-01'); // six missed periods

        $loan->refresh();
        $this->assertNotNull($this->open($loan));
        $this->assertSame(LoanStatus::Overdue, $loan->status); // the documented overdue rule, nothing more
        $this->assertSame('10000.00', $loan->outstanding_principal);
        $this->assertSame(CollateralStatus::Held, $loan->collateralItems()->sole()->status);
        // No penalty: seven periods (Jan–Jul), each charged exactly its normal 200.00.
        $this->assertSame(array_fill(0, 7, '200.00'), $loan->interestPeriods()->get()->pluck('expected_interest')->all());
        $this->assertSame(0, Payment::count());
    }

    // ── dashboard and due/overdue queries ───────────────────────────────────────────────────

    public function test_alert_list_and_dashboard_summary(): void
    {
        $this->threshold(2);
        $loan = $this->loan();
        $this->runOn('2026-03-01');
        Sanctum::actingAs($this->cashier);

        $this->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'missed_interest')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.threshold', 2)
            ->assertJsonPath('data.0.loan.loan_no', $loan->loan_no)
            ->assertJsonPath('data.0.customer.customer_no', $loan->customer->customer_no)
            ->assertJsonPath('data.0.triggering_period.due_date', '2026-02-28');

        $this->getJson('/api/v1/dashboard/missed-payment-alerts')
            ->assertOk()
            ->assertJson(['data' => ['open' => 1, 'customers' => 1, 'threshold' => 2]]);

        $this->pay($loan, 'interest', '400');
        $this->getJson('/api/v1/alerts')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/alerts?status=all')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/alerts?status=bogus')->assertUnprocessable();
    }

    public function test_due_and_overdue_views(): void
    {
        $this->threshold(2);
        $loan = $this->loan();
        $this->runOn('2026-04-15'); // Jan, Feb, Mar overdue; Apr upcoming
        $this->pay($loan, 'interest', '250'); // Jan paid, Feb partly paid (50)
        Sanctum::actingAs($this->cashier);

        $starts = fn (string $query) => collect($this->getJson("/api/v1/interest-periods?{$query}")->assertOk()->json('data'))->pluck('period_start')->all();

        $this->assertSame(['2026-02-01', '2026-03-01', '2026-04-01'], $starts(''));
        $this->assertSame(['2026-02-01', '2026-03-01'], $starts('status=overdue'));
        $this->assertSame(['2026-04-01'], $starts('status=upcoming'));
        $this->assertSame(['2026-02-01', '2026-03-01', '2026-04-01'], $starts('min_missed=2'));
        $this->assertSame([], $starts('min_missed=3'));
        $this->assertSame(['2026-03-01'], $starts('due_from=2026-03-01&due_to=2026-03-31'));

        $this->getJson('/api/v1/interest-periods?status=overdue')
            ->assertJsonPath('data.0.unpaid_interest', '150.00')
            ->assertJsonPath('data.0.status', 'overdue')
            ->assertJsonPath('data.0.loan_consecutive_missed', 2)
            ->assertJsonPath('data.0.loan.customer.customer_no', $loan->customer->customer_no);

        $this->getJson("/api/v1/loans/{$loan->loan_no}/interest-periods")
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.status', 'paid');

        Carbon::setTestNow('2026-04-30 10:00:00');
        $this->assertSame(['2026-04-01'], $starts('status=due'));
    }

    public function test_alert_and_due_views_require_loan_access(): void
    {
        $loan = $this->loan();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/alerts')->assertForbidden();
        $this->getJson('/api/v1/dashboard/missed-payment-alerts')->assertForbidden();
        $this->getJson('/api/v1/interest-periods')->assertForbidden();
        $this->getJson("/api/v1/loans/{$loan->loan_no}/interest-periods")->assertForbidden();
    }
}
