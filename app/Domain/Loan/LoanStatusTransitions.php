<?php

namespace App\Domain\Loan;

use App\Enums\LoanStatus;

/**
 * The single definition of which loan status changes are allowed (docs/01: "centralize them").
 * Business-decided lifecycle (the docs list the statuses only):
 *
 *   draft ──activate──▶ active ◀──system──▶ overdue
 *     │                   │                    │
 *     └──cancel──▶ cancelled ◀──cancel─────────┤   (open loans: only without posted payments)
 *                         active/overdue ──close──▶ closed   (settlement rules: LoanSettlement)
 *
 * closed and cancelled are terminal. active ⇄ overdue is set by the scheduler (interest engine /
 * missed-payment detection), never by a user action.
 */
final class LoanStatusTransitions
{
    /** @var array<string, list<LoanStatus>> */
    private const ALLOWED = [
        'draft' => [LoanStatus::Active, LoanStatus::Cancelled],
        'active' => [LoanStatus::Overdue, LoanStatus::Closed, LoanStatus::Cancelled],
        'overdue' => [LoanStatus::Active, LoanStatus::Closed, LoanStatus::Cancelled],
        'closed' => [],
        'cancelled' => [],
    ];

    public static function allows(LoanStatus $from, LoanStatus $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value], true);
    }

    /**
     * @return list<LoanStatus>
     */
    public static function from(LoanStatus $status): array
    {
        return self::ALLOWED[$status->value];
    }

    public static function isTerminal(LoanStatus $status): bool
    {
        return self::ALLOWED[$status->value] === [];
    }
}
