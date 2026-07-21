<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Satu-satunya tempat membuka koneksi ke broker (F4b). Kredensial hanya dibaca
 * dari config('rabbitmq.connection') — tak ada string koneksi terserak di kode.
 */
class RabbitMqConnection
{
    public static function open(): AMQPStreamConnection
    {
        $c = config('rabbitmq.connection');

        return new AMQPStreamConnection(
            $c['host'],
            $c['port'],
            $c['user'],
            $c['password'],
            $c['vhost'],
        );
    }
}
