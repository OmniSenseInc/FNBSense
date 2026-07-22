<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Deklarasi infra broker BERSAMA (F3a/F4b/F5b) — exchange event utama + dead-letter.
 * Semua idempoten: aman dipanggil tiap consumer start. Nama diambil dari
 * config('rabbitmq.topology') supaya relay & consumer merujuk satu sumber.
 *
 * DLX & dead queue di-assert DULU: harus sudah eksis sebelum queue consumer
 * (mis. finance.sales) menunjuk ke sana lewat x-dead-letter-exchange.
 */
class EventTopology
{
    public static function assertTopology(AMQPChannel $channel): void
    {
        $t = config('rabbitmq.topology');

        // Dead-letter: exchange fanout + satu dead queue terikat padanya.
        // args exchange_declare: name, type, passive=false, durable=true, autoDelete=false
        $channel->exchange_declare($t['dlx'], 'fanout', false, true, false);
        // args queue_declare: name, passive=false, durable=true, exclusive=false, autoDelete=false
        $channel->queue_declare($t['dead_queue'], false, true, false, false);
        $channel->queue_bind($t['dead_queue'], $t['dlx']);

        // Exchange event utama (topic) — routing_key = event_type saat publish.
        $channel->exchange_declare($t['exchange'], 'topic', false, true, false);
    }
}
