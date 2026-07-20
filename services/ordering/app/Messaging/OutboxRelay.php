<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Models\Outbox;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * Inti relay (F3a) — dipisah dari command supaya bisa diuji dengan channel palsu
 * (tanpa broker). Kontrak perilaku ada di docs/REALTIME.md.
 *
 * Invarian utama: published_at diisi HANYA setelah broker meng-confirm terima.
 * Kalau publish/confirm gagal, exception naik SEBELUM save() → baris tetap null
 * → dicoba lagi pass berikut (at-least-once; consumer buang duplikat by event_id).
 */
class OutboxRelay
{
    // Detik menunggu publisher-confirm broker per pesan sebelum menyerah.
    private const CONFIRM_TIMEOUT = 5.0;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly string $exchange,
    ) {
        // WAJIB: tanpa handler ini php-amqplib membuang pesan ter-nack DIAM-DIAM
        // (internal_ack_handler dipanggil dengan handler null) dan
        // wait_for_pending_acks() balik seolah sukses → published_at terisi untuk
        // event yang broker TOLAK. Akibatnya order.paid hilang permanen: stok tak
        // terpotong, KDS tak dapat tiket, nol jejak di log. Jadikan exception supaya
        // baris tetap null & dicoba lagi pass berikut.
        $this->channel->set_nack_handler(static function (): void {
            throw new RuntimeException('broker menolak (nack) event outbox — tak dianggap terkirim');
        });
    }

    /**
     * Angkat SATU batch baris belum-terkirim (tertua dulu) → publish → tandai.
     *
     * @return int jumlah baris yang berhasil di-relay
     */
    public function flushBatch(int $limit): int
    {
        $rows = Outbox::query()
            ->whereNull('published_at')
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get();

        $relayed = 0;
        foreach ($rows as $row) {
            $this->publish($row);
            // Baris ini hanya tercapai kalau publish() tidak melempar (broker sudah
            // confirm). Urutan publish→confirm→save tak boleh dibalik.
            $row->forceFill(['published_at' => now()])->save();
            $relayed++;
        }

        return $relayed;
    }

    private function publish(Outbox $row): void
    {
        // payload di-cast 'array' oleh model → encode ulang ke JSON amplop utuh.
        $message = new AMQPMessage(
            (string) json_encode($row->payload),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, // tahan restart broker
            ],
        );

        // routing_key = event_type ("order.paid") → consumer bind pola yang diminati.
        $this->channel->basic_publish($message, $this->exchange, $row->event_type);

        // Blokir sampai broker ack. Nack/timeout → lempar → save() tak tercapai.
        $this->channel->wait_for_pending_acks(self::CONFIRM_TIMEOUT);
    }
}
