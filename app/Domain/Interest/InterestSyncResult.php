<?php

namespace App\Domain\Interest;

final readonly class InterestSyncResult
{
    public function __construct(
        public int $periodsCreated,
        public int $statusesChanged,
    ) {}
}
