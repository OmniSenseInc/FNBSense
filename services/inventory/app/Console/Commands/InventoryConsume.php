<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\ConsumeOutcome;
use App\Messaging\EventPublisher;
use App\Messaging\EventTopology;
use App\Messaging\OrderPaidConsumer;
use App\Messaging\RabbitMqConnection;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Daemon consumer order.paid → potong stok (F4b). Mekanik AMQP saja; keputusan
 * ada di OrderPaidConsumer (biar teruji tanpa broker). Terjemahkan outcome:
 *   Ack → ack; Requeue → nack(requeue); Dead → nack(requeue=false)→DLQ.
 */
class InventoryConsume extends Command
{
    protected $signature = 'inventory:consume';

    protected $description = 'Konsumsi event order.paid dari RabbitMQ dan potong stok sesuai resep Catalog.';

    public function handle(OrderPaidConsumer $consumer): int
    {
        $t = config('rabbitmq.topology');
        $connection = RabbitMqConnection::open();
        $channel = $connection->channel();

        // Infra bersama (exchange + DLX) harus ada dulu, lalu queue Inventory yang
        // menunjuk ke DLX kalau pesan ditolak permanen.
        EventTopology::assertTopology($channel);
        $channel->queue_declare(
            $t['queue'], false, true, false, false, false,
            new AMQPTable(['x-dead-letter-exchange' => $t['dlx']]),
        );
        $channel->queue_bind($t['queue'], $t['exchange'], $t['routing_key']);

        // Satu pesan diproses tuntas sebelum ambil berikutnya (potong stok = jalur uang).
        $channel->basic_qos(null, (int) config('rabbitmq.consume.prefetch', 10), null);

        $channel->basic_consume(
            $t['queue'], '', false, false, false, false,
            function (AMQPMessage $message) use ($consumer): void {
                $this->dispatch($consumer, $message);
            },
        );

        $this->info("inventory:consume mendengarkan queue '{$t['queue']}' (Ctrl+C untuk berhenti).");

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
        // Publisher punya koneksi sendiri (dibuka malas saat ada event saga) —
        // consumer menutup miliknya saja tak cukup.
        app(EventPublisher::class)->close();

        return self::SUCCESS;
    }

    private function dispatch(OrderPaidConsumer $consumer, AMQPMessage $message): void
    {
        $body = json_decode($message->getBody(), true);

        // JSON rusak = tak bisa diproses ulang → langsung DLQ (jangan requeue selamanya).
        if (! is_array($body)) {
            $message->nack(false);

            return;
        }

        match ($consumer->handle($body)) {
            ConsumeOutcome::Ack => $message->ack(),
            ConsumeOutcome::Requeue => $message->nack(true),
            ConsumeOutcome::Dead => $message->nack(false),
        };
    }
}
