<?php

namespace Tests\Unit;

use App\Domain\Loan\LoanStatusTransitions;
use App\Enums\LoanStatus;
use PHPUnit\Framework\TestCase;

class LoanStatusTransitionsTest extends TestCase
{
    /** The complete business-decided lifecycle; every other pair must be rejected. */
    private const ALLOWED = [
        'draft' => ['active', 'cancelled'],
        'active' => ['overdue', 'closed', 'cancelled'],
        'overdue' => ['active', 'closed', 'cancelled'],
        'closed' => [],
        'cancelled' => [],
    ];

    public function test_every_status_pair_matches_the_decided_lifecycle(): void
    {
        foreach (LoanStatus::cases() as $from) {
            foreach (LoanStatus::cases() as $to) {
                $expected = in_array($to->value, self::ALLOWED[$from->value], true);

                $this->assertSame($expected, LoanStatusTransitions::allows($from, $to), "{$from->value} → {$to->value}");
            }
        }
    }

    public function test_closed_and_cancelled_are_terminal(): void
    {
        $this->assertTrue(LoanStatusTransitions::isTerminal(LoanStatus::Closed));
        $this->assertTrue(LoanStatusTransitions::isTerminal(LoanStatus::Cancelled));
        $this->assertFalse(LoanStatusTransitions::isTerminal(LoanStatus::Draft));
        $this->assertSame([], LoanStatusTransitions::from(LoanStatus::Closed));
    }

    public function test_a_draft_cannot_be_closed_or_become_overdue(): void
    {
        $this->assertFalse(LoanStatusTransitions::allows(LoanStatus::Draft, LoanStatus::Closed));
        $this->assertFalse(LoanStatusTransitions::allows(LoanStatus::Draft, LoanStatus::Overdue));
    }
}
