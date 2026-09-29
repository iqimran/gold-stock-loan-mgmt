<?php

namespace App\Domain\Interest;

use Carbon\CarbonImmutable;

/**
 * One interest period's dates (inclusive), as Y-m-d strings.
 */
final readonly class InterestPeriodBounds
{
    public function __construct(
        public int $index,
        public string $start,
        public string $end,
        public string $due,
    ) {}

    /**
     * Number of days in the period, both ends included.
     */
    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->start, 'UTC')->diffInDays(CarbonImmutable::parse($this->end, 'UTC')) + 1;
    }
}
