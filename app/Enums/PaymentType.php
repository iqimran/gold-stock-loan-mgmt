<?php

namespace App\Enums;

/**
 * Payment types (docs/01). How each is allocated is decided in App\Domain\Payment\PaymentAllocator.
 */
enum PaymentType: string
{
    /** Interest only, oldest unpaid period first. */
    case Interest = 'interest';

    /** Reduces outstanding principal only. */
    case Principal = 'principal';

    /** Clears unpaid interest (oldest first), the remainder reduces principal. */
    case PrincipalAndInterest = 'principal_and_interest';

    /** A fee collected at the counter; touches neither interest nor principal. */
    case OtherFee = 'other_fee';

    /** Not accepted until an explicit adjustment workflow is specified (docs/08 Payments 1). */
    case Adjustment = 'adjustment';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
