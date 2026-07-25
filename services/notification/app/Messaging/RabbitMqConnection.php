<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitMqConnection
{
    public static function open(): AMQPStreamConnection
    {
        $connection = config('rabbitmq.connection');

        return new AMQPStreamConnection(
            $connection['host'], $connection['port'], $connection['user'],
            $connection['password'], $connection['vhost'],
        );
    }
}
