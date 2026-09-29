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
    | Settings defaults
    |--------------------------------------------------------------------------
    |
    | Defaults for Settings → Loan settings (App\Domain\Settings\LoanSettings). A value saved in
    | Settings wins; these apply until one is saved. Number formats: PREFIX-PERIOD-SEQUENCE.
    |
    */

    'currency' => [
        'code' => env('CURRENCY_CODE', 'BDT'),
        'symbol' => env('CURRENCY_SYMBOL', '৳'),
    ],

    'defaults' => [
        'interest_rate' => null,         // prefilled on the New loan form when set
        'interest_rate_type' => 'monthly',
        'grace_days' => (int) env('GRACE_DAYS', 0),
    ],

    'numbering' => [
        'customer' => 'CUS',
        'loan' => 'LN',
        'collateral' => 'COL',
        'receipt' => 'RCPT',
        'reset' => 'monthly',            // monthly: PREFIX-YYYYMM-…, yearly: PREFIX-YYYY-…
        'digits' => 6,
    ],

    'karat_options' => ['18', '21', '22', '24'],

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

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    |
    | PDF/Excel exports run within the request (there is no queued-export infrastructure); above
    | this many rows the user is asked to narrow the filters.
    |
    */

    'exports' => [
        'max_rows' => (int) env('EXPORT_MAX_ROWS', 5000),
    ],

    'alerts' => [
        'missed_period_threshold' => (int) env('ALERT_MISSED_PERIOD_THRESHOLD', 2),
    ],

];
