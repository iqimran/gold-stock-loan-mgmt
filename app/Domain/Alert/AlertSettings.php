<?php

namespace App\Domain\Alert;

use InvalidArgumentException;

/**
 * The configurable alert rule (config/loans.php today; the Settings module later). Never hard-coded.
 */
final readonly class AlertSettings
{
    public function __construct(public int $missedPeriodThreshold = 2)
    {
        if ($missedPeriodThreshold < 1) {
            throw new InvalidArgumentException("The missed-period alert threshold must be at least 1, [{$missedPeriodThreshold}] given.");
        }
    }

    public static function fromConfig(): self
    {
        return new self((int) config('loans.alerts.missed_period_threshold', 2));
    }
}
