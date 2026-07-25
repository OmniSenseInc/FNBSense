<?php

return [
    'connection' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'fnbsense'),
        'password' => env('RABBITMQ_PASSWORD', ''),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],
    // Topologi BERSAMA (harus identik dgn Inventory/Ordering) + queue milik Notification.
    'topology' => [
        'exchange' => 'fnbsense.events',          // topic, durable
        'dlx' => 'fnbsense.events.dlx',           // fanout, durable
        'dead_queue' => 'fnbsense.dead',          // durable
        'queue' => 'notification.alerts',         // queue consumer Notification (durable)
        'routing_keys' => [                       // alarm stok dari Inventory (F4c)
            'inventory.low_stock',
            'inventory.shortfall',
            'inventory.recipe_missing',
        ],
    ],
    'consume' => ['prefetch' => 10],
];
