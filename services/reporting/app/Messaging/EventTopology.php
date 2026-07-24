<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;

class EventTopology
{
    public static function assertTopology(AMQPChannel $channel): void
    {
        $topology = config('rabbitmq.topology');
        $channel->exchange_declare($topology['dlx'], 'fanout', false, true, false);
        $channel->queue_declare($topology['dead_queue'], false, true, false, false);
        $channel->queue_bind($topology['dead_queue'], $topology['dlx']);
        $channel->exchange_declare($topology['exchange'], 'topic', false, true, false);
    }
}
