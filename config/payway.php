<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Merchant credentials
    |--------------------------------------------------------------------------
    |
    | The merchant ID and public (API) key ABA sends you when your PayWay
    | account is set up. Sandbox and production use different credentials.
    |
    */

    'merchant_id' => env('PAYWAY_MERCHANT_ID'),

    'api_key' => env('PAYWAY_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | RSA public key
    |--------------------------------------------------------------------------
    |
    | Needed only for refunds, pre-auth completion/cancellation, payment links
    | and payouts, which encrypt their payload. ABA sends it with your
    | credentials. Use the PEM text (escaped "\n" are fine), its base64 body,
    | or a path to a .pem file.
    |
    */

    'rsa_public_key' => env('PAYWAY_RSA_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | When true, requests go to checkout-sandbox.payway.com.kh. Set
    | PAYWAY_BASE_URL to override the host entirely.
    |
    */

    'sandbox' => env('PAYWAY_SANDBOX', true),

    'base_url' => env('PAYWAY_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => env('PAYWAY_TIMEOUT', 30),

];
