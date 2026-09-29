<?php

namespace Tests\Feature\Payments;

use App\Domain\Loan\LoanService;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Payment screens (Inertia): list, form lookups, posting, detail, receipt, reversal. The engine itself is
 * covered by PaymentEngineTest / PaymentReversalTest; these check what the screens receive and send.
 */
class PaymentPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-15 10:00:00');
        config(['shop.name' => 'Rupali Jewellers', 'shop.address' => '12 Tanti Bazar, Dhaka', 'shop.phone' => '01700-000000']);
        $this->cashier = $this->userWith(Permission::PaymentsView, Permission::PaymentsCreate, Permission::LoansView, Permission::CustomersView);
    }

    private function userWith(Permission ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (Permission $permission) => $permission->value, $permissions));

        return $user;
    }

    /** 10,000.00 at 2% a month from 2026-10-01: Oct + Nov interest overdue (400.00), Dec upcoming. */
    private function loan(): Loan
    {
        $loan = Loan::factory()->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-10-01']);

        return app(LoanService::class)->activate($loan, User::factory()->create());
    }

    private function postPayment(Loan $loan, array $overrides = [])
    {
        return $this->actingAs($this->cashier)->from('/payments/create')->post('/payments', [
            'loan' => $loan->loan_no, 'type' => 'interest', 'amount' => '300', 'method' => 'cash',
            'payment_date' => '2026-12-15', 'idempotency_key' => 'form-'.uniqid(), ...$overrides,
        ]);
    }

    // ── form ────────────────────────────────────────────────────────────────────────────────

    public function test_form_offers_types_methods_and_a_fresh_idempotency_key(): void
    {
        $this->actingAs($this->cashier)->get('/payments/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments/create')
                ->where('types', ['interest', 'principal', 'principal_and_interest', 'other_fee']) // adjustment is not offered
                ->where('methods', ['cash', 'bank', 'mobile_banking', 'card', 'other'])
                ->where('today', '2026-12-15')
                ->where('idempotencyKey', fn (string $key) => strlen($key) === 36)
                ->where('loanInfo', null));
    }

    public function test_lookups_return_customers_their_open_loans_and_authoritative_figures(): void
    {
        $loan = $this->loan();
        $customer = $loan->customer;
        Loan::factory()->for($customer)->status(LoanStatus::Closed)->create();

        $this->actingAs($this->cashier)->get('/payments/create?customer_q='.urlencode($customer->name))
            ->assertInertia(fn (Assert $page) => $page->where('customerResults.0.customer_no', $customer->customer_no));

        $this->actingAs($this->cashier)->get("/payments/create?customer={$customer->customer_no}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('customer.customer_no', $customer->customer_no)
                ->has('loans', 1) // the closed loan is not offered
                ->where('loans.0.loan_no', $loan->loan_no));

        $this->actingAs($this->cashier)->get("/payments/create?loan={$loan->loan_no}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('customer.customer_no', $customer->customer_no)
                ->where('loanInfo.loan.loan_no', $loan->loan_no)
                ->where('loanInfo.loan.status', 'overdue')
                ->where('loanInfo.accepts_payments', true)
                ->where('loanInfo.outstanding_principal', '10000.00')
                ->where('loanInfo.interest_due', '400.00')
                ->where('loanInfo.interest_payable', '600.00') // includes the current period
                ->where('loanInfo.overdue_periods', 2)
                ->where('loanInfo.next_due_date', '2026-12-31'));
    }

    public function test_loan_figures_need_permission_to_view_the_loan(): void
    {
        $loan = $this->loan();

        $this->actingAs($this->userWith(Permission::PaymentsCreate))->get("/payments/create?loan={$loan->loan_no}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('loanInfo', null));

        $this->actingAs($this->userWith(Permission::PaymentsView))->get('/payments/create')->assertForbidden();
    }

    // ── posting ─────────────────────────────────────────────────────────────────────────────

    public function test_posting_redirects_to_the_server_issued_receipt(): void
    {
        $loan = $this->loan();

        $this->postPayment($loan)
            ->assertRedirect('/payments/RCPT-202612-000001/receipt?new=1')
            ->assertSessionHas('success', 'Payment RCPT-202612-000001 recorded.');

        $this->assertSame('300.00', Payment::sole()->amount);
    }

    public function test_server_validation_messages_return_to_the_form(): void
    {
        $loan = $this->loan();

        $this->postPayment($loan, ['amount' => '600.01'])
            ->assertRedirect('/payments/create')
            ->assertSessionHasErrors(['amount' => 'The amount exceeds the interest payable (600.00).']);

        $this->postPayment($loan, ['amount' => '', 'payment_date' => '2026-12-16'])->assertSessionHasErrors(['amount', 'payment_date']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_double_submitted_form_posts_once(): void
    {
        $loan = $this->loan();

        $this->postPayment($loan, ['idempotency_key' => 'same-form'])->assertRedirect('/payments/RCPT-202612-000001/receipt?new=1');
        $this->postPayment($loan, ['idempotency_key' => 'same-form'])->assertRedirect('/payments/RCPT-202612-000001/receipt?new=1');

        $this->assertDatabaseCount('payments', 1);
    }

    // ── detail and receipt ──────────────────────────────────────────────────────────────────

    public function test_detail_shows_the_allocation_and_balances_after_the_payment(): void
    {
        $loan = $this->loan();
        $this->postPayment($loan, ['type' => 'principal_and_interest', 'amount' => '1000']);

        $this->actingAs($this->cashier)->get('/payments/RCPT-202612-000001')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments/show')
                ->where('payment.allocation.interest', '600.00')
                ->where('payment.allocation.principal', '400.00')
                ->has('payment.allocation.periods', 3)
                ->where('payment.can_reverse', false) // the cashier may not reverse
                ->where('balances.principal_after', '9600.00')
                ->where('balances.customer_balance_after', '9400.00')); // 10,000 disbursed + 400 interest due − 1,000 paid
    }

    public function test_receipt_carries_shop_details_and_balances_as_of_the_payment(): void
    {
        $loan = $this->loan();
        $this->postPayment($loan, ['type' => 'principal', 'amount' => '1000']);
        $this->postPayment($loan, ['type' => 'principal', 'amount' => '2000']);

        $this->actingAs($this->cashier)->get('/payments/RCPT-202612-000001/receipt?print=1&new=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments/receipt')
                ->where('shop.name', 'Rupali Jewellers')
                ->where('shop.address', '12 Tanti Bazar, Dhaka')
                ->where('shop.phone', '01700-000000')
                ->where('payment.receipt_no', 'RCPT-202612-000001')
                ->where('payment.customer.name', $loan->customer->name)
                ->where('payment.loan.loan_no', $loan->loan_no)
                ->where('payment.type', 'principal')
                ->where('payment.method', 'cash')
                ->where('payment.allocation.principal', '1000.00')
                ->where('balances.principal_after', '9000.00')        // not today's 7,000.00: as of this payment
                ->where('balances.customer_balance_after', '9400.00')
                ->where('cashier', $this->cashier->name)
                ->where('autoPrint', true)
                ->where('justCompleted', true));

        $this->actingAs($this->cashier)->get('/payments/RCPT-202612-000002/receipt')
            ->assertInertia(fn (Assert $page) => $page->where('balances.principal_after', '7000.00')->where('autoPrint', false));
    }

    public function test_a_reversed_payment_still_has_its_receipt(): void
    {
        $loan = $this->loan();
        $this->postPayment($loan);
        $supervisor = $this->userWith(Permission::PaymentsView, Permission::PaymentsReverse);

        $this->actingAs($supervisor)->from('/payments/RCPT-202612-000001')->post('/payments/RCPT-202612-000001/reverse', ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($supervisor)->from('/payments/RCPT-202612-000001')->post('/payments/RCPT-202612-000001/reverse', ['reason' => 'Wrong loan'])
            ->assertRedirect('/payments/RCPT-202612-000001')
            ->assertSessionHas('success', 'Payment RCPT-202612-000001 reversed.');

        $this->assertSame(PaymentStatus::Reversed, Payment::sole()->status);
        $this->actingAs($supervisor)->get('/payments/RCPT-202612-000001/receipt')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payment.status', 'reversed')->where('payment.reversal_reason', 'Wrong loan'));
    }

    // ── list and permissions ────────────────────────────────────────────────────────────────

    public function test_list_is_filtered_and_paginated(): void
    {
        $loan = $this->loan();
        $this->postPayment($loan, ['reference' => 'BKASH-42']);
        $this->postPayment($loan, ['type' => 'principal', 'amount' => '500', 'method' => 'bank']);

        $this->actingAs($this->cashier)->get('/payments?method=bank')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments/index')
                ->where('filters.method', 'bank')
                ->has('payments.data', 1)
                ->where('payments.data.0.type', 'principal')
                ->has('payments.meta'));

        $this->actingAs($this->cashier)->get('/payments?q=bkash')->assertInertia(fn (Assert $page) => $page->has('payments.data', 1));
        $this->actingAs($this->cashier)->from('/payments')->get('/payments?status=void')->assertRedirect('/payments')->assertSessionHasErrors('status');
    }

    public function test_screens_require_their_permissions(): void
    {
        $loan = $this->loan();
        $this->postPayment($loan);
        $nobody = User::factory()->create();

        foreach (['/payments', '/payments/create', '/payments/RCPT-202612-000001', '/payments/RCPT-202612-000001/receipt'] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }

        $this->actingAs($this->cashier)->post('/payments/RCPT-202612-000001/reverse', ['reason' => 'Try'])->assertForbidden();
        $this->actingAs($this->userWith(Permission::PaymentsCreate))->post('/payments', ['loan' => $loan->loan_no, 'type' => 'interest', 'amount' => '1', 'method' => 'cash', 'payment_date' => '2026-12-15'])
            ->assertForbidden(); // cannot see the loan
    }
}
