<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\EventTopology;
use App\Messaging\OutboxRelay;
use App\Messaging\RabbitMqConnection;
use Illuminate\Console\Command;

/**
 * Daemon relay outbox → RabbitMQ (F3a). Default: loop terus (dikelola
 * Supervisor/pm2 di produksi). --once: satu pass lalu keluar (test/fallback cron).
 *
 * Command sengaja tipis: orkestrasi koneksi + loop saja. Logika publish/confirm
 * ada di OutboxRelay (yang bisa diuji tanpa broker).
 */
class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay {--once : Satu pass batch lalu keluar}';

    protected $description = 'Relay baris outbox belum-terkirim ke RabbitMQ (F3a).';

    public function handle(): int
    {
        $batchSize = (int) config('rabbitmq.relay.batch_size');
        $sleepSeconds = (int) config('rabbitmq.relay.sleep_seconds');
        $exchange = config('rabbitmq.topology.exchange');
        $once = (bool) $this->option('once');

        $connection = RabbitMqConnection::open();
        $channel = $connection->channel();
        $channel->confirm_select();               // aktifkan publisher confirms
        EventTopology::assertTopology($channel);   // idempoten

        $relay = new OutboxRelay($channel, $exchange);

        try {
            do {
                $count = $relay->flushBatch($batchSize);

                if ($count > 0) {
                    $this->info("outbox:relay — {$count} event terkirim.");
                }

                if ($once) {
                    break;
                }

                // Backlog kosong → jeda. Ada isi → langsung loop lagi (drain).
                if ($count === 0) {
                    sleep($sleepSeconds);
                }
            } while (true);
        } finally {
            $channel->close();
            $connection->close();
        }

        return self::SUCCESS;
    }
}
