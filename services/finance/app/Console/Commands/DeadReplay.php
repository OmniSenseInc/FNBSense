<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\EventTopology;
use App\Messaging\RabbitMqConnection;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Putar ulang pesan dari antrean mati (fnbsense.dead) ke exchange utama,
 * routing key = event_type (sama persis dengan relay). Ditujukan untuk
 * pemulihan manual SETELAH akar masalah diperbaiki — bukan jalan otomatis:
 * memutar ulang pesan yang masih rusak cuma mengirimnya kembali ke antrean mati.
 *
 * Aman diputar ulang berkali-kali: consumer jalur uang (finance, inventory)
 * idempoten lewat processed_orders, jadi pesan duplikat tak menimbulkan
 * double-charge atau double-potong-stok. Pesan yang tak bisa ditentukan rutenya
 * (JSON rusak tanpa event_type) dikembalikan dan loop dihentikan, supaya
 * perintah tak berputar di pesan yang sama selamanya.
 */
class DeadReplay extends Command
{
    protected $signature = 'dead:replay {--limit=100 : maksimal pesan yang diputar ulang per jalan}';

    protected $description = 'Putar ulang pesan dari antrean mati ke exchange utama';

    public function handle(): int
    {
        $t = config('rabbitmq.topology');
        $connection = RabbitMqConnection::open();
        $channel = $connection->channel();

        EventTopology::assertTopology($channel);
        $channel->queue_declare($t['dead_queue'], false, true, false, false);

        $limit = (int) $this->option('limit');
        $replayed = 0;
        $skipped = 0;

        for ($i = 0; $i < $limit; $i++) {
            $message = $channel->basic_get($t['dead_queue']);
            if ($message === null) {
                break;
            }

            $routingKey = $this->routingKey($message->getBody());
            if ($routingKey === null) {
                $channel->basic_nack($message->getDeliveryTag(), false, true);
                $skipped++;
                break;
            }

            $channel->basic_publish(
                new AMQPMessage($message->getBody(), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]),
                $t['exchange'],
                $routingKey,
            );
            $channel->basic_ack($message->getDeliveryTag());
            $replayed++;
        }

        $this->info("dead:replay selesai — {$replayed} diputar ulang, {$skipped} dilewati.");

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    private function routingKey(string $raw): ?string
    {
        $body = json_decode($raw, true);
        if (! is_array($body)) {
            return null;
        }

        $type = $body['event_type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }
}
