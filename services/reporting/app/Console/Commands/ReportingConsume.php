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

class ReportingConsume extends Command
{
    protected $signature = 'reporting:consume';

    protected $description = 'Konsumsi order.paid dan bentuk read-model analytics.';

    public function handle(OrderPaidConsumer $consumer): int
    {
        $topology = config('rabbitmq.topology');
        $connection = RabbitMqConnection::open();
        $channel = $connection->channel();
        EventTopology::assertTopology($channel);
        $channel->queue_declare(
            $topology['queue'], false, true, false, false, false,
            new AMQPTable(['x-dead-letter-exchange' => $topology['dlx']]),
        );
        $channel->queue_bind($topology['queue'], $topology['exchange'], $topology['routing_key']);
        $channel->basic_qos(null, (int) config('rabbitmq.consume.prefetch', 10), null);
        $channel->basic_consume(
            $topology['queue'], '', false, false, false, false,
            fn (AMQPMessage $message) => $this->dispatch($consumer, $message),
        );
        $this->info("reporting:consume mendengarkan queue '{$topology['queue']}'.");
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
        if (! is_array($body)) {
            $message->nack(false);

            return;
        }
        try {
            match ($consumer->handle($body)) {
                ConsumeOutcome::Ack => $message->ack(),
                ConsumeOutcome::Requeue => $message->nack(true),
                ConsumeOutcome::Dead => $message->nack(false),
            };
        } catch (Throwable $exception) {
            Log::error("reporting.consume: exception tak terduga → DLQ: {$exception->getMessage()}");
            $message->nack(false);
        }
    }
}
