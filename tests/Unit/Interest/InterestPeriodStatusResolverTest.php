<?php

namespace Tests\Unit\Interest;

use App\Domain\Interest\InterestPeriodStatusResolver;
use App\Enums\InterestPeriodStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InterestPeriodStatusResolverTest extends TestCase
{
    /**
     * Due date 2026-12-15, expected 200.00.
     *
     * @return array<string, array{0: string, 1: bool, 2: string, 3: InterestPeriodStatus}> [paid, waived, today, status]
     */
    public static function cases(): array
    {
        return [
            'before the due date' => ['0.00', false, '2026-12-14', InterestPeriodStatus::Upcoming],
            'long before the due date' => ['0.00', false, '2026-01-01', InterestPeriodStatus::Upcoming],
            'on the due date' => ['0.00', false, '2026-12-15', InterestPeriodStatus::Due],
            'the day after the due date' => ['0.00', false, '2026-12-16', InterestPeriodStatus::Overdue],
            'next year' => ['0.00', false, '2027-01-01', InterestPeriodStatus::Overdue],
            'partly paid before the due date' => ['50.00', false, '2026-12-01', InterestPeriodStatus::PartiallyPaid],
            'partly paid on the due date' => ['199.99', false, '2026-12-15', InterestPeriodStatus::PartiallyPaid],
            'partly paid after the due date is overdue' => ['199.99', false, '2026-12-16', InterestPeriodStatus::Overdue],
            'paid in advance' => ['200.00', false, '2026-11-01', InterestPeriodStatus::Paid],
            'paid on the due date' => ['200.00', false, '2026-12-15', InterestPeriodStatus::Paid],
            'paid late stays paid' => ['200.00', false, '2027-03-01', InterestPeriodStatus::Paid],
            'overpaid' => ['200.01', false, '2026-12-20', InterestPeriodStatus::Paid],
            'waived before due' => ['0.00', true, '2026-12-01', InterestPeriodStatus::Waived],
            'waived after due' => ['0.00', true, '2027-01-01', InterestPeriodStatus::Waived],
            'waived after a partial payment' => ['80.00', true, '2027-01-01', InterestPeriodStatus::Waived],
        ];
    }

    #[DataProvider('cases')]
    public function test_status_is_derived_from_facts_and_business_date(string $paid, bool $waived, string $today, InterestPeriodStatus $expected): void
    {
        $this->assertSame($expected, InterestPeriodStatusResolver::resolve('200.00', $paid, $waived, '2026-12-15', $today));
    }

    public function test_a_zero_interest_period_is_settled(): void
    {
        $this->assertSame(InterestPeriodStatus::Paid, InterestPeriodStatusResolver::resolve('0.00', '0.00', false, '2026-12-15', '2027-01-01'));
    }

    public function test_the_same_facts_always_give_the_same_status(): void
    {
        foreach (range(1, 50) as $ignored) {
            $this->assertSame(InterestPeriodStatus::Overdue, InterestPeriodStatusResolver::resolve('200.00', '10.00', false, '2026-12-15', '2026-12-16'));
        }
    }
}
