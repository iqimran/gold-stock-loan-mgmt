<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interest Engine
    |--------------------------------------------------------------------------
    |
    | Defaults for the interest calculation ("interest calculation mode" and "due-day policy" in
    | docs/01). Read only through App\Domain\Interest\InterestSettings, so the Settings module can
    | take them over later. Business dates ("today", due dates) use the application time zone
    | (APP_TIMEZONE).
    |
    */

    'interest' => [
        // outstanding: outstanding principal at the start of each period (reducing balance)
        // principal:   the loan's original principal for every period (flat)
        'base' => env('INTEREST_BASE', 'outstanding'),

        // period_end:   interest is due on the last day of the period (in arrears)
        // period_start: interest is due on the first day of the period (in advance)
        'due' => env('INTEREST_DUE', 'period_end'),

        // How a yearly rate becomes a monthly period rate. twelfths: rate ÷ 12;
        // actual_days: rate × days in the period ÷ 365.
        'yearly_conversion' => env('INTEREST_YEARLY_CONVERSION', 'twelfths'),
    ],

];
