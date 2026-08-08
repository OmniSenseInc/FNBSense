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

    // Secret yang DITERIMA Inventory di header X-Service-Token (gerbang stok
    // yang dipanggil Ordering). Sengaja BEDA dari catalog.service_token di
    // bawah — yang itu dikirim keluar. Satu nilai untuk dua arah berarti
    // bocornya token Catalog ikut membuka pintu masuk Inventory.
    'internal_token' => env('INVENTORY_SERVICE_TOKEN'),

    // Catalog: sumber resep (BOM) buat potong stok (F4b). service_token dikirim
    // sbg X-Service-Token dan HARUS sama dgn CATALOG_SERVICE_TOKEN di Catalog.
    'catalog' => [
        'base_url' => env('CATALOG_BASE_URL', 'http://127.0.0.1:8001'),
        'timeout' => (int) env('CATALOG_TIMEOUT', 3),
        'service_token' => env('CATALOG_SERVICE_TOKEN'),
    ],

];
