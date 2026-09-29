<?php

namespace Tests\Feature\Customers;

use App\Domain\Loan\LoanService;
use App\Domain\Payment\PaymentReversalService;
use App\Domain\Payment\PaymentService;
use App\Domain\Reporting\Export\ExportDatasets;
use App\Enums\Permission;
use App\Models\CollateralItem;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Customer screen → Payments & ledger: the customer's full payment history (reversed payments included)
 * and ledger statement across all loans and years, with shared filters and Excel / PDF exports.
 *
 * Scenario: one customer, loan A (10,000.00 at 2% from 2025-11-01) and loan B (5,000.00 at 2% from
 * 2026-01-01); interest run to 2026-03-05.
 */
class CustomerHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Loan $a;

    private Loan $b;

    protected function setUp(): void
    {
        parent::setUp();

        $cashier = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->a = $this->loan('2025-11-01', '10000.00', $cashier);
        $this->pay($this->a, '2025-12-10', '200.00', $cashier); // Nov interest
        $this->b = $this->loan('2026-01-01', '5000.00', $cashier);
        $this->pay($this->a, '2026-02-10', '400.00', $cashier); // Dec + Jan interest
        $reversed = $this->pay($this->b, '2026-02-10', '100.00', $cashier);
        app(PaymentReversalService::class)->reverse($reversed, $cashier, 'Entered on the wrong loan');

        $this->runOn('2026-03-05');
    }

    private function loan(string $start, string $principal, User $cashier): Loan
    {
        $this->runOn($start);
        $loan = Loan::factory()->for($this->customer)->create(['principal' => $principal, 'outstanding_principal' => $principal, 'interest_rate' => '2.0000', 'start_date' => $start]);
        CollateralItem::factory()->for($loan)->create();

        return app(LoanService::class)->activate($loan, $cashier);
    }

    private function runOn(string $date): void
    {
        Carbon::setTestNow("{$date} 00:05:00");
        $this->artisan('loans:process-interest')->assertSuccessful();
    }

    private function pay(Loan $loan, string $date, string $amount, User $cashier): Payment
    {
        $this->runOn($date);

        return app(PaymentService::class)->post($loan->fresh(), ['type' => 'interest', 'amount' => $amount, 'method' => 'cash', 'payment_date' => $date], $cashier);
    }

    private function user(Permission ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (Permission $p) => $p->value, [Permission::CustomersView, ...$permissions]));

        return $user;
    }

    private function page(User $user, array $query = [])
    {
        return $this->actingAs($user)->get(route('customers.show', [$this->customer->customer_no, ...$query]));
    }

    public function test_full_payment_and_ledger_history_across_loans(): void
    {
        $this->page($this->user(Permission::PaymentsView, Permission::ReportsView))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('customers/show')
                ->where('historyLoans', [$this->a->loan_no, $this->b->loan_no])
                ->where('firstYear', 2025)
                ->where('history', ['from' => '', 'to' => '', 'loan' => ''])
                ->has('payments.data', 3)
                ->where('payments.data.0.status', 'reversed') // newest first; reversed payments stay listed
                ->where('payments.data.0.loan.loan_no', $this->b->loan_no)
                ->where('payments.data.2.amount', '200.00')
                ->where('ledger.totals.opening_balance', '0.00')
                // Disbursed 15,000.00 + interest charged (A: Nov–Feb 800.00, B: Jan–Feb 200.00) + reversal 100.00
                ->where('ledger.totals.debit', '16100.00')
                ->where('ledger.totals.credit', '700.00')
                ->where('ledger.totals.closing_balance', '15400.00')
                ->where('ledger.entries.data.0.entry_type', 'loan_disbursed')
                ->where('ledger.entries.meta.total', 12));
    }

    public function test_a_year_filter_brings_the_previous_balance_forward(): void
    {
        $user = $this->user(Permission::PaymentsView, Permission::ReportsView);

        $this->page($user, ['from' => '2026-01-01', 'to' => '2026-12-31'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.from', '2026-01-01')
                ->has('payments.data', 2)
                // 2025: 10,000.00 + Nov & Dec interest 400.00 − 200.00 paid
                ->where('ledger.totals.opening_balance', '10200.00')
                ->where('ledger.totals.closing_balance', '15400.00'));

        $this->page($user, ['loan' => $this->a->loan_no])
            ->assertInertia(fn (Assert $page) => $page
                ->has('payments.data', 2)
                ->where('ledger.totals.closing_balance', '10200.00')); // 10,000.00 + 800.00 − 600.00
    }

    public function test_filters_are_validated_and_limited_to_the_customers_loans(): void
    {
        $other = Loan::factory()->create();
        $user = $this->user(Permission::PaymentsView);

        $this->page($user, ['loan' => $other->loan_no])->assertSessionHasErrors('loan');
        $this->page($user, ['from' => '2026-03-01', 'to' => '2026-01-01'])->assertSessionHasErrors('to');
    }

    public function test_each_part_needs_its_own_permission(): void
    {
        $this->page($this->user())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payments', null)->where('ledger', null)->where('historyLoans', []));

        $this->page($this->user(Permission::PaymentsView))
            ->assertInertia(fn (Assert $page) => $page->has('payments.data', 3)->where('ledger', null));

        $this->page($this->user(Permission::ReportsView))
            ->assertInertia(fn (Assert $page) => $page->where('payments', null)->has('ledger.entries.data'));
    }

    public function test_payments_and_ledger_page_independently(): void
    {
        $this->page($this->user(Permission::PaymentsView, Permission::ReportsView), ['ledger_page' => 2])
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.meta.current_page', 1)
                ->where('ledger.entries.meta.current_page', 2) // the ledger's own page parameter only
                ->where('ledger.entries.meta.per_page', 25));
    }

    public function test_interest_month_by_month_shows_paid_and_unpaid(): void
    {
        $this->page($this->user(Permission::ReportsView))
            ->assertInertia(fn (Assert $page) => $page
                ->has('interest.months.data', 8) // A: Nov 2025 … Mar 2026, B: Jan … Mar 2026
                ->where('interest.months.data.0', [
                    'loan_no' => $this->a->loan_no, 'month' => '2025-11', 'period_start' => '2025-11-01', 'period_end' => '2025-11-30',
                    'due_date' => '2025-11-30', 'principal' => '10000.00', 'rate' => '2% monthly', 'expected_interest' => '200.00',
                    'paid_interest' => '200.00', 'waived_interest' => '0.00', 'unpaid_interest' => '0.00', 'status' => 'paid',
                    'paid_on' => '2025-12-10', 'receipts' => Payment::query()->orderBy('id')->value('receipt_no'),
                ])
                ->where('interest.months.data.3.month', '2026-02')
                ->where('interest.months.data.3.unpaid_interest', '200.00')
                ->where('interest.months.data.3.status', 'overdue')
                ->where('interest.months.data.4.status', 'upcoming') // March: running, nothing owed yet
                ->where('interest.months.data.4.unpaid_interest', '0.00')
                // B's January: its payment was reversed, so it is unpaid and lists no payment.
                ->where('interest.months.data.5.loan_no', $this->b->loan_no)
                ->where('interest.months.data.5.paid_on', '')
                ->where('interest.months.data.5.unpaid_interest', '100.00')
                ->where('interest.totals', ['months' => 8, 'charged' => '1000.00', 'paid' => '600.00', 'waived' => '0.00', 'unpaid' => '400.00', 'overdue_months' => 3]));

        $this->page($this->user(Permission::ReportsView), ['loan' => $this->b->loan_no, 'from' => '2026-02-01'])
            ->assertInertia(fn (Assert $page) => $page->has('interest.months.data', 2)->where('interest.totals.unpaid', '100.00'));

        $this->page($this->user(Permission::PaymentsView))->assertInertia(fn (Assert $page) => $page->where('interest', null));
    }

    public function test_interest_statement_exports_and_api(): void
    {
        $user = $this->user(Permission::ReportsView, Permission::ReportsExport);
        $query = ['report' => 'customer-interest', 'customer' => $this->customer->customer_no];

        $this->actingAs($user)->get(route('reports.export', [...$query, 'format' => 'xlsx']))->assertOk()->assertDownload();
        $pdf = $this->actingAs($user)->get(route('reports.export', [...$query, 'format' => 'pdf']));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $dataset = app(ExportDatasets::class)->customerInterest(
            ['customer' => $this->customer, 'loan' => null, 'loan_id' => null, 'from' => null, 'to' => null],
        );
        $this->assertCount(8, $dataset->rows);
        $this->assertSame('Overdue', $dataset->rows[3]['status']);
        $this->assertSame(['expected_interest' => '1000.00', 'paid_interest' => '600.00', 'waived_interest' => '0.00', 'unpaid_interest' => '400.00'], $dataset->totals[0]['values']);

        $this->actingAs($user)->getJson('/api/v1/reports/customer-interest?customer='.$this->customer->customer_no)
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonPath('totals.unpaid', '400.00');

        $this->actingAs($this->user(Permission::ReportsView))->get(route('reports.export', $query))->assertForbidden();
    }

    public function test_history_exports_to_excel_and_pdf(): void
    {
        $user = $this->user(Permission::PaymentsView, Permission::ReportsView, Permission::ReportsExport);
        $customerNo = $this->customer->customer_no;

        $this->actingAs($user)->get(route('payments.export', ['customer' => $customerNo, 'paid_from' => '2026-01-01', 'format' => 'xlsx']))
            ->assertOk()
            ->assertDownload();

        $pdf = $this->actingAs($user)->get(route('reports.export', ['report' => 'customer-ledger', 'customer' => $customerNo, 'from' => '2026-01-01', 'format' => 'pdf']));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        // Exports stay behind reports.export.
        $this->actingAs($this->user(Permission::PaymentsView, Permission::ReportsView))
            ->get(route('payments.export', ['customer' => $customerNo]))
            ->assertForbidden();
    }
}
