<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\ConsumeOutcome;
use App\Messaging\EventTopology;
use App\Messaging\NotificationConsumer;
use App\Messaging\RabbitMqConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class NotificationConsume extends Command
{
    protected $signature = 'notification:consume';

    protected $description = 'Konsumsi alarm inventory.* dan bentuk notifikasi inbox.';

    public function handle(NotificationConsumer $consumer): int
    {
        $topology = config('rabbitmq.topology');
        $connection = RabbitMqConnection::open();
        $channel = $connection->channel();
        EventTopology::assertTopology($channel);
        $channel->queue_declare(
            $topology['queue'], false, true, false, false, false,
            new AMQPTable(['x-dead-letter-exchange' => $topology['dlx']]),
        );
        foreach ($topology['routing_keys'] as $key) {
            $channel->queue_bind($topology['queue'], $topology['exchange'], $key);
        }
        $channel->basic_qos(null, (int) config('rabbitmq.consume.prefetch', 10), null);
        $channel->basic_consume(
            $topology['queue'], '', false, false, false, false,
            fn (AMQPMessage $message) => $this->dispatch($consumer, $message),
        );
        $this->info("notification:consume mendengarkan queue '{$topology['queue']}'.");
        while ($channel->is_consuming()) {
            $channel->wait();
        }
        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    private function dispatch(NotificationConsumer $consumer, AMQPMessage $message): void
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
            Log::error("notification.consume: exception tak terduga -> DLQ: {$exception->getMessage()}");
            $message->nack(false);
        }
    }
}
