<?php

return [
    'store_id' => env('SSLCOMMERZ_STORE_ID'),
    'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
    'sandbox' => (bool) env('SSLCOMMERZ_SANDBOX', true),
    'currency' => env('SSLCOMMERZ_CURRENCY', 'BDT'),
    'frontend_url' => rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),
    'callback_base_url' => rtrim(env('SSLCOMMERZ_CALLBACK_BASE_URL', env('APP_URL', 'http://localhost')), '/'),
    'sandbox_base_url' => 'https://sandbox.sslcommerz.com',
    'live_base_url' => 'https://securepay.sslcommerz.com',
];
