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

    /*
    |--------------------------------------------------------------------------
    | Collateral
    |--------------------------------------------------------------------------
    |
    | Accepted collateral types ("gold, diamond, mixed/other (configurable)", docs/01) until the
    | Settings module manages them. Karat is optional (diamonds have none) and at most 24 (pure gold).
    |
    */

    'collateral' => [
        'types' => ['gold', 'diamond', 'mixed', 'other'],
        'max_karat' => '24',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    |
    | Accepted payment methods ("payment method should be configurable", docs/01) until the
    | Settings module manages them.
    |
    */

    'payment_methods' => ['cash', 'bank', 'mobile_banking', 'card', 'other'],

    /*
    |--------------------------------------------------------------------------
    | Missed-Interest Alerts
    |--------------------------------------------------------------------------
    |
    | Consecutive missed interest periods that raise an alert ("missed-period alert threshold",
    | docs/00, docs/01; the docs' example default is 2). Read only through
    | App\Domain\Alert\AlertSettings, so the Settings module can take it over. An alert is
    | information only: it never marks a loan defaulted, closes it or touches collateral.
    |
    */

    'alerts' => [
        'missed_period_threshold' => (int) env('ALERT_MISSED_PERIOD_THRESHOLD', 2),
    ],

];
