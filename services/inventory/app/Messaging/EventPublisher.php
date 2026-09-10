<?php

declare(strict_types=1);

namespace App\Messaging;

use Illuminate\Support\Str;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Throwable;

/**
 * Penerbit event saga Inventory (F4c) — inventory.shortfall / recipe_missing /
 * low_stock. Publish LANGSUNG ke exchange (bukan outbox seperti Ordering):
 * event saga = sinyal alert, bukan invarian uang. Kalau hilang karena crash
 * tepat di sela commit & publish, status tetap terekam di processed_orders +
 * saldo, jadi bisa direkonsiliasi lewat query. Outbox = biaya tabel + daemon
 * kedua yang belum sepadan; naikkan kalau F8 butuh garansi kirim.
 *
 * Koneksi dibuka MALAS: order normal (mayoritas) tak menerbitkan apa pun, jadi
 * jangan bayar koneksi broker untuk kasus yang tak terjadi.
 */
class EventPublisher
{
    // Detik menunggu publisher-confirm broker sebelum menyerah (sama seperti relay Ordering).
    private const CONFIRM_TIMEOUT = 5.0;

    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /**
     * Bungkus payload jadi amplop standar lalu terbitkan. routing_key = event_type,
     * sama seperti relay Ordering, supaya consumer bind pola yang diminatinya.
     *
     * Melempar kalau broker tak meng-confirm (ack tak datang, nack, timeout, atau
     * koneksi putus). Pemanggil yang memutuskan artinya — di consumer, potong stok
     * sudah commit, jadi gagal terbit dicatat sebagai error, bukan di-rollback.
     *
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $eventType, string $tenantId, string $outletId, array $payload): void
    {
        $envelope = [
            'event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'occurred_at' => now()->toIso8601String(),
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'payload' => $payload,
        ];

        $channel = $this->channel();

        try {
            $channel->basic_publish(
                new AMQPMessage(
                    (string) json_encode($envelope),
                    [
                        'content_type' => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, // tahan restart broker
                    ],
                ),
                (string) config('rabbitmq.topology.exchange'),
                $eventType,
            );

            // Blokir sampai broker menjawab. Nack ditangkap lewat handler yang
            // dipasang di channel() — tanpa itu php-amqplib membuang pesan ter-nack
            // diam-diam dan method ini balik seolah sukses.
            $channel->wait_for_pending_acks(self::CONFIRM_TIMEOUT);

            // Tutup SEKARANG, bukan "simpan untuk dipakai ulang". Event saga itu
            // JARANG — bisa berjeda berjam-jam atau berhari-hari — dan koneksi yang
            // dipegang sambil nganggur itu racun: php-amqplib cuma memproses
            // heartbeat saat ada pembacaan (wait), dan publisher malas ini tak
            // pernah membaca di sela event. Setelah nganggur lebih dari 2×heartbeat
            // (120 detik), broker menganggap koneksinya mati, dan publish berikutnya
            // ke koneksi basi itu lempar "Missed server heartbeat" — event saga
            // HILANG (tercatat di log produksi sebagai error). Menutup di sini
            // membuat event berikutnya selalu membuka koneksi segar; biaya
            // menyambung ulang untuk event yang jarang itu nol dari sisi praktis.
            $this->discard();
        } catch (Throwable $e) {
            // Channel/koneksi kemungkinan sudah rusak (broker restart, blip jaringan,
            // protocol error). Buang supaya publish BERIKUTNYA menyambung ulang.
            // Tanpa ini, satu kegagalan mematikan seluruh alert seumur hidup daemon
            // (yang bisa berhari-hari) sampai ada yang me-restart manual.
            $this->discard();

            throw $e;
        }
    }

    /** Tutup koneksi kalau sempat dibuka. Dipanggil saat daemon berhenti. */
    public function close(): void
    {
        $this->discard();
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel === null) {
            $this->connection = RabbitMqConnection::open();
            $this->channel = $this->connection->channel();
            $this->channel->confirm_select();              // aktifkan publisher confirms
            EventTopology::assertTopology($this->channel); // idempoten

            // Broker menolak pesan → jadikan exception. Body sengaja TAK ikut di pesan
            // error (minim PII); identitas event bisa dilacak dari log pemanggil.
            $this->channel->set_nack_handler(static function (AMQPMessage $message): void {
                throw new RuntimeException(
                    'broker nack event saga (exchange menolak / tak bisa persist)',
                );
            });
        }

        return $this->channel;
    }

    /** Tutup & lupakan koneksi. Kegagalan saat menutup diabaikan — sudah rusak. */
    private function discard(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (Throwable) {
            // Menutup channel yang sudah mati wajar melempar; tak ada yang perlu diselamatkan.
        }

        $this->channel = null;
        $this->connection = null;
    }
}
