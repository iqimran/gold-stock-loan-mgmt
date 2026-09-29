<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shop Details
    |--------------------------------------------------------------------------
    |
    | Printed on customer receipts (as in the Inventory POS). The Settings module
    | ("shop/business information", docs/01) will take these over.
    |
    */

    'name' => env('SHOP_NAME', env('APP_NAME', 'Gold Stock & Loan Management')),
    'address' => env('SHOP_ADDRESS'),
    'phone' => env('SHOP_PHONE'),
    'receipt_footer' => env('SHOP_RECEIPT_FOOTER', 'Thank you. Please keep this receipt.'),

    /*
    |--------------------------------------------------------------------------
    | Initial Administrator
    |--------------------------------------------------------------------------
    |
    | Used by the database seeder to create the first Admin account. A
    | password must be supplied outside of local/testing environments.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
