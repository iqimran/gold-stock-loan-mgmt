<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Loan\LoanService;
use App\Domain\Payment\PaymentReversalService;
use App\Domain\Payment\PaymentService;
use App\Domain\Reporting\DashboardMetricsService;
use App\Enums\LoanStatus;
use App\Enums\Permission;
use App\Models\Loan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dashboard metrics. "Today" is 2026-12-15 (APP_TIMEZONE UTC). Hand-calculated scenario:
 *
 *  A  10,000.00 at 2%/month from 2026-10-01 (active → overdue): Oct + Nov interest due (200.00 each), Dec upcoming.
 *     Pays 150.00 interest (Oct partly paid) — and 50.00 that is reversed.
 *  B   5,000.00 from 2026-12-01: Dec upcoming. Pays 1,000.00 principal and a 25.00 fee.
 *  C   3,000.00 draft.   D   closed, with two November payments (500.00 on 11-20, 80.00 on 11-30).
 */
class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Loan $a;

    protected function setUp(): void
    {
        parent::setUp();

        config(['loans.alerts.missed_period_threshold' => 2]);
        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->staff = User::factory()->create();
        $this->staff->givePermissionTo([Permission::LoansView->value, Permission::PaymentsView->value]);

        $loans = app(LoanService::class);
        $payments = app(PaymentService::class);
        $pay = fn (Loan $loan, string $type, string $amount) => $payments->post($loan->fresh(), ['type' => $type, 'amount' => $amount, 'method' => 'cash', 'payment_date' => '2026-12-15'], $this->staff);

        $this->a = $loans->activate(Loan::factory()->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-10-01']), $this->staff);
        $b = $loans->activate(Loan::factory()->create(['principal' => '5000.00', 'outstanding_principal' => '5000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-12-01']), $this->staff);
        Loan::factory()->create(['principal' => '3000.00', 'outstanding_principal' => '3000.00']);
        $d = Loan::factory()->status(LoanStatus::Closed)->create(['outstanding_principal' => '0.00']);

        $pay($this->a, 'interest', '150');
        app(PaymentReversalService::class)->reverse($pay($this->a, 'interest', '50'), $this->staff, 'Entered twice');
        $pay($b, 'principal', '1000');
        $pay($b, 'other_fee', '25');

        foreach ([['R-NOV-1', '2026-11-20', '500.00'], ['R-NOV-2', '2026-11-30', '80.00']] as [$receipt, $date, $amount]) {
            $id = DB::table('payments')->insertGetId([
                'receipt_no' => $receipt, 'customer_id' => $d->customer_id, 'loan_id' => $d->id, 'type' => 'principal', 'amount' => $amount,
                'method' => 'cash', 'payment_date' => $date, 'status' => 'posted',
            ]);
            DB::table('payment_allocations')->insert(['payment_id' => $id, 'principal_amount' => $amount, 'total_amount' => $amount]);
        }
    }

    private function metrics(): DashboardMetricsService
    {
        return app(DashboardMetricsService::class);
    }

    public function test_loan_summary_balances_as_of_today(): void
    {
        $this->assertSame([
            'active_loans' => 2,                    // A and B; the draft and the closed loan don't count
            'overdue_loans' => 1,                   // A
            'draft_loans' => 1,
            'outstanding_principal' => '14000.00',  // A 10,000 + B 4,000
            'due_interest' => '250.00',             // Oct 50 left + Nov 200 (Dec not due yet)
            'due_periods' => 2,
            'overdue_interest' => '250.00',
            'overdue_accounts' => 1,
            'overdue_customers' => 1,
        ], $this->metrics()->loanSummary());
    }

    public function test_monthly_collections_split_by_component_without_reversed_payments(): void
    {
        $this->assertSame([
            'month' => '2026-12', 'from' => '2026-12-01', 'to' => '2026-12-31',
            'total' => '1175.00',   // 150 + 1,000 + 25 (the reversed 50 is excluded)
            'payments' => 3,
            'interest' => '150.00',
            'principal' => '1000.00',
            'fees' => '25.00',
            'revenue' => '175.00',  // interest + fees
        ], $this->metrics()->collections(CarbonImmutable::parse('2026-12-15')));
    }

    public function test_the_month_includes_its_first_and_last_day(): void
    {
        $november = $this->metrics()->collections(CarbonImmutable::parse('2026-11-01'));

        $this->assertSame('580.00', $november['total']); // 11-20 and 11-30 (the last day)
        $this->assertSame(2, $november['payments']);
        $this->assertSame('0.00', $this->metrics()->collections(CarbonImmutable::parse('2026-10-01'))['total']);
    }

    public function test_overdue_accounts_and_alerts(): void
    {
        $this->assertSame([[
            'loan_no' => $this->a->loan_no,
            'status' => 'overdue',
            'customer' => ['customer_no' => $this->a->customer->customer_no, 'name' => $this->a->customer->name, 'mobile' => $this->a->customer->mobile],
            'overdue_interest' => '250.00',
            'overdue_periods' => 2,
            'oldest_due_date' => '2026-10-31',
            'consecutive_missed' => 2, // Oct is only partly paid, so it still counts
        ]], $this->metrics()->overdueAccounts());

        $this->assertSame(['open' => 1, 'customers' => 1, 'threshold' => 2], $this->metrics()->alerts());
    }

    public function test_recent_payments_and_loans_newest_first(): void
    {
        $payments = $this->metrics()->recentPayments();
        $this->assertCount(5, $payments);
        $this->assertSame('R-NOV-2', $payments[0]['receipt_no']);
        $this->assertContains('reversed', array_column($payments, 'status')); // reversals stay visible

        $loans = $this->metrics()->recentLoans();
        $this->assertSame(['closed', 'draft', 'active', 'overdue'], array_column($loans, 'status'));
    }

    public function test_figures_agree_with_the_lists_they_summarise(): void
    {
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/v1/loans?overdue=1')->assertJsonPath('meta.total', $this->metrics()->loanSummary()['overdue_accounts']);
        $this->getJson('/api/v1/loans?status=open')->assertJsonPath('meta.total', $this->metrics()->loanSummary()['active_loans']);
        $this->getJson('/api/v1/payments?paid_from=2026-12-01&paid_to=2026-12-31&status=posted')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/payments?paid_from=2026-11-30&paid_to=2026-11-30')->assertJsonPath('meta.total', 1); // same-day range
    }

    // ── the screen ──────────────────────────────────────────────────────────────────────────

    public function test_dashboard_shows_every_block_to_staff_with_both_permissions(): void
    {
        $this->actingAs($this->staff)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('asOf', '2026-12-15')
                ->where('timezone', 'UTC')
                ->where('month', '2026-12')
                ->where('loanSummary.due_interest', '250.00')
                ->where('collections.total', '1175.00')
                ->where('alerts.open', 1)
                ->has('overdueAccounts', 1)
                ->has('recentPayments', 5)
                ->has('recentLoans', 4));
    }

    public function test_blocks_follow_permissions(): void
    {
        $loansOnly = tap(User::factory()->create())->givePermissionTo(Permission::LoansView->value);
        $this->actingAs($loansOnly)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('loanSummary.active_loans', 2)
            ->where('collections', null)
            ->where('recentPayments', null));

        $paymentsOnly = tap(User::factory()->create())->givePermissionTo(Permission::PaymentsView->value);
        $this->actingAs($paymentsOnly)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('collections.total', '1175.00')
            ->where('loanSummary', null)
            ->where('alerts', null)
            ->where('overdueAccounts', null));

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('loanSummary', null)
            ->where('collections', null));
    }

    public function test_the_collections_month_can_be_chosen_but_not_in_the_future(): void
    {
        $this->actingAs($this->staff)->get('/dashboard?month=2026-11')
            ->assertInertia(fn (Assert $page) => $page->where('month', '2026-11')->where('collections.total', '580.00'));

        $this->actingAs($this->staff)->from('/dashboard')->get('/dashboard?month=2027-01')->assertRedirect('/dashboard')->assertSessionHasErrors('month');
        $this->actingAs($this->staff)->from('/dashboard')->get('/dashboard?month=december')->assertSessionHasErrors('month');
    }

    public function test_the_default_month_follows_the_application_time_zone(): void
    {
        $original = date_default_timezone_get();
        config(['app.timezone' => 'Asia/Dhaka']);
        date_default_timezone_set('Asia/Dhaka');

        try {
            // 31 Dec 20:00 UTC is already 1 Jan 2027 (02:00) in Dhaka.
            Carbon::setTestNow(Carbon::parse('2026-12-31 20:00:00', 'UTC'));

            $this->actingAs($this->staff)->get('/dashboard')
                ->assertInertia(fn (Assert $page) => $page->where('asOf', '2027-01-01')->where('month', '2027-01')->where('timezone', 'Asia/Dhaka'));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_api_endpoints_serve_the_same_figures(): void
    {
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/v1/dashboard/loan-summary')->assertOk()->assertJsonPath('data.outstanding_principal', '14000.00')->assertJsonPath('data.as_of', '2026-12-15');
        $this->getJson('/api/v1/dashboard/collections?month=2026-11')->assertOk()->assertJsonPath('data.total', '580.00');
        $this->getJson('/api/v1/dashboard/due-interest')
            ->assertOk()
            ->assertJsonPath('data.due_to_date.amount', '250.00')
            ->assertJsonPath('data.overdue.periods', 2)
            ->assertJsonPath('data.upcoming_7_days.amount', '0.00');
        $this->getJson('/api/v1/dashboard/overdue-accounts')->assertOk()->assertJsonPath('data.0.loan_no', $this->a->loan_no);

        Sanctum::actingAs(tap(User::factory()->create())->givePermissionTo(Permission::LoansView->value));
        $this->getJson('/api/v1/dashboard/collections')->assertForbidden();
        Sanctum::actingAs(tap(User::factory()->create())->givePermissionTo(Permission::PaymentsView->value));
        $this->getJson('/api/v1/dashboard/loan-summary')->assertForbidden();
    }
}
