<?php

namespace App\Domain\Alert;

use App\Domain\Settings\LoanSettings;
use InvalidArgumentException;

/**
 * The configurable alert rule (Settings → Loan settings; default in config/loans.php). Never hard-coded.
 */
final readonly class AlertSettings
{
    public function __construct(public int $missedPeriodThreshold = 2)
    {
        if ($missedPeriodThreshold < 1) {
            throw new InvalidArgumentException("The missed-period alert threshold must be at least 1, [{$missedPeriodThreshold}] given.");
        }
    }

    public static function fromSettings(LoanSettings $settings): self
    {
        return new self($settings->alertThreshold());
    }
}
