<?php

namespace Tests\Unit\Payment;

use App\Domain\Payment\PaymentAllocator;
use App\Enums\PaymentType;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pure allocation rules (no database; the Laravel test case only provides the validator for refusals).
 */
class PaymentAllocatorTest extends TestCase
{
    /** Oct fully unpaid, Nov partly paid (50 of 200), Dec unpaid; oldest due first. */
    private const PERIODS = [
        ['id' => 1, 'expected' => '200.00', 'paid' => '0.00'],
        ['id' => 2, 'expected' => '200.00', 'paid' => '50.00'],
        ['id' => 3, 'expected' => '200.00', 'paid' => '0.00'],
    ];

    private function allocate(PaymentType $type, string $amount, array $periods = self::PERIODS, string $outstanding = '10000.00')
    {
        return (new PaymentAllocator)->allocate($type, $amount, $periods, $outstanding);
    }

    // ── interest ────────────────────────────────────────────────────────────────────────────

    public function test_interest_settles_the_oldest_period_first(): void
    {
        $allocation = $this->allocate(PaymentType::Interest, '300.00');

        $this->assertSame([1 => '200.00', 2 => '100.00'], $allocation->periods);
        $this->assertSame('0.00', $allocation->principal);
        $this->assertSame('300.00', $allocation->interest());
        $this->assertSame('300.00', $allocation->total());
    }

    public function test_partial_interest_payment_stays_on_the_oldest_period(): void
    {
        $this->assertSame([1 => '0.01'], $this->allocate(PaymentType::Interest, '0.01')->periods);
        $this->assertSame([1 => '199.99'], $this->allocate(PaymentType::Interest, '199.99')->periods);
    }

    public function test_a_partly_paid_period_only_takes_its_remainder(): void
    {
        // 200 for Oct, then only 150 fits into Nov (50 already paid), 20 into Dec.
        $this->assertSame([1 => '200.00', 2 => '150.00', 3 => '20.00'], $this->allocate(PaymentType::Interest, '370.00')->periods);
    }

    public function test_exactly_all_interest_payable(): void
    {
        $this->assertSame([1 => '200.00', 2 => '150.00', 3 => '200.00'], $this->allocate(PaymentType::Interest, '550.00')->periods);
    }

    public function test_interest_above_what_is_payable_is_refused(): void
    {
        $this->assertRefused(PaymentType::Interest, '550.01', 'exceeds the interest payable (550.00)');
        $this->assertRefused(PaymentType::Interest, '0.01', 'exceeds the interest payable (0.00)', []);
    }

    // ── principal ───────────────────────────────────────────────────────────────────────────

    public function test_principal_payment_ignores_unpaid_interest(): void
    {
        $allocation = $this->allocate(PaymentType::Principal, '2500.00');

        $this->assertSame([], $allocation->periods);
        $this->assertSame('2500.00', $allocation->principal);
    }

    public function test_principal_above_outstanding_is_refused(): void
    {
        $this->assertSame('10000.00', $this->allocate(PaymentType::Principal, '10000.00')->principal);
        $this->assertRefused(PaymentType::Principal, '10000.01', 'exceeds the outstanding principal (10000.00)');
    }

    // ── combined ────────────────────────────────────────────────────────────────────────────

    public function test_combined_payment_clears_interest_first_then_principal(): void
    {
        $allocation = $this->allocate(PaymentType::PrincipalAndInterest, '1000.00');

        $this->assertSame([1 => '200.00', 2 => '150.00', 3 => '200.00'], $allocation->periods);
        $this->assertSame('550.00', $allocation->interest());
        $this->assertSame('450.00', $allocation->principal);
        $this->assertSame('1000.00', $allocation->total());
    }

    public function test_combined_payment_smaller_than_interest_payable_is_all_interest(): void
    {
        $allocation = $this->allocate(PaymentType::PrincipalAndInterest, '250.00');

        $this->assertSame([1 => '200.00', 2 => '50.00'], $allocation->periods);
        $this->assertSame('0.00', $allocation->principal);
    }

    public function test_combined_payment_without_interest_payable_is_all_principal(): void
    {
        $allocation = $this->allocate(PaymentType::PrincipalAndInterest, '400.00', []);

        $this->assertSame([], $allocation->periods);
        $this->assertSame('400.00', $allocation->principal);
    }

    public function test_combined_payment_can_settle_the_loan_exactly_but_not_more(): void
    {
        $this->assertSame('10000.00', $this->allocate(PaymentType::PrincipalAndInterest, '10550.00')->principal);
        $this->assertRefused(PaymentType::PrincipalAndInterest, '10550.01', 'exceeds the interest payable plus outstanding principal (10550.00)');
    }

    // ── fee / adjustment / amount ───────────────────────────────────────────────────────────

    public function test_fee_touches_neither_interest_nor_principal(): void
    {
        $allocation = $this->allocate(PaymentType::OtherFee, '75.50');

        $this->assertSame([], $allocation->periods);
        $this->assertSame('0.00', $allocation->principal);
        $this->assertSame('75.50', $allocation->fee);
        $this->assertSame('75.50', $allocation->total());
    }

    public function test_adjustments_are_refused_until_specified(): void
    {
        $this->assertRefused(PaymentType::Adjustment, '10.00', 'Adjustments are not accepted yet', field: 'type');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonPositive(): array
    {
        return ['zero' => ['0.00'], 'negative' => ['-5.00'], 'rounds to zero' => ['0.004']];
    }

    #[DataProvider('nonPositive')]
    public function test_amount_must_be_positive(string $amount): void
    {
        foreach ([PaymentType::Interest, PaymentType::Principal, PaymentType::PrincipalAndInterest, PaymentType::OtherFee] as $type) {
            $this->assertRefused($type, $amount, 'must be greater than zero');
        }
    }

    public function test_allocation_is_exact_and_deterministic(): void
    {
        $periods = array_map(fn (int $id) => ['id' => $id, 'expected' => '33.33', 'paid' => '0.00'], range(1, 3));

        foreach (range(1, 20) as $ignored) {
            $allocation = $this->allocate(PaymentType::PrincipalAndInterest, '100.00', $periods);
            $this->assertSame([1 => '33.33', 2 => '33.33', 3 => '33.33'], $allocation->periods);
            $this->assertSame('0.01', $allocation->principal);
            $this->assertSame('100.00', $allocation->total());
        }
    }

    private function assertRefused(PaymentType $type, string $amount, string $message, array $periods = self::PERIODS, string $field = 'amount'): void
    {
        try {
            $this->allocate($type, $amount, $periods);
            $this->fail("{$type->value} {$amount} was accepted.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString($message, $e->errors()[$field][0]);
        }
    }
}
