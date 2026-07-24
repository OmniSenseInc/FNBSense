<?php

return [
    'connection' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'fnbsense'),
        'password' => env('RABBITMQ_PASSWORD', ''),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],
    'topology' => [
        'exchange' => 'fnbsense.events',
        'dlx' => 'fnbsense.events.dlx',
        'dead_queue' => 'fnbsense.dead',
        'queue' => 'reporting.sales',
        'routing_key' => 'order.paid',
    ],
    'consume' => ['prefetch' => 10],
];
