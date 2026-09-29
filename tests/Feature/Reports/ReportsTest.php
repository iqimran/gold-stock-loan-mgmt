<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\CustomerLedgerService;
use App\Domain\Loan\LoanService;
use App\Domain\Payment\PaymentReversalService;
use App\Domain\Payment\PaymentService;
use App\Enums\CollateralStatus;
use App\Enums\LoanStatus;
use App\Enums\Permission;
use App\Models\CollateralItem;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reports against one known dataset ("today" 2026-12-15; every total below is worked out by hand).
 *
 *  Customer X  loan A  10,000.00 @ 2%/month from 2026-10-01 — Oct, Nov due (200.00 each), Dec upcoming.
 *                      staff1 takes 150.00 interest (cash; Oct partly paid) and 50.00 that is reversed.
 *              loan B   5,000.00 @ 2%/month from 2026-12-01 — Dec upcoming (100.00).
 *                      staff2 takes 1,000.00 principal (bank) and a 25.00 fee (mobile banking).
 *  Customer Y  loan C   8,000.00 @ 3%/month from 2026-11-01 — Nov 240.00 paid by staff2 (cash), Dec upcoming.
 *  Customer Z  loan D   closed; its 5.000 g item was released on 2026-12-10.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff1;

    private User $staff2;

    private Customer $x;

    private Customer $y;

    private Loan $a;

    private Loan $b;

    private Loan $c;

    protected function setUp(): void
    {
        parent::setUp();

        config(['loans.alerts.missed_period_threshold' => 2]);
        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->staff1 = User::factory()->create(['name' => 'Staff One']);
        $this->staff2 = User::factory()->create(['name' => 'Staff Two']);
        $this->x = Customer::factory()->create(['name' => 'Customer X']);
        $this->y = Customer::factory()->create(['name' => 'Customer Y']);

        $loans = app(LoanService::class);
        $activate = fn (Customer $customer, string $principal, string $rate, string $start) => $loans->activate(
            Loan::factory()->for($customer)->create(['principal' => $principal, 'outstanding_principal' => $principal, 'interest_rate' => $rate, 'start_date' => $start]),
            $this->staff1,
        );
        $this->a = $activate($this->x, '10000.00', '2.0000', '2026-10-01');
        $this->b = $activate($this->x, '5000.00', '2.0000', '2026-12-01');
        $this->c = $activate($this->y, '8000.00', '3.0000', '2026-11-01');

        $pay = function (User $staff, Loan $loan, string $type, string $amount, string $method) {
            $this->actingAs($staff); // the recording staff member (userstamps)

            return app(PaymentService::class)->post($loan->fresh(), ['type' => $type, 'amount' => $amount, 'method' => $method, 'payment_date' => '2026-12-15'], $staff);
        };
        $pay($this->staff1, $this->a, 'interest', '150', 'cash');
        app(PaymentReversalService::class)->reverse($pay($this->staff1, $this->a, 'interest', '50', 'cash'), $this->staff1, 'Duplicate');
        $pay($this->staff2, $this->b, 'principal', '1000', 'bank');
        $pay($this->staff2, $this->b, 'other_fee', '25', 'mobile_banking');
        $pay($this->staff2, $this->c, 'interest', '240', 'cash');

        CollateralItem::factory()->for($this->a)->create(['type' => 'gold', 'weight_grams' => '11.664', 'estimated_value' => '90000.00', 'received_at' => '2026-10-01 10:00:00']);
        CollateralItem::factory()->for($this->b)->create(['type' => 'diamond', 'karat' => null, 'weight_grams' => '2.500', 'estimated_value' => '150000.00', 'received_at' => '2026-12-01 10:00:00']);
        CollateralItem::factory()->for(Loan::factory()->status(LoanStatus::Closed))->create([
            'type' => 'gold', 'weight_grams' => '5.000', 'estimated_value' => '40000.00', 'received_at' => '2026-06-01 10:00:00',
            'status' => CollateralStatus::Released, 'released_at' => '2026-12-10 12:00:00', 'released_by' => $this->staff1->id,
        ]);

        $reporter = User::factory()->create();
        $reporter->givePermissionTo(Permission::ReportsView->value);
        Sanctum::actingAs($reporter);
    }

    // ── collection report ───────────────────────────────────────────────────────────────────

    public function test_collection_totals_by_type_and_method(): void
    {
        $this->getJson('/api/v1/reports/collections?paid_from=2026-12-01&paid_to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('meta.total', 4) // the reversed 50.00 was never collected
            ->assertJsonPath('totals', [
                'gross' => '1415.00',
                'payments' => 4,
                'interest' => '390.00',
                'principal' => '1000.00',
                'fees' => '25.00',
                'revenue' => '415.00',
                'by_type' => [
                    'interest' => ['payments' => 2, 'amount' => '390.00'],
                    'other_fee' => ['payments' => 1, 'amount' => '25.00'],
                    'principal' => ['payments' => 1, 'amount' => '1000.00'],
                ],
                'by_method' => [
                    'bank' => ['payments' => 1, 'amount' => '1000.00'],
                    'cash' => ['payments' => 2, 'amount' => '390.00'],
                    'mobile_banking' => ['payments' => 1, 'amount' => '25.00'],
                ],
            ]);
    }

    public function test_collection_filters_and_staff_column(): void
    {
        $gross = fn (string $query) => $this->getJson("/api/v1/reports/collections?{$query}")->assertOk()->json('totals.gross');

        $this->assertSame('150.00', $gross("staff={$this->staff1->id}"));
        $this->assertSame('1265.00', $gross("staff={$this->staff2->id}"));
        $this->assertSame('1175.00', $gross("customer={$this->x->customer_no}"));
        $this->assertSame('240.00', $gross("loan={$this->c->loan_no}"));
        $this->assertSame('390.00', $gross('method=cash'));
        $this->assertSame('1000.00', $gross('type=principal'));
        $this->assertSame('0.00', $gross('paid_to=2026-12-14'));

        $this->getJson("/api/v1/reports/collections?staff={$this->staff1->id}")
            ->assertJsonPath('data.0.staff', 'Staff One')
            ->assertJsonPath('data.0.customer.name', 'Customer X');
    }

    // ── due report ──────────────────────────────────────────────────────────────────────────

    public function test_due_report_totals_and_filters(): void
    {
        $this->getJson('/api/v1/reports/due')
            ->assertOk()
            ->assertJsonPath('meta.total', 5) // A Oct, Nov, Dec; B Dec; C Dec (C Nov is paid)
            ->assertJsonPath('totals', ['periods' => 5, 'loans' => 3, 'expected_interest' => '940.00', 'paid_interest' => '150.00', 'balance_due' => '790.00']);

        $this->getJson('/api/v1/reports/due?status=overdue')
            ->assertJsonPath('totals.balance_due', '250.00') // Oct 50 + Nov 200
            ->assertJsonPath('data.0.loan.customer.mobile', $this->x->mobile)
            ->assertJsonPath('data.0.loan_consecutive_missed', 2);

        $this->getJson('/api/v1/reports/due?min_missed=2')->assertJsonPath('totals.periods', 3)->assertJsonPath('totals.balance_due', '450.00');
        $this->getJson('/api/v1/reports/due?min_missed=3')->assertJsonPath('totals.periods', 0);
        $this->getJson("/api/v1/reports/due?customer={$this->y->customer_no}")->assertJsonPath('totals.balance_due', '240.00');
        $this->getJson("/api/v1/reports/due?loan={$this->b->loan_no}")->assertJsonPath('totals.expected_interest', '100.00');
        $this->getJson('/api/v1/reports/due?due_from=2026-12-01&due_to=2026-12-31')->assertJsonPath('totals.periods', 3);
        $this->getJson('/api/v1/reports/due?sort=balance&direction=desc')->assertJsonPath('data.0.unpaid_interest', '240.00');
    }

    // ── customer ledger ─────────────────────────────────────────────────────────────────────

    public function test_customer_ledger_is_chronological_with_a_running_balance(): void
    {
        $response = $this->getJson("/api/v1/reports/customer-ledger?customer={$this->x->customer_no}")->assertOk();

        $this->assertSame(
            [['2026-10-01', '10000.00', '0.00', '10000.00'], ['2026-10-31', '200.00', '0.00', '10200.00'], ['2026-11-30', '200.00', '0.00', '10400.00'], ['2026-12-01', '5000.00', '0.00', '15400.00']],
            collect($response->json('data'))->take(4)->map(fn ($row) => [$row['date'], $row['debit'], $row['credit'], $row['balance']])->all(),
        );
        $response
            ->assertJsonPath('data.0.loan_no', $this->a->loan_no)
            ->assertJsonPath('data.0.reference', $this->a->loan_no)
            // 4 loan postings (2 disbursements, Oct + Nov interest) + 6 payment postings (150, 50, its reversal, 1,000, fee charged, fee paid).
            ->assertJsonPath('totals', ['opening_balance' => '0.00', 'debit' => '15475.00', 'credit' => '1225.00', 'closing_balance' => '14250.00', 'entries' => 10]);

        // Unfiltered, the closing balance is the customer's ledger balance.
        $this->assertSame('14250.00', app(CustomerLedgerService::class)->balance($this->x));
    }

    public function test_a_date_range_brings_the_opening_balance_forward(): void
    {
        $this->getJson("/api/v1/customers/{$this->x->customer_no}/ledger?from=2026-12-01")
            ->assertOk()
            ->assertJsonPath('totals', ['opening_balance' => '10400.00', 'debit' => '5075.00', 'credit' => '1225.00', 'closing_balance' => '14250.00', 'entries' => 7])
            ->assertJsonPath('data.0.balance', '15400.00'); // 10,400 brought forward + 5,000 disbursed
    }

    public function test_one_loans_ledger_equals_its_exposure_in_the_outstanding_report(): void
    {
        $closing = $this->getJson("/api/v1/loans/{$this->a->loan_no}/ledger")->assertOk()->json('totals.closing_balance');
        $exposure = collect($this->getJson('/api/v1/reports/loan-outstanding')->json('data'))->firstWhere('loan_no', $this->a->loan_no)['exposure'];

        $this->assertSame('10250.00', $closing);   // 10,000 + 400 interest − 150 paid (the 50 and its reversal cancel out)
        $this->assertSame($closing, $exposure);    // outstanding 10,000 + due interest 250
    }

    // ── loan outstanding ────────────────────────────────────────────────────────────────────

    public function test_loan_outstanding_rows_totals_and_sorting(): void
    {
        $this->getJson('/api/v1/reports/loan-outstanding?sort=exposure&direction=desc')
            ->assertOk()
            ->assertJsonPath('totals', ['loans' => 3, 'principal' => '23000.00', 'outstanding_principal' => '22000.00', 'due_interest' => '250.00', 'exposure' => '22250.00'])
            ->assertJsonPath('data.0', [
                'loan_no' => $this->a->loan_no,
                'customer' => ['customer_no' => $this->x->customer_no, 'name' => 'Customer X', 'mobile' => $this->x->mobile],
                'principal' => '10000.00',
                'outstanding_principal' => '10000.00',
                'due_interest' => '250.00',
                'exposure' => '10250.00',
                'next_due_date' => '2026-12-31',
                'status' => 'overdue',
            ])
            ->assertJsonPath('data.1.loan_no', $this->c->loan_no)
            ->assertJsonPath('data.2.loan_no', $this->b->loan_no)
            ->assertJsonPath('data.2.outstanding_principal', '4000.00');

        $this->getJson('/api/v1/reports/loan-outstanding?status=closed')->assertJsonPath('totals.loans', 1)->assertJsonPath('totals.outstanding_principal', '50000.00');
        $this->getJson('/api/v1/reports/loan-outstanding?status=all')->assertJsonPath('totals.loans', 4);
        $this->getJson('/api/v1/reports/loan-outstanding?overdue=1')->assertJsonPath('totals.loans', 1);
    }

    // ── collateral ──────────────────────────────────────────────────────────────────────────

    public function test_collateral_totals_split_into_held_and_released(): void
    {
        $this->getJson('/api/v1/reports/collateral?sort=value&direction=desc')
            ->assertOk()
            ->assertJsonPath('totals', [
                'items' => 3,
                'weight_grams' => '19.164',
                'estimated_value' => '280000.00',
                'held' => ['items' => 2, 'weight_grams' => '14.164', 'estimated_value' => '240000.00'],
                'released' => ['items' => 1, 'weight_grams' => '5.000', 'estimated_value' => '40000.00'],
            ])
            ->assertJsonPath('data.0.type', 'diamond')
            ->assertJsonPath('data.0.loan.customer.name', 'Customer X');

        $this->getJson('/api/v1/reports/collateral?status=held')->assertJsonPath('totals.items', 2);
        $this->getJson('/api/v1/reports/collateral?released_from=2026-12-01')->assertJsonPath('totals.released.items', 1)->assertJsonPath('totals.items', 1);
        $this->getJson("/api/v1/reports/collateral?customer={$this->y->customer_no}")->assertJsonPath('totals.items', 0);
    }

    // ── stability, validation, authorization ────────────────────────────────────────────────

    public function test_pages_are_stable_and_totals_cover_the_whole_set(): void
    {
        $receipts = [];

        foreach (range(1, 4) as $page) {
            $response = $this->getJson("/api/v1/reports/collections?per_page=1&page={$page}")->assertOk();
            $this->assertSame('1415.00', $response->json('totals.gross')); // same on every page
            $receipts[] = $response->json('data.0.receipt_no');
        }

        $this->assertCount(4, array_unique($receipts));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson('/api/v1/reports/collections?sort=customer&method=cheque&paid_from=2026-12-10&paid_to=2026-12-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'method', 'paid_to']);
        $this->getJson('/api/v1/reports/due?status=late&min_missed=0')->assertUnprocessable()->assertJsonValidationErrors(['status', 'min_missed']);
        $this->getJson('/api/v1/reports/customer-ledger')->assertUnprocessable()->assertJsonValidationErrors('customer');
        $this->getJson('/api/v1/reports/customer-ledger?customer=CUS-000000-000000')->assertNotFound();
    }

    public function test_reports_require_reports_view(): void
    {
        $urls = [
            '/api/v1/reports/collections', '/api/v1/reports/due', "/api/v1/reports/customer-ledger?customer={$this->x->customer_no}",
            '/api/v1/reports/loan-outstanding', '/api/v1/reports/collateral',
            "/api/v1/customers/{$this->x->customer_no}/ledger", "/api/v1/loans/{$this->a->loan_no}/ledger",
        ];

        // Viewing loans, payments or customers is not enough.
        Sanctum::actingAs(tap(User::factory()->create())->givePermissionTo([Permission::LoansView->value, Permission::PaymentsView->value, Permission::CustomersView->value]));
        foreach ($urls as $url) {
            $this->getJson($url)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/reports/collections')->assertUnauthorized();
    }
}
