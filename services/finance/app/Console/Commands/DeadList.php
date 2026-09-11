<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\EventTopology;
use App\Messaging\RabbitMqConnection;
use Illuminate\Console\Command;

/**
 * Intip antrean mati (fnbsense.dead) TANPA mengubahnya. Pesan yang ditolak
 * permanen oleh consumer (malformed / tak bisa diproses) mendarat di sini dan
 * selama ini hanya meninggalkan satu baris log — penjualan bisa tak kecatat
 * tanpa seorang pun tahu. Perintah ini memberi mata: berapa banyak yang
 * menumpuk dan isinya apa, sebelum diputuskan mau diputar ulang (dead:replay).
 */
class DeadList extends Command
{
    protected $signature = 'dead:list {--limit=10 : berapa isi yang ditampilkan}';

    protected $description = 'Tampilkan isi antrean mati tanpa mengubahnya';

    public function handle(): int
    {
        $t = config('rabbitmq.topology');
        $connection = RabbitMqConnection::open();
        $channel = $connection->channel();

        EventTopology::assertTopology($channel);
        [, $count] = $channel->queue_declare($t['dead_queue'], false, true, false, false);

        $this->info("Antrean mati '{$t['dead_queue']}': {$count} pesan.");

        $limit = (int) $this->option('limit');
        for ($i = 0; $i < min($count, $limit); $i++) {
            $message = $channel->basic_get($t['dead_queue']);
            if ($message === null) {
                break;
            }

            $this->line($this->preview($message->getBody()));

            // Kembalikan ke antrean — dead:list hanya MEMBACA.
            $channel->basic_nack($message->getDeliveryTag(), false, true);
        }

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    private function preview(string $raw): string
    {
        $body = json_decode($raw, true);
        if (! is_array($body)) {
            return '  - [JSON rusak] '.substr($raw, 0, 80);
        }

        $type = $body['event_type'] ?? '?';
        $orderId = $body['payload']['order_id'] ?? ($body['order_id'] ?? '?');

        return "  - {$type} (order {$orderId})";
    }
}
