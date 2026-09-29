<?php

namespace Tests\Feature\Interest;

use App\Domain\Loan\LoanService;
use App\Domain\Loan\LoanSummary;
use App\Domain\Payment\PaymentService;
use App\Domain\Reporting\CustomerLedgerReport;
use App\Enums\LoanStatus;
use App\Models\CollateralItem;
use App\Models\Loan;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Business rules stated by the shop:
 *  1. Interest is always calculated on the principal amount (simple interest, never on unpaid
 *     interest). Only a principal payment — any day, any part — reduces the principal amount; interest
 *     payments leave it unchanged. The month after a principal payment is charged on the rest.
 *  2. A loan has no fixed term: if the principal is not repaid within the year, the loan carries
 *     forward into the next year, period after period, until the principal is fully repaid.
 *
 * Scenario: 10,000.00 at 2% a month from 2025-01-01; monthly periods due on the month's last day.
 */
class PrincipalReductionCarryForwardTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create();
    }

    private function loan(): Loan
    {
        Carbon::setTestNow('2025-01-01 09:00:00');
        $loan = Loan::factory()->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => '2025-01-01']);
        CollateralItem::factory()->for($loan)->create();

        return app(LoanService::class)->activate($loan, $this->cashier);
    }

    private function runOn(string $date): void
    {
        Carbon::setTestNow("{$date} 00:05:00");
        $this->artisan('loans:process-interest')->assertSuccessful();
    }

    private function pay(Loan $loan, string $type, string $amount): void
    {
        Carbon::setTestNow(today()->setTime(10, 0));
        app(PaymentService::class)->post($loan->fresh(), ['type' => $type, 'amount' => $amount, 'method' => 'cash', 'payment_date' => today()->toDateString()], $this->cashier);
    }

    private function interest(Loan $loan, string $periodStart): ?string
    {
        return $loan->interestPeriods()->whereDate('period_start', $periodStart)->value('expected_interest');
    }

    public function test_principal_payment_reduces_the_interest_of_the_following_periods(): void
    {
        $loan = $this->loan();
        $this->runOn('2025-02-10'); // Jan charged (200.00); Feb running on 10,000.00

        // Interest first (Jan, and Feb which is already running: 400.00), then 4,000.00 principal.
        $this->pay($loan, 'principal_and_interest', '4400.00');
        $this->assertSame('6000.00', $loan->fresh()->outstanding_principal);

        $this->runOn('2025-04-01');
        $this->assertSame('200.00', $this->interest($loan, '2025-01-01'));
        $this->assertSame('200.00', $this->interest($loan, '2025-02-01')); // started before the payment: unchanged
        $this->assertSame('120.00', $this->interest($loan, '2025-03-01')); // 6,000.00 × 2%
        $this->assertSame('120.00', $this->interest($loan, '2025-04-01'));

        $this->pay($loan, 'principal', '1000.00');
        $this->runOn('2025-05-01');
        $this->assertSame('120.00', $this->interest($loan, '2025-04-01'));
        $this->assertSame('100.00', $this->interest($loan, '2025-05-01')); // 5,000.00 × 2%
    }

    public function test_interest_payments_never_reduce_the_principal_and_principal_can_be_paid_any_time(): void
    {
        $loan = $this->loan();

        // Customer pays only the interest each month: the principal amount and the interest stay the same.
        foreach (['2025-02-01', '2025-03-01', '2025-04-01', '2025-05-01'] as $date) {
            $this->runOn($date);
            $this->pay($loan, 'interest', '200.00');
            $this->assertSame('10000.00', $loan->fresh()->outstanding_principal);
        }
        $this->assertSame(['200.00'], $loan->interestPeriods()->pluck('expected_interest')->unique()->values()->all());

        // A partial principal payment on any day (here mid-month, with this month's interest not yet paid)
        // reduces the principal amount; the next month's interest is on the rest.
        $this->runOn('2025-05-17');
        $this->pay($loan, 'principal', '2500.00');
        $this->assertSame('7500.00', $loan->fresh()->outstanding_principal);

        $this->runOn('2025-06-01');
        $this->assertSame('200.00', $this->interest($loan, '2025-05-01')); // May: on 10,000.00 (started before)
        $this->assertSame('150.00', $this->interest($loan, '2025-06-01')); // June: 7,500.00 × 2%

        // Unpaid interest is never added to the principal: it only stays owed.
        $this->runOn('2025-09-01');
        $this->assertSame('7500.00', $loan->fresh()->outstanding_principal);
        $this->assertSame('150.00', $this->interest($loan, '2025-09-01'));
    }

    public function test_an_unpaid_loan_carries_forward_into_the_next_years_until_the_principal_is_repaid(): void
    {
        $loan = $this->loan();
        $this->runOn('2025-02-10');
        $this->pay($loan, 'principal_and_interest', '4400.00'); // Jan + Feb interest, 4,000.00 principal: 6,000.00 left

        // Two years later the loan is still open and still charging interest on the remaining principal.
        $this->runOn('2027-02-15');
        $loan->refresh();
        $this->assertContains($loan->status->value, LoanStatus::open());
        $this->assertSame('6000.00', $loan->outstanding_principal);
        $this->assertSame(26, $loan->interestPeriods()->count()); // Jan 2025 … Feb 2027, no break at year end
        $this->assertSame('120.00', $this->interest($loan, '2025-12-01'));
        $this->assertSame('120.00', $this->interest($loan, '2026-01-01'));
        $this->assertSame('120.00', $this->interest($loan, '2027-02-01'));

        // Year statement: 2026 opens with the balance carried forward from 2025.
        $ledger = app(CustomerLedgerReport::class);
        $year2025 = $ledger->totals($loan->customer, ['from' => '2025-01-01', 'to' => '2025-12-31']);
        $year2026 = $ledger->totals($loan->customer, ['from' => '2026-01-01', 'to' => '2026-12-31']);
        $this->assertSame($year2025['closing_balance'], $year2026['opening_balance']);
        $this->assertSame('1440.00', $year2026['debit']); // 12 × 120.00 charged in 2026

        // Paying everything that is owed ends the process: no principal left, the loan can be closed.
        // Payable interest = every generated period still unpaid, including the one running now.
        $payable = Money::of((string) $loan->interestPeriods()->reorder()->whereNull('waived_at')->selectRaw('sum(expected_interest - paid_interest) as payable')->value('payable'));
        // Unpaid interest carries forward too: Mar 2025 … Jan 2027 = 23 × 120.00 due, plus Feb 2027 running.
        $this->assertSame('2760.00', app(LoanSummary::class)->for($loan->fresh())['interest_due']);
        $this->assertSame('2880.00', $payable);
        $this->pay($loan, 'principal_and_interest', bcadd($payable, '6000.00', 2));
        $this->assertSame('0.00', $loan->fresh()->outstanding_principal);

        $closed = app(LoanService::class)->close($loan->fresh(), $this->cashier);
        $this->assertSame(LoanStatus::Closed, $closed->status);
    }
}
