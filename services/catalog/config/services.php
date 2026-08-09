<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Shared-secret auth service-to-service (Inventory -> Catalog, endpoint /api/recipe).
    'internal_token' => env('CATALOG_SERVICE_TOKEN'),

    // Inventory: sumber saldo bahan buat menandai produk habis di /api/menu.
    // service_token DIKIRIM sbg X-Service-Token dan HARUS sama dgn
    // INVENTORY_SERVICE_TOKEN di sisi Inventory. Sengaja BEDA dari
    // internal_token di atas — yang itu DITERIMA. Satu nilai untuk dua arah
    // berarti bocornya token Inventory ikut membuka pintu masuk Catalog.
    'inventory' => [
        'base_url' => env('INVENTORY_BASE_URL', 'http://127.0.0.1:8003'),
        'timeout' => (int) env('INVENTORY_TIMEOUT', 3),
        'service_token' => env('INVENTORY_SERVICE_TOKEN'),
    ],

];
