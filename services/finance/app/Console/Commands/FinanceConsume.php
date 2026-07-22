<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\ConsumeOutcome;
use App\Messaging\EventTopology;
use App\Messaging\OrderPaidConsumer;
use App\Messaging\RabbitMqConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Daemon consumer order.paid → catat penjualan (F5b). Mekanik AMQP saja; keputusan
 * ada di OrderPaidConsumer (biar teruji tanpa broker). Terjemahkan outcome:
 *   Ack → ack; Requeue → nack(requeue); Dead → nack(requeue=false)→DLQ.
 */
class FinanceConsume extends Command
{
    protected $signature = 'finance:consume';

    protected $description = 'Konsumsi event order.paid dari RabbitMQ dan catat penjualan (sales).';

    public function handle(OrderPaidConsumer $consumer): int
    {
        $t = config('rabbitmq.topology');
        $connection = RabbitMqConnection::open();
        $channel = $connection->channel();

        // Infra bersama (exchange + DLX) harus ada dulu, lalu queue Finance yang
        // menunjuk ke DLX kalau pesan ditolak permanen.
        EventTopology::assertTopology($channel);
        $channel->queue_declare(
            $t['queue'], false, true, false, false, false,
            new AMQPTable(['x-dead-letter-exchange' => $t['dlx']]),
        );
        $channel->queue_bind($t['queue'], $t['exchange'], $t['routing_key']);

        // Satu pesan diproses tuntas sebelum ambil berikutnya (catat penjualan = jalur uang).
        $channel->basic_qos(null, (int) config('rabbitmq.consume.prefetch', 10), null);

        $channel->basic_consume(
            $t['queue'], '', false, false, false, false,
            function (AMQPMessage $message) use ($consumer): void {
                $this->dispatch($consumer, $message);
            },
        );

        $this->info("finance:consume mendengarkan queue '{$t['queue']}' (Ctrl+C untuk berhenti).");

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();

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

        // Jaring pengaman: apa pun yang lolos dari handle() (mis. TypeError tak
        // terduga) TAK BOLEH menumbangkan daemon — kalau mati, seluruh pencatatan
        // penjualan berhenti sampai ada intervensi. Buang ke DLQ, lanjut hidup.
        try {
            match ($consumer->handle($body)) {
                ConsumeOutcome::Ack => $message->ack(),
                ConsumeOutcome::Requeue => $message->nack(true),
                ConsumeOutcome::Dead => $message->nack(false),
            };
        } catch (Throwable $e) {
            Log::error("finance.consume: exception tak terduga → DLQ: {$e->getMessage()}");
            $message->nack(false);
        }
    }
}
