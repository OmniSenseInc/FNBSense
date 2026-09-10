<?php

namespace Tests\Feature;

use App\Messaging\OutboxRelay;
use App\Models\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\TestCase;

/**
 * Langkah 5 (F3a): test bergigi OutboxRelay dengan channel PALSU (tanpa broker).
 *
 * Invarian yang dijaga (docs/REALTIME.md):
 *  - publish pakai routing_key = event_type, body = payload amplop utuh;
 *  - published_at diisi HANYA setelah confirm (wait_for_pending_acks) sukses;
 *  - confirm gagal → baris tetap null (dicoba lagi nanti);
 *  - baris yang sudah terkirim tak dikirim ulang.
 */
class OutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    private const EXCHANGE = 'fnbsense.events';

    /** Nack handler yang dipasang relay ke channel — ditangkap supaya bisa dipicu manual. */
    private $nackHandler;

    /**
     * Channel palsu. Relay memasang nack handler di constructor, jadi tiap mock
     * harus mengizinkannya; sekalian ditangkap untuk meniru broker yang menolak.
     *
     * @return AMQPChannel&\Mockery\MockInterface
     */
    private function mockChannel()
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('set_nack_handler')
            ->once()
            ->andReturnUsing(function (callable $handler): void {
                $this->nackHandler = $handler;
            });

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOutbox(array $overrides = []): Outbox
    {
        $eventId = (string) Str::uuid();

        return Outbox::create(array_merge([
            'aggregate_type' => 'order',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'order.paid',
            'payload' => [
                'event_id' => $eventId,
                'event_type' => 'order.paid',
                'tenant_id' => (string) Str::uuid(),
                'outlet_id' => (string) Str::uuid(),
                'payload' => ['order_id' => (string) Str::uuid()],
            ],
            'occurred_at' => now(),
            'published_at' => null,
        ], $overrides));
    }

    public function test_publish_pakai_routing_key_event_type_lalu_tandai_terkirim(): void
    {
        $row = $this->makeOutbox(['event_type' => 'order.paid']);

        $channel = $this->mockChannel();
        $channel->shouldReceive('basic_publish')
            ->once()
            ->with(Mockery::type(AMQPMessage::class), self::EXCHANGE, 'order.paid');
        $channel->shouldReceive('wait_for_pending_acks')->once();

        $relayed = (new OutboxRelay($channel, self::EXCHANGE))->flushBatch(10);

        $this->assertSame(1, $relayed);
        $this->assertNotNull($row->fresh()->published_at);
    }

    public function test_body_pesan_adalah_payload_json_utuh(): void
    {
        $row = $this->makeOutbox();
        $capturedBody = null;

        $channel = $this->mockChannel();
        $channel->shouldReceive('basic_publish')
            ->once()
            ->with(
                Mockery::on(function (AMQPMessage $message) use (&$capturedBody) {
                    $capturedBody = $message->getBody();

                    return true;
                }),
                self::EXCHANGE,
                'order.paid',
            );
        $channel->shouldReceive('wait_for_pending_acks')->once();

        (new OutboxRelay($channel, self::EXCHANGE))->flushBatch(10);

        // Bandingkan hasil decode, bukan string mentah: kolom JSON MySQL boleh
        // menata ulang urutan key — yang penting isinya identik.
        $this->assertEquals($row->fresh()->payload, json_decode((string) $capturedBody, true));
    }

    public function test_confirm_gagal_baris_tetap_null(): void
    {
        $row = $this->makeOutbox();

        $channel = $this->mockChannel();
        $channel->shouldReceive('basic_publish')->once();
        $channel->shouldReceive('wait_for_pending_acks')
            ->once()
            ->andThrow(new \RuntimeException('broker nack'));

        try {
            (new OutboxRelay($channel, self::EXCHANGE))->flushBatch(10);
        } catch (\RuntimeException) {
            // diharapkan naik
        }

        // Kalau save() dibalik ke SEBELUM confirm, baris ini akan terisi → test merah.
        $this->assertNull($row->fresh()->published_at);
    }

    /**
     * Broker MENOLAK (nack) event → baris TIDAK boleh ditandai terkirim.
     * Tanpa set_nack_handler, php-amqplib membuang pesan ter-nack diam-diam dan
     * wait_for_pending_acks() balik normal → order.paid hilang permanen tanpa jejak.
     * (mutasi: hapus set_nack_handler di constructor OutboxRelay → test ini merah)
     */
    public function test_broker_nack_baris_tetap_null(): void
    {
        $row = $this->makeOutbox();

        $channel = $this->mockChannel();
        $channel->shouldReceive('basic_publish')->once();
        // Tiru broker: yang datang saat menunggu confirm justru nack, yang di
        // php-amqplib sungguhan berarti handler terpasang dipanggil.
        $channel->shouldReceive('wait_for_pending_acks')
            ->once()
            ->andReturnUsing(fn () => ($this->nackHandler)(new AMQPMessage('x')));

        try {
            (new OutboxRelay($channel, self::EXCHANGE))->flushBatch(10);
            $this->fail('nack broker harus melempar, bukan dianggap terkirim');
        } catch (\RuntimeException) {
            // diharapkan naik
        }

        $this->assertNull($row->fresh()->published_at); // dicoba lagi pass berikut
    }

    public function test_baris_sudah_terkirim_tak_dikirim_ulang(): void
    {
        $this->makeOutbox(['published_at' => now()]);

        $channel = $this->mockChannel();
        $channel->shouldReceive('basic_publish')->never();
        $channel->shouldReceive('wait_for_pending_acks')->never();

        $relayed = (new OutboxRelay($channel, self::EXCHANGE))->flushBatch(10);

        $this->assertSame(0, $relayed);
    }

    public function test_batch_dibatasi_limit(): void
    {
        $this->makeOutbox();
        $this->makeOutbox();
        $this->makeOutbox();

        $channel = $this->mockChannel();
        $channel->shouldReceive('basic_publish')->twice();
        $channel->shouldReceive('wait_for_pending_acks')->twice();

        $relayed = (new OutboxRelay($channel, self::EXCHANGE))->flushBatch(2);

        $this->assertSame(2, $relayed);
        $this->assertSame(1, Outbox::whereNull('published_at')->count());
    }

    /**
     * REGRESI head-of-line blocking: baris PERTAMA gagal publish (broker mati/
     * nack) TIDAK boleh menggagalkan baris kedua yang bisa terkirim. Sebelum
     * perbaikan, exception dari baris pertama membatalkan seluruh flushBatch —
     * pass berikutnya menarik batch yang sama, gagal lagi di baris yang sama,
     * dan seluruh aliran event tersumbat di belakang satu baris bermasalah.
     * (mutasi: hapus try/catch di flushBatch → test ini merah)
     */
    public function test_baris_gagal_tak_memblokir_baris_lain(): void
    {
        $gagal = $this->makeOutbox();  // tertua → dicoba lebih dulu → gagal
        $sukses = $this->makeOutbox();

        $channel = $this->mockChannel();
        $channel->shouldReceive('basic_publish')->twice();
        // Confirm baris PERTAMA melempar (broker menolak), yang KEDUA sukses.
        $channel->shouldReceive('wait_for_pending_acks')
            ->once()
            ->andThrow(new \RuntimeException('broker down (simulasi)'));
        $channel->shouldReceive('wait_for_pending_acks')->once();

        $relayed = (new OutboxRelay($channel, self::EXCHANGE))->flushBatch(10);

        $this->assertSame(1, $relayed);                                   // baris kedua tetap terkirim
        $this->assertNull($gagal->fresh()->published_at);                 // yang gagal: dicoba lagi nanti
        $this->assertNotNull($sukses->fresh()->published_at);             // yang sehat: jalan
    }
}
