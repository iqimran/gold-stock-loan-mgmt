<?php

namespace Tests\Feature\Payments;

use App\Domain\Customer\CustomerSummary;
use App\Domain\Ledger\CustomerLedgerService;
use App\Domain\Loan\LoanService;
use App\Enums\LedgerEntryType;
use App\Enums\LoanEventType;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\InterestPeriod;
use App\Models\LedgerEntry;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Payment reversal. Scenario (as PaymentEngineTest): 10,000.00 at 2% a month from 2026-10-01, activated on
 * 2026-12-15: Oct and Nov interest (200.00 each) overdue, Dec upcoming; ledger balance 10,400.00.
 */
class PaymentReversalTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->supervisor = User::factory()->create();
        $this->supervisor->givePermissionTo([
            Permission::PaymentsCreate->value, Permission::PaymentsView->value, Permission::PaymentsReverse->value, Permission::LoansView->value,
        ]);
        Sanctum::actingAs($this->supervisor);
    }

    private function loan(): Loan
    {
        $loan = Loan::factory()->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-10-01']);

        return app(LoanService::class)->activate($loan, User::factory()->create());
    }

    private function pay(Loan $loan, string $type, string $amount, string $date = '2026-12-15'): string
    {
        return $this->postJson('/api/v1/payments', ['loan' => $loan->loan_no, 'type' => $type, 'amount' => $amount, 'method' => 'cash', 'payment_date' => $date])
            ->assertCreated()
            ->json('data.receipt_no');
    }

    private function reverse(string $receipt, ?string $reason = 'Posted on the wrong loan')
    {
        return $this->postJson("/api/v1/payments/{$receipt}/reverse", ['reason' => $reason]);
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

    /**
     * Every period's paid interest equals the interest allocated to it by payments that are still posted.
     */
    private function assertPeriodsMatchPostedAllocations(Loan $loan): void
    {
        foreach ($loan->interestPeriods()->get() as $period) {
            $allocated = DB::table('payment_allocations as a')
                ->join('payments as p', 'p.id', '=', 'a.payment_id')
                ->where('a.interest_period_id', $period->id)
                ->whereNull('p.reversed_at')
                ->sum('a.interest_amount');

            $this->assertSame(Money::of((string) $allocated), $period->paid_interest, "Period {$period->period_start->toDateString()}");
        }
    }

    // ── successful reversal ─────────────────────────────────────────────────────────────────

    public function test_reversal_voids_the_payment_and_keeps_it_visible(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'interest', '300');
        Carbon::setTestNow('2026-12-16 09:30:00');

        $this->reverse($receipt)
            ->assertOk()
            ->assertJsonPath('data.receipt_no', $receipt)
            ->assertJsonPath('data.status', 'reversed')
            ->assertJsonPath('data.amount', '300.00')                 // amount never changed
            ->assertJsonPath('data.allocation.interest', '300.00')    // original allocation still visible
            ->assertJsonPath('data.reversed_at', now()->toIso8601String())
            ->assertJsonPath('data.reversed_by', $this->supervisor->name)
            ->assertJsonPath('data.reversal_reason', 'Posted on the wrong loan')
            ->assertJsonPath('data.can_reverse', false);

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Reversed, $payment->status);
        $this->assertSame($this->supervisor->id, $payment->reversed_by);
        $this->assertSame('2026-12-15', $payment->payment_date->toDateString());
        $this->assertSame(2, $payment->allocations()->count());

        $this->getJson("/api/v1/payments/{$receipt}")->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->getJson('/api/v1/payments?status=reversed')->assertJsonPath('meta.total', 1);

        $event = $loan->events()->where('event_type', LoanEventType::PaymentReversed)->sole();
        $this->assertSame($this->supervisor->id, $event->actor_id);
        $this->assertSame('Posted on the wrong loan', $event->payload['reason']);
        $this->assertSame('300.00', $event->payload['interest']);
    }

    // ── interest restoration ────────────────────────────────────────────────────────────────

    public function test_interest_is_taken_back_off_the_periods(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'interest', '600');
        $this->assertSame([['200.00', 'paid'], ['200.00', 'paid'], ['200.00', 'paid']], $this->periods($loan));
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);

        $this->reverse($receipt)->assertOk();

        $this->assertSame([['0.00', 'overdue'], ['0.00', 'overdue'], ['0.00', 'upcoming']], $this->periods($loan));
        $this->assertNull($loan->interestPeriods()->first()->paid_at);
        $this->assertSame(LoanStatus::Overdue, $loan->fresh()->status);
        $this->assertPeriodsMatchPostedAllocations($loan);
    }

    public function test_reversing_an_older_payment_leaves_later_payments_intact(): void
    {
        $loan = $this->loan();
        $first = $this->pay($loan, 'interest', '300');   // Oct 200, Nov 100
        $this->pay($loan, 'interest', '200');            // Nov 100, Dec 100

        $this->reverse($first)->assertOk();

        $this->assertSame([['0.00', 'overdue'], ['100.00', 'overdue'], ['100.00', 'partially_paid']], $this->periods($loan));
        $this->assertPeriodsMatchPostedAllocations($loan);
        $this->assertSame('10200.00', $this->balance($loan));
    }

    // ── balance restoration ─────────────────────────────────────────────────────────────────

    public function test_principal_is_restored(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'principal', '2500');
        $this->assertSame('7500.00', $loan->fresh()->outstanding_principal);

        $this->reverse($receipt)->assertOk()->assertJsonPath('data.allocation.principal', '2500.00');

        $this->assertSame('10000.00', $loan->fresh()->outstanding_principal);
    }

    public function test_combined_payment_is_fully_undone(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'principal_and_interest', '1000');
        $this->assertSame('9600.00', $loan->fresh()->outstanding_principal);

        $this->reverse($receipt)->assertOk();

        $this->assertSame('10000.00', $loan->fresh()->outstanding_principal);
        $this->assertSame([['0.00', 'overdue'], ['0.00', 'overdue'], ['0.00', 'upcoming']], $this->periods($loan));
        $this->assertSame(LoanStatus::Overdue, $loan->fresh()->status);
        $this->assertSame('10400.00', $this->balance($loan));
    }

    // ── ledger restoration ──────────────────────────────────────────────────────────────────

    public function test_ledger_gets_a_compensating_entry_and_original_entries_stay(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'interest', '300');
        $this->assertSame('10100.00', $this->balance($loan));

        $this->reverse($receipt)->assertOk();

        $this->assertSame('10400.00', $this->balance($loan));
        $entries = LedgerEntry::where('payment_id', Payment::sole()->id)->orderBy('id')->get();
        $this->assertSame(
            [['payment_received', '0.00', '300.00', '10100.00'], ['payment_reversed', '300.00', '0.00', '10400.00']],
            $entries->map(fn ($e) => [$e->entry_type->value, $e->debit, $e->credit, $e->balance_after])->all(),
        );
        $this->assertSame($this->supervisor->id, $entries[1]->created_by);
        $this->assertStringContainsString('Posted on the wrong loan', $entries[1]->description);
    }

    public function test_fee_reversal_nets_to_zero(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'other_fee', '75.50');

        $this->reverse($receipt)->assertOk();

        $this->assertSame('10400.00', $this->balance($loan));
        $this->assertSame(
            ['fee_charged', 'payment_received', 'payment_reversed', 'fee_reversed'],
            LedgerEntry::whereNotNull('payment_id')->orderBy('id')->pluck('entry_type')->map->value->all(),
        );
    }

    // ── customer summaries ──────────────────────────────────────────────────────────────────

    public function test_customer_summary_is_corrected(): void
    {
        $loan = $this->loan();
        $summary = fn () => app(CustomerSummary::class)->apply(Customer::query()->whereKey($loan->customer_id))->first();
        $receipt = $this->pay($loan, 'interest', '400');
        $this->assertSame('0.00', $summary()->total_interest_due);

        $this->reverse($receipt)->assertOk();

        $this->assertSame('400.00', $summary()->total_interest_due);
        $this->assertSame(2, $summary()->consecutive_missed);
        $this->assertNull($summary()->last_payment_date); // reversed payments don't count
    }

    // ── refused reversals ───────────────────────────────────────────────────────────────────

    public function test_unauthorized_users_cannot_reverse(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'interest', '300');

        Sanctum::actingAs(tap(User::factory()->create())->givePermissionTo([Permission::PaymentsCreate->value, Permission::PaymentsView->value]));
        $this->reverse($receipt)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->reverse($receipt)->assertUnauthorized();

        $this->assertSame(PaymentStatus::Posted, Payment::sole()->status);
        $this->assertSame('10100.00', $this->balance($loan));
    }

    public function test_a_reason_is_required(): void
    {
        $receipt = $this->pay($this->loan(), 'interest', '300');

        $this->reverse($receipt, '')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->reverse($receipt, 'no')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(PaymentStatus::Posted, Payment::sole()->status);
    }

    public function test_a_payment_cannot_be_reversed_twice(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'principal', '1000');
        $this->reverse($receipt)->assertOk();

        $this->reverse($receipt, 'Again')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'has already been reversed']);

        $this->assertSame('10000.00', $loan->fresh()->outstanding_principal); // restored once, not twice
        $this->assertSame(1, LedgerEntry::where('entry_type', LedgerEntryType::PaymentReversed)->count());
        $this->assertSame('Posted on the wrong loan', Payment::sole()->reversal_reason);
    }

    public function test_principal_reversal_is_refused_once_a_newer_period_has_started(): void
    {
        $loan = $this->loan();
        $principal = $this->pay($loan, 'principal', '2500');
        $interest = $this->pay($loan, 'interest', '200');

        // January starts; its interest is charged on the reduced 7,500.00.
        Carbon::setTestNow('2027-01-02 10:00:00');
        $this->artisan('loans:process-interest')->assertSuccessful();

        $this->reverse($principal)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'a newer interest period started on 2027-01-01']);
        $this->assertSame('7500.00', $loan->fresh()->outstanding_principal);

        // An interest-only payment from the same day can still be reversed.
        $this->reverse($interest)->assertOk();
    }

    public function test_payments_on_closed_loans_cannot_be_reversed(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'principal_and_interest', '10600');
        app(LoanService::class)->close($loan->fresh(), $this->supervisor);

        $this->reverse($receipt)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'is closed']);
        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);
        $this->assertSame('0.00', $this->balance($loan));
    }

    public function test_an_unknown_payment_is_not_found(): void
    {
        $this->reverse('RCPT-000000-000000')->assertNotFound();
    }

    // ── atomicity ───────────────────────────────────────────────────────────────────────────

    public function test_a_failure_part_way_leaves_the_payment_and_balances_untouched(): void
    {
        $loan = $this->loan();
        $receipt = $this->pay($loan, 'principal_and_interest', '1000');
        $before = [$this->periods($loan), $loan->fresh()->outstanding_principal, $this->balance($loan)];

        $this->app->instance(CustomerLedgerService::class, new class extends CustomerLedgerService
        {
            public function post($customer, LedgerEntryType $type, string $amount, $date, string $description, string $reference, array $links = []): LedgerEntry
            {
                if ($type === LedgerEntryType::PaymentReversed) {
                    throw new RuntimeException('Ledger unavailable');
                }

                return parent::post($customer, $type, $amount, $date, $description, $reference, $links);
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->reverse($receipt);
            $this->fail('The reversal should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Ledger unavailable', $e->getMessage());
        }

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Posted, $payment->status);
        $this->assertNull($payment->reversed_at);
        $this->assertSame($before, [$this->periods($loan), $loan->fresh()->outstanding_principal, $this->balance($loan)]);
        $this->assertSame(0, $loan->events()->where('event_type', LoanEventType::PaymentReversed)->count());
    }
}
