<?php

// Konfigurasi broker + topologi event (F3a). Semua NAMA exchange/queue/routing
// hidup di sini sebagai satu sumber kebenaran — relay & consumer merujuk ke sini,
// tak ada string broker yang terserak di kode.
return [
    'connection' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'fnbsense'),
        'password' => env('RABBITMQ_PASSWORD', ''),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],

    // Topologi — lihat docs/REALTIME.md. DLX dipasang dari awal (queue immutable).
    'topology' => [
        'exchange' => 'fnbsense.events',          // topic, durable
        'dlx' => 'fnbsense.events.dlx',           // fanout, durable
        'dead_queue' => 'fnbsense.dead',          // durable
    ],

    // Relay daemon
    'relay' => [
        'batch_size' => 100,        // baris outbox per pass
        'sleep_seconds' => 1,       // jeda saat backlog kosong
    ],
];
