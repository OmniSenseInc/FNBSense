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

    // Service Inventory — gerbang stok sebelum order dibuat. Nilai
    // service_token HARUS sama dengan INVENTORY_SERVICE_TOKEN di Inventory.
    //
    // Kosong = gerbangnya mati: Inventory membalas 401 dan klien fail-open,
    // jadi pesanan tetap lewat. Itu SENGAJA (lihat InventoryClient), tapi
    // artinya token yang lupa dipasang tak akan pernah terlihat dari layar —
    // hanya dari baris WARNING di log.
    'inventory' => [
        'base_url' => env('INVENTORY_BASE_URL', 'http://127.0.0.1:8003'),
        'timeout' => (int) env('INVENTORY_TIMEOUT', 3),
        'service_token' => env('INVENTORY_SERVICE_TOKEN'),
    ],

    // Service Catalog — sumber harga & ketersediaan produk. Ordering memanggil
    // langsung ke port service-nya (bukan lewat gateway) untuk snapshot harga.
    'catalog' => [
        'base_url' => env('CATALOG_BASE_URL', 'http://127.0.0.1:8001'),
        // Detik. Catalog lambat/mati -> order baru gagal cepat (503), bukan
        // customer menggantung. Connect & response pakai batas yang sama.
        'timeout' => (int) env('CATALOG_TIMEOUT', 3),
        'internal_token' => env('CATALOG_SERVICE_TOKEN'),
        'promotions_enabled' => env('PROMOTIONS_ENABLED', true),
    ],

];
