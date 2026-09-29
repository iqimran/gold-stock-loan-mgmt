<?php

namespace Tests\Feature\Customers;

use App\Domain\Customer\CustomerSearch;
use App\Domain\Customer\CustomerSummary;
use App\Enums\LoanStatus;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Derived customer figures (docs/01 Customer, docs/08 Interest 3–5, business-decided missed rule).
 * "Today" is 2026-12-15 throughout.
 */
class CustomerSummaryTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-15 10:00:00');
    }

    private function summaryOf(Customer $customer): Customer
    {
        return app(CustomerSummary::class)->apply(Customer::query()->whereKey($customer->id))->firstOrFail();
    }

    private function loan(Customer $customer, LoanStatus $status, ?string $nextDue = null): int
    {
        return DB::table('loans')->insertGetId([
            'loan_no' => 'L-'.++$this->sequence, 'customer_id' => $customer->id, 'principal' => '10000.00', 'outstanding_principal' => '10000.00',
            'interest_rate' => '2.0000', 'interest_rate_type' => 'percent', 'interest_period_unit' => 'month',
            'status' => $status->value, 'start_date' => '2026-09-01', 'next_due_date' => $nextDue,
        ]);
    }

    /**
     * @param  string  $paid  paid_interest against 200.00 expected
     */
    private function period(int $loanId, string $dueDate, string $paid = '0.00', bool $waived = false): void
    {
        $due = Carbon::parse($dueDate);

        DB::table('interest_periods')->insert([
            'loan_id' => $loanId, 'period_start' => $due->copy()->startOfMonth()->toDateString(), 'period_end' => $dueDate, 'due_date' => $dueDate,
            'expected_interest' => '200.00', 'paid_interest' => $paid, 'status' => 'due',
            'waived_at' => $waived ? '2026-11-05 10:00:00' : null, 'waived_by' => $waived ? $this->admin()->id : null, 'waiver_reason' => $waived ? 'Goodwill' : null,
        ]);
    }

    private function payment(Customer $customer, int $loanId, string $date, string $amount, bool $reversed = false): void
    {
        DB::table('payments')->insert([
            'receipt_no' => 'R-'.++$this->sequence, 'customer_id' => $customer->id, 'loan_id' => $loanId, 'type' => 'interest', 'amount' => $amount,
            'method' => 'cash', 'payment_date' => $date, 'status' => $reversed ? 'reversed' : 'posted',
            'reversed_at' => $reversed ? '2026-12-02 10:00:00' : null, 'reversed_by' => $reversed ? $this->admin()->id : null, 'reversal_reason' => $reversed ? 'Entered twice' : null,
        ]);
    }

    /**
     * Customer A: two open loans (worst streak 2) and a closed loan that must be ignored.
     */
    private function customerWithHistory(): Customer
    {
        $customer = Customer::factory()->create();

        $first = $this->loan($customer, LoanStatus::Active, '2026-12-31');
        $this->period($first, '2026-09-30', '200.00');   // settled
        $this->period($first, '2026-10-31');             // missed
        $this->period($first, '2026-11-30', '100.00');   // partially paid: still missed, streak continues
        $this->period($first, '2026-12-31');             // not yet due

        $second = $this->loan($customer, LoanStatus::Overdue, '2026-12-30');
        $this->period($second, '2026-09-30');            // missed, but before the waiver
        $this->period($second, '2026-10-31', waived: true); // waiver resets the streak
        $this->period($second, '2026-11-30');            // missed

        $closed = $this->loan($customer, LoanStatus::Closed, '2026-01-01');
        $this->period($closed, '2026-06-30');
        $this->period($closed, '2026-07-31');
        $this->period($closed, '2026-08-31');

        $this->payment($customer, $first, '2026-11-30', '100.00');
        $this->payment($customer, $first, '2026-12-01', '500.00', reversed: true);

        return $customer;
    }

    public function test_summary_figures_are_derived_from_open_loans(): void
    {
        $summary = $this->summaryOf($this->customerWithHistory());

        $this->assertSame(2, $summary->active_loans_count);
        // First loan: 200 (Oct) + 100 (Nov remainder); second loan: 200 (Sep) + 200 (Nov). Closed loan excluded.
        $this->assertSame('700.00', $summary->total_interest_due);
        // Worst open loan: first loan's Oct + Nov (a partial payment does not reset the streak).
        $this->assertSame(2, $summary->consecutive_missed);
        $this->assertSame('2026-12-30', $summary->next_due_date->toDateString());
        // The reversed 500.00 payment is ignored.
        $this->assertSame('2026-11-30', $summary->last_payment_date->toDateString());
        $this->assertSame('100.00', $summary->last_payment_amount);
    }

    public function test_customer_without_loans_has_empty_figures(): void
    {
        $summary = $this->summaryOf(Customer::factory()->create());

        $this->assertSame(0, $summary->active_loans_count);
        $this->assertSame('0.00', $summary->total_interest_due);
        $this->assertNull($summary->consecutive_missed);
        $this->assertNull($summary->next_due_date);
        $this->assertNull($summary->last_payment_date);
    }

    public function test_a_fully_paid_period_resets_the_streak_but_older_misses_remain_overdue(): void
    {
        $customer = Customer::factory()->create();
        $loan = $this->loan($customer, LoanStatus::Active);
        $this->period($loan, '2026-09-30');              // missed long ago
        $this->period($loan, '2026-10-31', '200.00');    // paid in full: resets the streak
        $this->period($loan, '2026-11-30', '200.00');

        $summary = $this->summaryOf($customer);

        $this->assertNull($summary->consecutive_missed);
        $this->assertSame('200.00', $summary->total_interest_due);
        $this->assertSame([$customer->id], $this->search(['overdue' => true]));
    }

    public function test_interest_due_today_is_due_but_not_yet_missed(): void
    {
        $customer = Customer::factory()->create();
        $this->period($this->loan($customer, LoanStatus::Active), '2026-12-15');

        $summary = $this->summaryOf($customer);

        $this->assertSame('200.00', $summary->total_interest_due);
        $this->assertNull($summary->consecutive_missed);
        $this->assertSame([], $this->search(['overdue' => true]));
    }

    public function test_overdue_and_missed_threshold_filters(): void
    {
        $withHistory = $this->customerWithHistory();

        $upToDate = Customer::factory()->create();
        $loan = $this->loan($upToDate, LoanStatus::Active);
        $this->period($loan, '2026-10-31', '200.00');
        $this->period($loan, '2026-11-30', '200.00');

        $this->assertSame([$withHistory->id], $this->search(['overdue' => true]));
        $this->assertSame([$withHistory->id], $this->search(['min_missed' => 1]));
        $this->assertSame([$withHistory->id], $this->search(['min_missed' => 2]));
        $this->assertSame([], $this->search(['min_missed' => 3]));
        $this->assertEqualsCanonicalizing([$withHistory->id, $upToDate->id], $this->search([]));
    }

    /**
     * @return list<int>
     */
    private function search(array $filters): array
    {
        return app(CustomerSearch::class)->query($filters)->pluck('customers.id')->all();
    }
}
