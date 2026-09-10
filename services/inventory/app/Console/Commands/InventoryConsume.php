<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\ConsumeOutcome;
use App\Messaging\EventPublisher;
use App\Messaging\EventTopology;
use App\Messaging\OrderPaidConsumer;
use App\Messaging\RabbitMqConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Daemon consumer order.paid → potong stok (F4b). Mekanik AMQP saja; keputusan
 * ada di OrderPaidConsumer (biar teruji tanpa broker). Terjemahkan outcome:
 *   Ack → ack; Requeue → nack(requeue); Dead → nack(requeue=false)→DLQ.
 *
 * Dua pertahanan hidup-atasan di sini (pola yang sama dengan FinanceConsume):
 *  1. Jaring Throwable — exception yang lolos dari handle() (mis. TypeError
 *     tak terduga) TIDAK BOLEH menumbangkan daemon: kalau mati, Supervisor
 *     menghidupkan ulang, pesan yang sama diterima lagi, meledak lagi — loop
 *     mati-nyala tanpa ujung sementara stok berhenti terpotong. Buang ke DLQ,
 *     lanjut hidup.
 *  2. Jeda setelah requeue — nack(requeue) membuat broker MENGIRIM ULANG pesan
 *     hampir seketika. Tanpa jeda, Catalog down (atau DB galat) berarti loop
 *     panas: retry tanpa henti yang menghajar Catalog dan CPU. Jeda memberi
 *     waktu layanan sehat kembali.
 */
class InventoryConsume extends Command
{
    /** Lama menunggu tiap putaran sebelum mengecek ulang. */
    private const TUNGGU_DETIK = 5;

    /** Jeda (detik) setelah requeue — anti loop-panas saat layanan hilir sedang down. */
    private const JEDA_REQUEUE_DETIK = 3;

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
            try {
                $channel->wait(null, false, self::TUNGGU_DETIK);
            } catch (AMQPTimeoutException) {
                // Antrean sepi adalah keadaan NORMAL. Tanpa tangkapan ini
                // daemon mati sendiri begitu tak ada pembayaran selama
                // beberapa detik — dan sejak itu stok berhenti terpotong tanpa
                // satu pun tanda di layar mana pun.
                continue;
            }
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

        try {
            match ($consumer->handle($body)) {
                ConsumeOutcome::Ack => $message->ack(),
                ConsumeOutcome::Requeue => $this->requeueDenganJeda($message),
                ConsumeOutcome::Dead => $message->nack(false),
            };
        } catch (Throwable $e) {
            // Jaring pengaman terakhir: apa pun yang lolos dari handle() TIDAK
            // boleh menumbangkan daemon (lihat komentar kelas). Buang ke DLQ —
            // jejaknya ada, kasir tidak berhenti bekerja, stok menyusul lewat
            // intervensi manual atas pesan yang terkatig-katig ini.
            Log::error("inventory.consume: exception tak terduga → DLQ: {$e->getMessage()}");
            $message->nack(false);
        }
    }

    /** Requeue + jeda: beri jarak sebelum broker mengirim ulang pesan yang sama. */
    private function requeueDenganJeda(AMQPMessage $message): void
    {
        sleep(self::JEDA_REQUEUE_DETIK);
        $message->nack(true);
    }
}
