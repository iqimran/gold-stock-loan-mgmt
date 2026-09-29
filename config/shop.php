<?php

return [

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
