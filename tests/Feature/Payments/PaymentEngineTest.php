<?php

namespace Tests\Feature\Payments;

use App\Domain\Customer\CustomerSummary;
use App\Domain\Ledger\CustomerLedgerService;
use App\Domain\Loan\LoanService;
use App\Enums\InterestPeriodStatus;
use App\Enums\LedgerEntryType;
use App\Enums\LoanEventType;
use App\Enums\LoanStatus;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\InterestPeriod;
use App\Models\LedgerEntry;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The payment engine end to end. Scenario: a 10,000.00 loan at 2% a month started 2026-10-01 and
 * activated on 2026-12-15 (today): Oct and Nov interest (200.00 each) are overdue, Dec is upcoming.
 */
class PaymentEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->cashier = User::factory()->create();
        $this->cashier->givePermissionTo([Permission::PaymentsCreate->value, Permission::PaymentsView->value, Permission::LoansView->value]);
        Sanctum::actingAs($this->cashier);
    }

    private function loan(): Loan
    {
        $loan = Loan::factory()->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-10-01']);

        return app(LoanService::class)->activate($loan, User::factory()->create());
    }

    private function pay(Loan $loan, string $type, string $amount, array $extra = [])
    {
        return $this->postJson('/api/v1/payments', ['loan' => $loan->loan_no, 'type' => $type, 'amount' => $amount, 'method' => 'cash', 'payment_date' => '2026-12-15', 'idempotency_key' => (string) Str::uuid(), ...$extra]);
    }

    /**
     * @return list<array{0: string, 1: string}> [paid_interest, status] per period, oldest first
     */
    private function periods(Loan $loan): array
    {
        return $loan->interestPeriods()->get()->map(fn (InterestPeriod $p) => [$p->paid_interest, $p->status->value])->all();
    }

    private function balance(Loan $loan): string
    {
        return app(CustomerLedgerService::class)->balance($loan->customer_id);
    }

    // ── the scenario itself ─────────────────────────────────────────────────────────────────

    public function test_activation_posts_the_disbursement_and_the_interest_due_to_the_ledger(): void
    {
        $loan = $this->loan();

        $this->assertSame(LoanStatus::Overdue, $loan->fresh()->status);
        $this->assertSame([['0.00', 'overdue'], ['0.00', 'overdue'], ['0.00', 'upcoming']], $this->periods($loan));
        $this->assertSame(
            [['loan_disbursed', '10000.00', '0.00', '10000.00'], ['interest_charged', '200.00', '0.00', '10200.00'], ['interest_charged', '200.00', '0.00', '10400.00']],
            LedgerEntry::orderBy('id')->get()->map(fn ($e) => [$e->entry_type->value, $e->debit, $e->credit, $e->balance_after])->all(),
        );
    }

    // ── interest only ───────────────────────────────────────────────────────────────────────

    public function test_interest_payment_settles_the_oldest_periods_first(): void
    {
        $loan = $this->loan();

        $this->pay($loan, 'interest', '300')
            ->assertCreated()
            ->assertJsonPath('data.receipt_no', 'RCPT-202612-000001')
            ->assertJsonPath('data.type', 'interest')
            ->assertJsonPath('data.amount', '300.00')
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.allocation.interest', '300.00')
            ->assertJsonPath('data.allocation.principal', '0.00')
            ->assertJsonPath('data.allocation.periods.0.period_start', '2026-10-01')
            ->assertJsonPath('data.allocation.periods.0.interest', '200.00')
            ->assertJsonPath('data.allocation.periods.1.interest', '100.00')
            ->assertJsonMissingPath('data.id');

        // Nov is partly paid but past due, so still overdue (missed-period rule); the loan stays overdue.
        $this->assertSame([['200.00', 'paid'], ['100.00', 'overdue'], ['0.00', 'upcoming']], $this->periods($loan));
        $this->assertSame('10000.00', $loan->fresh()->outstanding_principal);
        $this->assertSame(LoanStatus::Overdue, $loan->fresh()->status);
        $this->assertNotNull($loan->interestPeriods()->first()->paid_at);

        $payment = Payment::sole();
        $this->assertSame($this->cashier->id, $payment->created_by);
        $this->assertSame($loan->customer_id, $payment->customer_id);
        $this->assertSame(2, $payment->allocations()->count());
        $this->assertSame('10100.00', $this->balance($loan));

        $event = $loan->events()->where('event_type', LoanEventType::PaymentPosted)->sole();
        $this->assertSame($this->cashier->id, $event->actor_id);
        $this->assertSame('RCPT-202612-000001', $event->payload['receipt_no']);
        $this->assertSame('300.00', $event->payload['interest']);
    }

    public function test_partial_interest_payment_on_the_current_period_is_partially_paid(): void
    {
        $loan = $this->loan();
        $this->pay($loan, 'interest', '400')->assertCreated();   // Oct + Nov
        $this->pay($loan, 'interest', '50')->assertCreated();    // part of Dec, before its due date

        $this->assertSame([['200.00', 'paid'], ['200.00', 'paid'], ['50.00', 'partially_paid']], $this->periods($loan));
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status); // nothing overdue any more
        $this->assertSame('RCPT-202612-000002', Payment::latest('id')->value('receipt_no'));
    }

    public function test_interest_above_what_is_payable_is_refused_and_nothing_is_written(): void
    {
        $loan = $this->loan();
        $ledgerBefore = LedgerEntry::count();

        $this->pay($loan, 'interest', '600.01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'exceeds the interest payable (600.00)']);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertSame($ledgerBefore, LedgerEntry::count());
        $this->pay($loan, 'interest', '600')->assertCreated()->assertJsonPath('data.receipt_no', 'RCPT-202612-000001'); // number not consumed
    }

    // ── principal only ──────────────────────────────────────────────────────────────────────

    public function test_principal_payment_reduces_outstanding_and_later_interest(): void
    {
        $loan = $this->loan();

        $this->pay($loan, 'principal', '2500')->assertCreated()->assertJsonPath('data.allocation.principal', '2500.00');

        $this->assertSame('7500.00', $loan->fresh()->outstanding_principal);
        $this->assertSame([['0.00', 'overdue'], ['0.00', 'overdue'], ['0.00', 'upcoming']], $this->periods($loan)); // interest untouched
        $this->assertSame('7900.00', $this->balance($loan));

        // Reducing balance: January's interest is charged on 7,500.00.
        $this->artisan('loans:process-interest', ['--date' => '2027-01-01'])->assertSuccessful();
        $this->assertSame('150.00', $loan->interestPeriods()->whereDate('period_start', '2027-01-01')->value('expected_interest'));
    }

    public function test_principal_above_outstanding_is_refused(): void
    {
        $loan = $this->loan();

        $this->pay($loan, 'principal', '10000.01')->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'outstanding principal (10000.00)']);
        $this->assertSame('10000.00', $loan->fresh()->outstanding_principal);
    }

    // ── combined ────────────────────────────────────────────────────────────────────────────

    public function test_combined_payment_clears_interest_then_reduces_principal(): void
    {
        $loan = $this->loan();

        $this->pay($loan, 'principal_and_interest', '1000')
            ->assertCreated()
            ->assertJsonPath('data.allocation.interest', '600.00')
            ->assertJsonPath('data.allocation.principal', '400.00');

        $this->assertSame([['200.00', 'paid'], ['200.00', 'paid'], ['200.00', 'paid']], $this->periods($loan));
        $this->assertSame('9600.00', $loan->fresh()->outstanding_principal);
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
        $this->assertSame(4, Payment::sole()->allocations()->count()); // three periods + principal
    }

    public function test_full_settlement_closes_with_a_balanced_statement(): void
    {
        $loan = $this->loan();
        $this->pay($loan, 'principal_and_interest', '10600')->assertCreated();

        app(LoanService::class)->close($loan->fresh(), $this->cashier);

        // Disbursed 10,000 + Oct + Nov + Dec (collected in advance, charged on closure) − 10,600 paid = 0.
        $this->assertSame('0.00', $this->balance($loan));
        $this->assertSame(3, LedgerEntry::where('entry_type', LedgerEntryType::InterestCharged)->count());
        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);
    }

    public function test_combined_payment_above_interest_plus_principal_is_refused(): void
    {
        $loan = $this->loan();

        $this->pay($loan, 'principal_and_interest', '10600.01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'interest payable plus outstanding principal (10600.00)']);
    }

    // ── fee / adjustment ────────────────────────────────────────────────────────────────────

    public function test_fee_is_income_that_leaves_interest_principal_and_balance_alone(): void
    {
        $loan = $this->loan();

        $this->pay($loan, 'other_fee', '75.50')->assertCreated()->assertJsonPath('data.allocation.fee', '75.50');

        $this->assertSame('10000.00', $loan->fresh()->outstanding_principal);
        $this->assertSame([['0.00', 'overdue'], ['0.00', 'overdue'], ['0.00', 'upcoming']], $this->periods($loan));
        $this->assertSame('10400.00', $this->balance($loan)); // fee charged and paid: net zero
        $this->assertSame(['fee_charged', 'payment_received'], LedgerEntry::whereNotNull('payment_id')->orderBy('id')->pluck('entry_type')->map->value->all());
    }

    public function test_adjustments_are_refused(): void
    {
        $this->pay($this->loan(), 'adjustment', '10')->assertUnprocessable()->assertJsonValidationErrors(['type' => 'Adjustments are not accepted yet']);
    }

    // ── validation ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayments(): array
    {
        return [
            'zero amount' => [['amount' => '0'], 'amount'],
            'negative amount' => [['amount' => '-10'], 'amount'],
            'three decimals' => [['amount' => '10.001'], 'amount'],
            'unknown type' => [['type' => 'tip'], 'type'],
            'unconfigured method' => [['method' => 'cheque'], 'method'],
            'bad date format' => [['payment_date' => '15/12/2026'], 'payment_date'],
            'future date' => [['payment_date' => '2026-12-16'], 'payment_date'],
            'before the current period' => [['payment_date' => '2026-11-30'], 'payment_date'],
            'unknown loan' => [['loan' => 'LN-000000-000000'], 'loan'],
        ];
    }

    #[DataProvider('invalidPayments')]
    public function test_invalid_payments_are_rejected(array $overrides, string $field): void
    {
        $this->pay($this->loan(), 'interest', '100', $overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_backdating_is_allowed_within_the_current_period(): void
    {
        $this->pay($this->loan(), 'interest', '100', ['payment_date' => '2026-12-01'])->assertCreated()->assertJsonPath('data.payment_date', '2026-12-01');
    }

    public function test_customer_must_own_the_loan(): void
    {
        $loan = $this->loan();

        $this->pay($loan, 'interest', '100', ['customer' => Customer::factory()->create()->customer_no])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer' => 'does not belong to customer']);
        $this->pay($loan, 'interest', '100', ['customer' => $loan->customer->customer_no])->assertCreated();
    }

    #[DataProvider('notOpen')]
    public function test_payments_only_on_active_or_overdue_loans(LoanStatus $status): void
    {
        $loan = Loan::factory()->status($status)->create();

        $this->pay($loan, 'principal', '100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['loan' => "this loan is {$status->value}"]);
    }

    public static function notOpen(): array
    {
        return ['draft' => [LoanStatus::Draft], 'closed' => [LoanStatus::Closed], 'cancelled' => [LoanStatus::Cancelled]];
    }

    // ── idempotency ─────────────────────────────────────────────────────────────────────────

    public function test_a_retried_request_posts_the_payment_once(): void
    {
        $loan = $this->loan();

        $first = $this->pay($loan, 'interest', '200', ['idempotency_key' => 'till-7-000123'])->assertCreated()->json('data.receipt_no');
        $this->pay($loan, 'interest', '200', ['idempotency_key' => 'till-7-000123'])->assertOk()->assertJsonPath('data.receipt_no', $first);
        $this->withHeader('Idempotency-Key', 'till-7-000123')->pay($loan, 'interest', '200', ['idempotency_key' => null])->assertOk()->assertJsonPath('data.receipt_no', $first);

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame([['200.00', 'paid'], ['0.00', 'overdue'], ['0.00', 'upcoming']], $this->periods($loan)); // applied once
    }

    public function test_reusing_a_key_for_a_different_payment_is_a_conflict(): void
    {
        $loan = $this->loan();
        $this->pay($loan, 'interest', '200', ['idempotency_key' => 'key-0001'])->assertCreated();

        $this->pay($loan, 'interest', '250', ['idempotency_key' => 'key-0001'])->assertConflict();
        $this->assertDatabaseCount('payments', 1);
    }

    // ── atomicity ───────────────────────────────────────────────────────────────────────────

    public function test_a_failure_part_way_rolls_back_everything(): void
    {
        $loan = $this->loan();
        $before = ['ledger' => LedgerEntry::count(), 'periods' => $this->periods($loan), 'outstanding' => $loan->fresh()->outstanding_principal, 'events' => $loan->events()->count()];

        // The ledger fails after the payment, its allocations, the periods and the principal were written.
        $this->app->instance(CustomerLedgerService::class, new class extends CustomerLedgerService
        {
            public function post($customer, LedgerEntryType $type, string $amount, $date, string $description, string $reference, array $links = []): LedgerEntry
            {
                if ($type === LedgerEntryType::PaymentReceived) {
                    throw new RuntimeException('Ledger unavailable');
                }

                return parent::post($customer, $type, $amount, $date, $description, $reference, $links);
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->pay($loan, 'principal_and_interest', '1000');
            $this->fail('The payment should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Ledger unavailable', $e->getMessage());
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertSame($before['periods'], $this->periods($loan));
        $this->assertSame($before['outstanding'], $loan->fresh()->outstanding_principal);
        $this->assertSame($before['ledger'], LedgerEntry::count());
        $this->assertSame($before['events'], $loan->events()->count());
        $this->assertSame(0, (int) DB::table('document_sequences')->where('prefix', 'RCPT')->value('last_number'));
    }

    public function test_sequential_payments_see_each_others_balances(): void
    {
        $loan = $this->loan();

        $this->pay($loan, 'principal', '6000')->assertCreated();
        $this->pay($loan, 'principal', '4000.01')->assertUnprocessable(); // only 4,000 left
        $this->pay($loan, 'principal', '4000')->assertCreated();

        $this->assertSame('0.00', $loan->fresh()->outstanding_principal);
    }

    // ── derived customer data ───────────────────────────────────────────────────────────────

    public function test_customer_summary_reflects_the_payment(): void
    {
        $loan = $this->loan();
        $summary = fn () => app(CustomerSummary::class)->apply(Customer::query()->whereKey($loan->customer_id))->first();

        $this->assertSame('400.00', $summary()->total_interest_due);
        $this->assertSame(2, $summary()->consecutive_missed);

        $this->pay($loan, 'interest', '400')->assertCreated();

        $this->assertSame('0.00', $summary()->total_interest_due);
        $this->assertNull($summary()->consecutive_missed);
        $this->assertSame('400.00', $summary()->last_payment_amount);
        $this->assertSame('2026-12-31', $loan->fresh()->next_due_date->toDateString());
    }

    // ── ledger lifecycle ────────────────────────────────────────────────────────────────────

    public function test_the_daily_run_charges_each_period_once(): void
    {
        $loan = $this->loan();

        foreach (range(1, 3) as $ignored) {
            $this->artisan('loans:process-interest', ['--date' => '2027-01-05'])->assertSuccessful();
        }

        // Oct, Nov, Dec (due 2026-12-31) — Jan is not due yet.
        $this->assertSame(3, LedgerEntry::where('entry_type', LedgerEntryType::InterestCharged)->count());
        $this->assertSame('10600.00', $this->balance($loan));
    }

    public function test_cancelling_an_activated_loan_clears_its_statement(): void
    {
        $loan = $this->loan();

        app(LoanService::class)->cancel($loan, $this->cashier, 'Entered on the wrong customer');

        $this->assertSame('0.00', $this->balance($loan));
        $this->assertSame('10400.00', LedgerEntry::where('entry_type', LedgerEntryType::LoanCancelled)->sole()->credit);
    }

    // ── authorization & listing ─────────────────────────────────────────────────────────────

    public function test_posting_requires_payments_create_and_access_to_the_loan(): void
    {
        $loan = $this->loan();

        Sanctum::actingAs(tap(User::factory()->create())->givePermissionTo([Permission::PaymentsView->value, Permission::LoansView->value]));
        $this->pay($loan, 'interest', '100')->assertForbidden();

        Sanctum::actingAs(tap(User::factory()->create())->givePermissionTo(Permission::PaymentsCreate->value));
        $this->pay($loan, 'interest', '100')->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payments_are_listed_shown_and_filtered(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'interest', '200', ['reference' => 'BKASH-99'])->json('data.receipt_no');
        $this->pay($loan, 'principal', '1000');

        $this->getJson("/api/v1/payments/{$receipt}")
            ->assertOk()
            ->assertJsonPath('data.loan.loan_no', $loan->loan_no)
            ->assertJsonPath('data.customer.customer_no', $loan->customer->customer_no)
            ->assertJsonPath('data.allocation.periods.0.due_date', '2026-10-31');

        $this->getJson('/api/v1/payments')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/payments?type=principal')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/payments?q=bkash')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.receipt_no', $receipt);
        $this->getJson("/api/v1/loans/{$loan->loan_no}/payments")->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/payments?paid_from=2026-12-16')->assertJsonPath('meta.total', 0);

        $this->cashier->givePermissionTo(Permission::CustomersView->value);
        $this->getJson("/api/v1/customers/{$loan->customer->customer_no}/payments")->assertJsonPath('meta.total', 2);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/payments')->assertForbidden();
    }

    public function test_posted_payments_cannot_be_edited_or_deleted_through_the_api(): void
    {
        $receipt = $this->pay($this->loan(), 'interest', '200')->json('data.receipt_no');

        $this->patchJson("/api/v1/payments/{$receipt}", ['amount' => '1'])->assertMethodNotAllowed();
        $this->deleteJson("/api/v1/payments/{$receipt}")->assertMethodNotAllowed();
        $this->assertSame('200.00', Payment::sole()->amount);
    }

    public function test_period_status_after_payment_is_stored_not_just_derived(): void
    {
        $loan = $this->loan();
        $this->pay($loan, 'interest', '200');

        $this->assertSame(InterestPeriodStatus::Paid, $loan->interestPeriods()->first()->status);
    }
}
