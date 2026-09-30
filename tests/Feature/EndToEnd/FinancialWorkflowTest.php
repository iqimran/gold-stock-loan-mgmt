<?php

namespace Tests\Feature\EndToEnd;

use App\Domain\Reporting\Export\ExportDatasets;
use App\Domain\Settings\LoanSettings;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 021 — end-to-end financial workflows, driven through the HTTP API exactly as the counter uses it,
 * by staff with realistic, separated permissions, with business days advanced by the daily interest run
 * (`loans:process-interest`). Figures are asserted against hand-calculated values and, for the reports,
 * against the transactional tables.
 *
 * Business rules exercised (decided by the shop): interest on the principal amount outstanding at the
 * start of each monthly anniversary period, due on the period's last day; a missed period is one past due
 * and not fully paid; payments settle the oldest interest first; closing needs zero principal and no
 * interest due; collateral is released only after closing.
 *
 * Deterministic data: loans start 2026-01-01 at 2% a month, so each period's interest is exact.
 */
class FinancialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;   // customers + loans + collateral intake

    private User $cashier;   // takes payments

    private User $manager;   // reversals, closing, collateral release

    private User $auditor;   // read-only: everything incl. reports

    private int $customers = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer = $this->staff('Loan Officer', Permission::CustomersView, Permission::CustomersCreate, Permission::LoansView, Permission::LoansCreate,
            Permission::LoansUpdate, Permission::CollateralView, Permission::CollateralCreate);
        $this->cashier = $this->staff('Cashier', Permission::LoansView, Permission::CustomersView, Permission::PaymentsView, Permission::PaymentsCreate);
        $this->manager = $this->staff('Branch Manager', Permission::LoansView, Permission::LoansClose, Permission::LoansCancel, Permission::PaymentsView,
            Permission::PaymentsReverse, Permission::CollateralView, Permission::CollateralRelease);
        $this->auditor = $this->staff('Auditor', Permission::CustomersView, Permission::LoansView, Permission::PaymentsView, Permission::CollateralView,
            Permission::ReportsView, Permission::ReportsExport);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    private function staff(string $name, Permission ...$permissions): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo(array_map(fn (Permission $p) => $p->value, $permissions));

        return $user->fresh();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** The daily interest run on a business date (00:05). */
    private function runOn(string $date): void
    {
        Carbon::setTestNow("{$date} 00:05:00");
        $this->artisan('loans:process-interest')->assertSuccessful();
    }

    /** Office hours on a business date. */
    private function at(string $date): void
    {
        Carbon::setTestNow("{$date} 10:00:00");
    }

    /**
     * Customer → draft loan → collateral → activation, as the loan officer. Returns the loan number.
     */
    private function openLoan(string $principal, string $start = '2026-01-01', ?string $customerNo = null, string $name = 'Salma Begum'): string
    {
        $this->at($start);
        $this->as($this->officer);

        $n = str_pad((string) ++$this->customers, 3, '0', STR_PAD_LEFT);
        $customerNo ??= $this->postJson('/api/v1/customers', ['name' => $name, 'mobile' => "01711000{$n}", 'nid' => "1990123456{$n}", 'address' => 'Tanti Bazar, Dhaka'])
            ->assertCreated()->json('data.customer_no');

        $loanNo = $this->postJson('/api/v1/loans', [
            'customer' => $customerNo, 'principal' => $principal, 'interest_rate' => '2', 'interest_rate_type' => 'monthly',
            'interest_period_unit' => 'month', 'start_date' => $start,
        ])->assertCreated()->json('data.loan_no');

        $this->postJson("/api/v1/loans/{$loanNo}/collateral", ['type' => 'gold', 'weight_grams' => '23.328', 'karat' => '22', 'estimated_value' => bcmul($principal, '1.5', 2), 'description' => 'Two bangles'])
            ->assertCreated();
        $this->postJson("/api/v1/loans/{$loanNo}/activate")->assertOk()->assertJsonPath('data.status', 'active');

        return $loanNo;
    }

    private function pay(string $loanNo, string $type, string $amount, ?string $date = null): TestResponse
    {
        if ($date !== null) {
            $this->at($date);
        }

        return $this->as($this->cashier)->postJson('/api/v1/payments', [
            'loan' => $loanNo, 'type' => $type, 'amount' => $amount, 'method' => 'cash',
            'payment_date' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function loan(string $loanNo): array
    {
        return $this->as($this->auditor)->getJson("/api/v1/loans/{$loanNo}")->assertOk()->json('data');
    }

    /**
     * @return list<array{start: string, due: string, expected: string, paid: string, status: string}>
     */
    private function periods(string $loanNo): array
    {
        return array_map(fn (array $p) => ['start' => $p['period_start'], 'due' => $p['due_date'], 'expected' => $p['expected_interest'], 'paid' => $p['paid_interest'], 'status' => $p['status']],
            $this->as($this->auditor)->getJson("/api/v1/loans/{$loanNo}/interest-periods")->assertOk()->json('data'));
    }

    private function ledgerTotals(string $customerNo, ?string $loanNo = null): array
    {
        $url = $loanNo ? "/api/v1/loans/{$loanNo}/ledger" : "/api/v1/customers/{$customerNo}/ledger";

        return $this->as($this->auditor)->getJson($url)->assertOk()->json('totals');
    }

    private function customerOf(string $loanNo): string
    {
        return $this->loan($loanNo)['customer']['customer_no'];
    }

    // ── Scenario 1: customer → loan → collateral ────────────────────────────────────────────

    public function test_scenario_1_customer_loan_and_collateral_are_opened_together(): void
    {
        $loanNo = $this->openLoan('100000');
        $loan = $this->loan($loanNo);
        $customerNo = $loan['customer']['customer_no'];

        $this->assertSame(['active', '100000.00', '100000.00', '2026-01-31'], [$loan['status'], $loan['principal'], $loan['outstanding_principal'], $loan['next_due_date']]);
        $this->assertStringStartsWith('LN-202601-', $loanNo);

        $collateral = $this->as($this->auditor)->getJson("/api/v1/loans/{$loanNo}/collateral")->assertOk()->json('data');
        $this->assertCount(1, $collateral);
        $this->assertSame(['gold', '23.328', 'held', '150000.00'], [$collateral[0]['type'], $collateral[0]['weight_grams'], $collateral[0]['status'], $collateral[0]['estimated_value']]);

        $customer = $this->as($this->auditor)->getJson("/api/v1/customers/{$customerNo}")->assertOk()->json('data');
        $this->assertSame(1, $customer['summary']['active_loans']);
        $this->assertSame('2026-01-31', $customer['summary']['next_due_date']);

        // The disbursement is on the customer's statement; every step is audited with its actor.
        $this->assertSame(['debit' => '100000.00', 'closing_balance' => '100000.00'], array_intersect_key($this->ledgerTotals($customerNo), ['debit' => 1, 'closing_balance' => 1]));
        $this->assertSame(
            ['customer.created', 'loan.created', 'collateral.created', 'loan.status_changed'],
            AuditLog::query()->orderBy('id')->pluck('event')->all(),
        );
        $this->assertSame([$this->officer->id], AuditLog::query()->distinct()->pluck('user_id')->all());
    }

    // ── Scenario 2: interest period → interest payment → paid ───────────────────────────────

    public function test_scenario_2_an_interest_period_is_generated_paid_and_marked_paid(): void
    {
        $loanNo = $this->openLoan('100000');

        $this->runOn('2026-01-15');
        $this->assertSame([['start' => '2026-01-01', 'due' => '2026-01-31', 'expected' => '2000.00', 'paid' => '0.00', 'status' => 'upcoming']], $this->periods($loanNo));

        $this->runOn('2026-01-31');
        $this->assertSame('due', $this->periods($loanNo)[0]['status']);

        $payment = $this->pay($loanNo, 'interest', '2000', '2026-01-31')->assertCreated()->json('data');
        $this->assertSame(['2000.00', '2000.00', '0.00'], [$payment['amount'], $payment['allocation']['interest'], $payment['allocation']['principal']]);
        $this->assertSame('2026-01-31', $payment['allocation']['periods'][0]['due_date']);

        $this->assertSame(['paid', '2000.00'], [$this->periods($loanNo)[0]['status'], $this->periods($loanNo)[0]['paid']]);
        $loan = $this->loan($loanNo);
        $this->assertSame(['active', '100000.00', '2026-02-28'], [$loan['status'], $loan['outstanding_principal'], $loan['next_due_date']]);

        // Statement: 100,000 disbursed + 2,000 interest charged − 2,000 paid.
        $totals = $this->ledgerTotals($this->customerOf($loanNo));
        $this->assertSame(['102000.00', '2000.00', '100000.00'], [$totals['debit'], $totals['credit'], $totals['closing_balance']]);

        // The receipt exists and paying more interest than is payable is refused.
        $this->as($this->cashier)->getJson("/api/v1/payments/{$payment['receipt_no']}")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->pay($loanNo, 'interest', '0.01', '2026-01-31')->assertUnprocessable();
    }

    // ── Scenario 3: missed periods → consecutive count → threshold → alert ──────────────────

    public function test_scenario_3_missed_periods_raise_an_alert_at_the_configured_threshold(): void
    {
        app(LoanSettings::class)->update(['collection.alert_threshold' => 2]);
        $loanNo = $this->openLoan('50000'); // 1,000.00 a month
        $customerNo = $this->customerOf($loanNo);
        $alerts = fn () => $this->as($this->auditor)->getJson("/api/v1/alerts?loan={$loanNo}&status=all")->assertOk()->json('data');

        $this->runOn('2026-02-01'); // January missed → 1
        $this->assertSame('overdue', $this->periods($loanNo)[0]['status']);
        $this->assertSame('overdue', $this->loan($loanNo)['status']);
        $this->assertSame(1, $this->as($this->auditor)->getJson("/api/v1/customers/{$customerNo}")->json('data.summary.consecutive_missed'));
        $this->assertSame([], $alerts());

        $this->runOn('2026-03-01'); // February missed → 2 = threshold
        $this->assertSame(2, $this->as($this->auditor)->getJson("/api/v1/customers/{$customerNo}")->json('data.summary.consecutive_missed'));
        $alert = $alerts();
        $this->assertCount(1, $alert);
        $this->assertSame(['open', 2, '2026-02-28'], [$alert[0]['status'], $alert[0]['threshold'], $alert[0]['triggering_period']['due_date']]);
        $this->as($this->auditor)->getJson('/api/v1/dashboard/missed-payment-alerts')->assertOk()->assertJsonPath('data.open', 1)->assertJsonPath('data.threshold', 2);

        // A partial payment does not reset the streak; the alert stays open.
        $this->pay($loanNo, 'interest', '500', '2026-03-02')->assertCreated();
        $this->runOn('2026-03-03');
        $this->assertSame('open', $alerts()[0]['status']);
        $this->assertSame(2, $this->as($this->auditor)->getJson("/api/v1/customers/{$customerNo}")->json('data.summary.consecutive_missed'));

        // Settling both missed months (oldest first: 500 left on Jan + 1,000 Feb) resolves it.
        $this->pay($loanNo, 'interest', '1500', '2026-03-03')->assertCreated();
        $this->assertSame(['paid', 'paid', 'upcoming'], array_column($this->periods($loanNo), 'status'));
        $this->assertSame('resolved', $alerts()[0]['status']);
        $this->assertSame('active', $this->loan($loanNo)['status']);
        $this->assertSame(0, $this->as($this->auditor)->getJson("/api/v1/customers/{$customerNo}")->json('data.summary.consecutive_missed'));
    }

    // ── Scenario 4: principal payment → outstanding decreases ───────────────────────────────

    public function test_scenario_4_a_principal_payment_reduces_the_outstanding_principal_and_later_interest(): void
    {
        $loanNo = $this->openLoan('100000');
        $this->runOn('2026-01-31');
        $this->pay($loanNo, 'interest', '2000', '2026-01-31')->assertCreated();

        $this->runOn('2026-02-10');
        $payment = $this->pay($loanNo, 'principal', '40000', '2026-02-10')->assertCreated()->json('data');
        $this->assertSame(['40000.00', '0.00'], [$payment['allocation']['principal'], $payment['allocation']['interest']]);
        $this->assertSame('60000.00', $this->loan($loanNo)['outstanding_principal']);

        // Paying more principal than is outstanding is refused.
        $this->pay($loanNo, 'principal', '60000.01', '2026-02-10')->assertUnprocessable();

        $this->runOn('2026-03-01');
        $periods = $this->periods($loanNo);
        $this->assertSame(['2000.00', '2000.00', '1200.00'], array_column($periods, 'expected')); // Feb already running on 100,000; Mar on 60,000
        $this->assertSame('overdue', $periods[1]['status']); // interest payments were not made by the principal payment
    }

    // ── Scenario 5: combined payment → interest then principal ──────────────────────────────

    public function test_scenario_5_a_combined_payment_clears_interest_oldest_first_then_principal(): void
    {
        $loanNo = $this->openLoan('100000');
        $this->runOn('2026-03-10'); // Jan + Feb overdue, Mar running: 6,000.00 interest payable

        $payment = $this->pay($loanNo, 'principal_and_interest', '16000', '2026-03-10')->assertCreated()->json('data');
        $this->assertSame(['16000.00', '6000.00', '10000.00', '0.00'], [$payment['amount'], $payment['allocation']['interest'], $payment['allocation']['principal'], $payment['allocation']['fee']]);
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($payment['allocation']['periods'], 'due_date'));

        $loan = $this->loan($loanNo);
        $this->assertSame(['active', '90000.00', '2026-04-30'], [$loan['status'], $loan['outstanding_principal'], $loan['next_due_date']]);
        $this->assertSame(['paid', 'paid', 'paid'], array_column($this->periods($loanNo), 'status'));

        // Statement: 100,000 + Jan/Feb interest charged (Mar is prepaid, not yet due) − 16,000 = 88,000.
        $this->assertSame('88000.00', $this->ledgerTotals($this->customerOf($loanNo))['closing_balance']);

        $this->runOn('2026-04-01');
        $this->assertSame('1800.00', $this->periods($loanNo)[3]['expected']); // April on 90,000
    }

    // ── Scenario 6: reversal → balances, ledger and interest status restored ────────────────

    public function test_scenario_6_reversing_a_payment_restores_balances_ledger_and_interest_status(): void
    {
        $loanNo = $this->openLoan('100000');
        $customerNo = $this->customerOf($loanNo);
        $this->runOn('2026-03-10');
        $before = ['loan' => $this->loan($loanNo), 'periods' => $this->periods($loanNo), 'ledger' => $this->ledgerTotals($customerNo)];

        $receipt = $this->pay($loanNo, 'principal_and_interest', '16000', '2026-03-10')->assertCreated()->json('data.receipt_no');

        // Only a manager may reverse, and only with a reason.
        $this->as($this->cashier)->postJson("/api/v1/payments/{$receipt}/reverse", ['reason' => 'Wrong loan'])->assertForbidden();
        $this->as($this->manager)->postJson("/api/v1/payments/{$receipt}/reverse", [])->assertJsonValidationErrors('reason');

        $this->at('2026-03-12');
        $this->as($this->manager)->postJson("/api/v1/payments/{$receipt}/reverse", ['reason' => 'Posted to the wrong loan'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed')
            ->assertJsonPath('data.reversal_reason', 'Posted to the wrong loan');

        $loan = $this->loan($loanNo);
        $this->assertSame([$before['loan']['outstanding_principal'], 'overdue'], [$loan['outstanding_principal'], $loan['status']]);
        $this->assertSame(['overdue', 'overdue', 'upcoming'], array_column($this->periods($loanNo), 'status'));
        $this->assertSame(['0.00', '0.00', '0.00'], array_column($this->periods($loanNo), 'paid'));

        // Ledger: the original credit stays, a compensating debit restores what is owed.
        $ledger = $this->ledgerTotals($customerNo);
        $this->assertSame($before['ledger']['closing_balance'], $ledger['closing_balance']);
        $this->assertSame(bcadd($before['ledger']['credit'], '16000.00', 2), $ledger['credit']);
        $this->assertSame(bcadd($before['ledger']['debit'], '16000.00', 2), $ledger['debit']);

        // History remains visible; the receipt cannot be reversed twice; the reversal is audited.
        $this->as($this->cashier)->getJson("/api/v1/payments?loan={$loanNo}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'reversed');
        $this->as($this->manager)->postJson("/api/v1/payments/{$receipt}/reverse", ['reason' => 'Again'])->assertUnprocessable();
        $audit = AuditLog::query()->where('event', 'payment.reversed')->sole();
        $this->assertSame([$this->manager->id, 'Posted to the wrong loan'], [$audit->user_id, $audit->new_values['reason']]);
    }

    // ── Scenario 7: full settlement → close → release collateral ────────────────────────────

    public function test_scenario_7_full_settlement_closes_the_loan_and_frees_the_collateral(): void
    {
        $loanNo = $this->openLoan('50000');
        $customerNo = $this->customerOf($loanNo);
        $collateralNo = $this->as($this->auditor)->getJson("/api/v1/loans/{$loanNo}/collateral")->json('data.0.collateral_no');
        $this->runOn('2026-02-10'); // Jan overdue (1,000), Feb running (1,000)

        // Not settled yet: closing and release are refused.
        $this->at('2026-02-10');
        $this->as($this->manager)->postJson("/api/v1/loans/{$loanNo}/close")->assertUnprocessable();
        $this->as($this->manager)->postJson("/api/v1/collateral/{$collateralNo}/release", ['reason' => 'Early'])->assertUnprocessable();

        $this->pay($loanNo, 'principal_and_interest', '52000', '2026-02-10')->assertCreated()
            ->assertJsonPath('data.allocation.interest', '2000.00')
            ->assertJsonPath('data.allocation.principal', '50000.00');
        $this->assertSame('0.00', $this->loan($loanNo)['outstanding_principal']);
        $this->assertSame(['paid', 'paid'], array_column($this->periods($loanNo), 'status'));

        // The cashier cannot close; the manager can.
        $this->as($this->cashier)->postJson("/api/v1/loans/{$loanNo}/close")->assertForbidden();
        $this->as($this->manager)->postJson("/api/v1/loans/{$loanNo}/close", ['note' => 'Settled in full'])
            ->assertOk()->assertJsonPath('data.status', 'closed');

        // The closed loan's statement balances to zero; no more interest or payments.
        $this->assertSame('0.00', $this->ledgerTotals($customerNo, $loanNo)['closing_balance']);
        $this->runOn('2026-04-01');
        $this->assertCount(2, $this->periods($loanNo));
        $this->pay($loanNo, 'interest', '1', '2026-04-01')->assertUnprocessable();

        // Collateral is now eligible and released by the manager with a reason, exactly once.
        $this->as($this->cashier)->postJson("/api/v1/collateral/{$collateralNo}/release", ['reason' => 'Returned'])->assertForbidden();
        $this->as($this->manager)->postJson("/api/v1/collateral/{$collateralNo}/release", [])->assertJsonValidationErrors('reason');
        $this->as($this->manager)->postJson("/api/v1/collateral/{$collateralNo}/release", ['reason' => 'Returned to customer in person'])
            ->assertOk()->assertJsonPath('data.status', 'released');
        $this->as($this->manager)->postJson("/api/v1/collateral/{$collateralNo}/release", ['reason' => 'Again'])->assertUnprocessable();

        $this->assertSame(0, $this->as($this->auditor)->getJson("/api/v1/customers/{$customerNo}")->json('data.summary.active_loans'));
        $this->assertSame(
            ['loan.status_changed', 'collateral.released'],
            AuditLog::query()->where('user_id', $this->manager->id)->orderBy('id')->pluck('event')->all(),
        );
    }

    // ── Scenario 8: reports match the transactional records ─────────────────────────────────

    public function test_scenario_8_report_totals_match_the_transactional_records(): void
    {
        // A small portfolio: two customers, three loans, interest, principal, a reversal and a closure.
        $a = $this->openLoan('100000', '2026-01-01', null, 'Salma Begum');
        $customerA = $this->customerOf($a);
        $b = $this->openLoan('50000', '2026-01-01', $customerA);
        $c = $this->openLoan('30000', '2026-01-01', null, 'Rahim Uddin');

        $this->runOn('2026-01-31');
        $this->pay($a, 'interest', '2000', '2026-01-31')->assertCreated();
        $this->pay($b, 'interest', '1000', '2026-01-31')->assertCreated();
        $this->runOn('2026-02-15');
        $this->pay($a, 'principal', '20000', '2026-02-15')->assertCreated();
        $wrong = $this->pay($c, 'interest', '600', '2026-02-15')->assertCreated()->json('data.receipt_no');
        $this->at('2026-02-15');
        $this->as($this->manager)->postJson("/api/v1/payments/{$wrong}/reverse", ['reason' => 'Keyed twice'])->assertOk();
        $this->pay($b, 'principal_and_interest', '51000', '2026-02-15')->assertCreated(); // Feb 1,000 + 50,000 → settled
        $this->at('2026-02-16');
        $this->as($this->manager)->postJson("/api/v1/loans/{$b}/close")->assertOk();
        $this->runOn('2026-03-05');

        $this->as($this->auditor);

        // Collections: posted payments only, split by allocation.
        $collections = $this->getJson('/api/v1/reports/collections?paid_from=2026-01-01&paid_to=2026-03-31')->assertOk()->json('totals');
        $posted = Payment::query()->whereNull('reversed_at');
        $split = DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->whereNull('p.reversed_at')
            ->selectRaw('sum(a.interest_amount) as interest, sum(a.principal_amount) as principal')->first();
        $this->assertSame(['74000.00', 4, '4000.00', '70000.00'], [$collections['gross'], $collections['payments'], $collections['interest'], $collections['principal']]);
        $this->assertSame([(clone $posted)->count(), bcadd((string) (clone $posted)->sum('amount'), '0', 2)], [$collections['payments'], $collections['gross']]);
        $this->assertSame([bcadd((string) $split->interest, '0', 2), bcadd((string) $split->principal, '0', 2)], [$collections['interest'], $collections['principal']]);

        // Loan outstanding: open loans A (80,000) and C (30,000); B is closed.
        $outstanding = $this->getJson('/api/v1/reports/loan-outstanding')->assertOk()->json('totals');
        $this->assertSame([2, '110000.00'], [$outstanding['loans'], $outstanding['outstanding_principal']]);
        $this->assertSame(bcadd((string) DB::table('loans')->whereIn('status', ['active', 'overdue'])->sum('outstanding_principal'), '0', 2), $outstanding['outstanding_principal']);
        // Due interest to date: A Feb 2,000 (Mar on 80,000 not yet due) + C Jan 600 + Feb 600.
        $this->assertSame('3200.00', $outstanding['due_interest']);

        // Due report (all unsettled periods of open loans, incl. running March): matches the periods table.
        $due = $this->getJson('/api/v1/reports/due')->assertOk()->json('totals');
        $unsettled = DB::table('interest_periods as p')->join('loans as l', 'l.id', '=', 'p.loan_id')->whereIn('l.status', ['active', 'overdue'])
            ->whereNull('p.waived_at')->whereColumn('p.paid_interest', '<', 'p.expected_interest')
            ->selectRaw('count(*) as n, sum(p.expected_interest - p.paid_interest) as amount')->first();
        $this->assertSame([(int) $unsettled->n, bcadd((string) $unsettled->amount, '0', 2)], [$due['periods'], $due['balance_due']]);
        $this->assertSame('5400.00', $due['balance_due']); // A: Feb 2,000 + Mar 1,600 (on 80,000); C: Jan, Feb, Mar 600 each

        // Customer ledger: equals the ledger table and, for the customer, what they owe.
        $ledger = $this->getJson("/api/v1/customers/{$customerA}/ledger")->assertOk()->json('totals');
        $rows = DB::table('ledger_entries as e')->join('customers as c', 'c.id', '=', 'e.customer_id')->where('c.customer_no', $customerA)
            ->selectRaw('sum(e.debit) as debit, sum(e.credit) as credit')->first();
        $this->assertSame([bcadd((string) $rows->debit, '0', 2), bcadd((string) $rows->credit, '0', 2)], [$ledger['debit'], $ledger['credit']]);
        $this->assertSame('82000.00', $ledger['closing_balance']); // A: 80,000 principal + Feb 2,000 due; B settled to 0

        // Collateral: three items received, all still held (B's is eligible for release but not yet released).
        $collateral = $this->getJson('/api/v1/reports/collateral')->assertOk()->json('totals');
        $this->assertSame([3, 3, 0], [$collateral['items'], $collateral['held']['items'], $collateral['released']['items']]);

        // Exports carry exactly the report's figures.
        $dataset = app(ExportDatasets::class)->collections(['paid_from' => '2026-01-01', 'paid_to' => '2026-03-31']);
        $this->assertCount(4, $dataset->rows);
        $this->getJson('/api/v1/reports/collections/export?format=xlsx&paid_from=2026-01-01&paid_to=2026-03-31')->assertOk()->assertDownload();
    }

    // ── authorization boundaries ────────────────────────────────────────────────────────────

    public function test_each_role_is_confined_to_its_own_actions(): void
    {
        // Nobody signed in: nothing, not even reading.
        $this->getJson('/api/v1/loans')->assertUnauthorized();
        $this->postJson('/api/v1/payments', [])->assertUnauthorized();

        $loanNo = $this->openLoan('10000');
        $this->runOn('2026-01-31');
        $receipt = $this->pay($loanNo, 'interest', '200', '2026-01-31')->assertCreated()->json('data.receipt_no');
        $collateralNo = $this->as($this->auditor)->getJson("/api/v1/loans/{$loanNo}/collateral")->json('data.0.collateral_no');
        $customerNo = $this->customerOf($loanNo);
        $newLoan = ['customer' => $customerNo, 'principal' => '1000', 'interest_rate' => '2', 'interest_rate_type' => 'monthly', 'interest_period_unit' => 'month', 'start_date' => '2026-01-31'];
        $payment = ['loan' => $loanNo, 'type' => 'interest', 'amount' => '1', 'method' => 'cash', 'payment_date' => '2026-01-31', 'idempotency_key' => 'boundary-key-1'];

        // Cashier: takes payments; cannot lend, reverse, close, release or read reports.
        $this->as($this->cashier);
        $this->postJson('/api/v1/loans', $newLoan)->assertForbidden();
        $this->postJson("/api/v1/payments/{$receipt}/reverse", ['reason' => 'x x x'])->assertForbidden();
        $this->postJson("/api/v1/loans/{$loanNo}/close")->assertForbidden();
        $this->postJson("/api/v1/collateral/{$collateralNo}/release", ['reason' => 'x x x'])->assertForbidden();
        $this->getJson('/api/v1/reports/collections')->assertForbidden();
        $this->getJson('/api/v1/payments/export')->assertForbidden();

        // Loan officer: lends and takes collateral; cannot post or reverse payments.
        $this->as($this->officer);
        $this->postJson('/api/v1/payments', $payment)->assertForbidden();
        $this->postJson("/api/v1/payments/{$receipt}/reverse", ['reason' => 'x x x'])->assertForbidden();
        $this->postJson("/api/v1/loans/{$loanNo}/cancel", ['reason' => 'x x x'])->assertForbidden();

        // Manager: approves; does not take payments or create loans.
        $this->as($this->manager);
        $this->postJson('/api/v1/payments', $payment)->assertForbidden();
        $this->postJson('/api/v1/loans', $newLoan)->assertForbidden();

        // Auditor: reads everything, changes nothing.
        $this->as($this->auditor);
        $this->getJson('/api/v1/reports/due')->assertOk();
        $this->postJson('/api/v1/payments', $payment)->assertForbidden();
        $this->postJson("/api/v1/payments/{$receipt}/reverse", ['reason' => 'x x x'])->assertForbidden();
        $this->postJson("/api/v1/loans/{$loanNo}/close")->assertForbidden();

        // Nothing changed through the refused requests.
        $this->assertSame(1, Payment::count());
        $this->assertSame(['active', 'held'], [$this->loan($loanNo)['status'], $this->as($this->auditor)->getJson("/api/v1/collateral/{$collateralNo}")->json('data.status')]);
    }
}
