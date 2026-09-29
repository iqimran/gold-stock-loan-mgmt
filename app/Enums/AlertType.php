<?php

namespace App\Enums;

enum AlertType: string
{
    /** A loan's consecutive missed interest periods reached the configured threshold. */
    case MissedInterest = 'missed_interest';
}
