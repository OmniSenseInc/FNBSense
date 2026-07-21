<?php

// Konfigurasi broker + topologi event (F4b). Sama sumber kebenaran nama seperti
// Ordering — exchange/DLX identik supaya consumer nyambung ke pipa yang sama.
// Consumer Inventory menambah queue-nya sendiri: inventory.orders.
return [
    'connection' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'fnbsense'),
        'password' => env('RABBITMQ_PASSWORD', ''),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],

    // Topologi BERSAMA (harus identik dgn Ordering) + queue milik Inventory.
    'topology' => [
        'exchange' => 'fnbsense.events',          // topic, durable (dibuat Ordering/relay)
        'dlx' => 'fnbsense.events.dlx',           // fanout, durable
        'dead_queue' => 'fnbsense.dead',          // durable
        'queue' => 'inventory.orders',            // queue consumer Inventory (durable)
        'routing_key' => 'order.paid',            // pola yang di-bind ke exchange
    ],

    // Consumer daemon
    'consume' => [
        'prefetch' => 10,       // maksimal pesan belum-ACK di tangan consumer
    ],
];
