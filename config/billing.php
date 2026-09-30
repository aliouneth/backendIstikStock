<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Payment driver
    |---------------------------------------------------------------------------
    | "manual" is a development placeholder that charges nothing. Point this at
    | a real driver (e.g. "stripe") once the gateway is implemented, then the
    | same checkout endpoints will capture payment before granting the plan.
    */

    'driver' => env('BILLING_DRIVER', 'manual'),

    'currency' => env('BILLING_CURRENCY', 'usd'),

    /*
    |---------------------------------------------------------------------------
    | Manual confirmations
    |---------------------------------------------------------------------------
    | Off by default in production so a forgotten driver can never hand out
    | unpaid upgrades. Override only for local/staging testing.
    */

    'allow_manual' => env('BILLING_ALLOW_MANUAL', env('APP_ENV', 'production') !== 'production'),

];
