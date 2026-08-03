<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Messaging\ConsumeOutcome;
use App\Messaging\NotificationConsumer;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockAlertConsumerTest extends TestCase
{
    use RefreshDatabase;

    private function envelope(string $type, array $payload): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'occurred_at' => '2026-07-24T03:00:00Z',
            'tenant_id' => (string) Str::uuid(),
            'outlet_id' => (string) Str::uuid(),
            'payload' => $payload,
        ];
    }

    private function consume(array $envelope): ConsumeOutcome
    {
        return app(NotificationConsumer::class)->handle($envelope);
    }

    /** Payload pembatalan yang sah, boleh ditimpa per test. */
    private function batal(array $overrides = []): array
    {
        return $this->envelope('order.cancelled', array_merge([
            'order_id' => 'ORD-9',
            'order_number' => 'A-012',
            'grand_total' => 81_200,
            'reason' => 'Pelanggan pergi',
            'cancelled_by' => (string) Str::uuid(),
        ], $overrides));
    }

    /**
     * Pembatalan hanya untuk owner, dan nominalnya ikut.
     *
     * Notifikasi ini melaporkan tindakan KASIR — orang yang dilaporkan tak
     * boleh ikut membacanya. Dan tanpa nominal, owner tak bisa membedakan
     * segelas kopi salah pesan dari rombongan dua juta yang batal.
     */
    public function test_pembatalan_jadi_notifikasi_owner_bernominal(): void
    {
        $this->assertSame(ConsumeOutcome::Ack, $this->consume($this->batal()));

        $notif = Notification::firstOrFail();

        $this->assertSame('order_cancelled', $notif->type);
        $this->assertSame('warning', $notif->severity);
        $this->assertSame(Notification::UNTUK_OWNER, $notif->audience);
        $this->assertStringContainsString('A-012', $notif->body);
        $this->assertStringContainsString('81.200', $notif->body);
        $this->assertStringContainsString('Pelanggan pergi', $notif->body);
    }

    /** Kasir boleh membatalkan tanpa menulis alasan — bodynya harus tetap waras. */
    public function test_pembatalan_tanpa_alasan_tetap_terbaca(): void
    {
        $this->assertSame(ConsumeOutcome::Ack, $this->consume($this->batal(['reason' => null])));

        $this->assertStringContainsString('Tanpa alasan tertulis', Notification::firstOrFail()->body);
    }

    /**
     * Nominal rusak dibuang ke DLQ, bukan dicetak apa adanya.
     *
     * Non-numerik meledak di number_format dan menjatuhkan daemon; negatif
     * tercetak sebagai "Rp -81.200" di inbox owner. Dua-duanya lolos kalau yang
     * diperiksa cuma keberadaan kuncinya.
     */
    public function test_nominal_rusak_ditolak(): void
    {
        $rusakSemua = [
            ['grand_total' => 'delapan puluh ribu'],
            ['grand_total' => -1],
            ['grand_total' => null],
        ];

        foreach ($rusakSemua as $rusak) {
            $this->assertSame(ConsumeOutcome::Dead, $this->consume($this->batal($rusak)));
        }

        $this->assertSame(0, Notification::count());
    }

    public function test_low_stock_jadi_notifikasi_warning(): void
    {
        $env = $this->envelope('inventory.low_stock', [
            'order_id' => 'ORD-1',
            'items' => [['ingredient_id' => (string) Str::uuid(), 'on_hand_after' => 5, 'min_stock' => 10]],
        ]);

        $this->assertSame(ConsumeOutcome::Ack, $this->consume($env));
        $this->assertDatabaseCount('notifications', 1);
        $notif = Notification::first();
        $this->assertSame('low_stock', $notif->type);
        $this->assertSame('warning', $notif->severity);
        $this->assertSame($env['outlet_id'], $notif->outlet_id);
        // == bukan ===: JSON column MySQL tak menjamin urutan key, hanya isi yang penting.
        $this->assertEquals($env['payload'], $notif->payload);
    }

    public function test_shortfall_jadi_notifikasi_critical(): void
    {
        $env = $this->envelope('inventory.shortfall', [
            'order_id' => 'ORD-2',
            'items' => [['ingredient_id' => (string) Str::uuid(), 'needed' => 3, 'on_hand_after' => -2]],
        ]);

        $this->assertSame(ConsumeOutcome::Ack, $this->consume($env));
        $this->assertSame('critical', Notification::first()->severity);
    }

    public function test_recipe_missing_jadi_notifikasi_info(): void
    {
        $env = $this->envelope('inventory.recipe_missing', [
            'order_id' => 'ORD-3',
            'product_ids' => [(string) Str::uuid()],
        ]);

        $this->assertSame(ConsumeOutcome::Ack, $this->consume($env));
        $this->assertSame('recipe_missing', Notification::first()->type);
    }

    public function test_replay_event_sama_tak_dobel(): void
    {
        $env = $this->envelope('inventory.shortfall', [
            'order_id' => 'ORD-4',
            'items' => [['ingredient_id' => (string) Str::uuid(), 'needed' => 1, 'on_hand_after' => -1]],
        ]);

        $this->consume($env);
        $this->assertSame(ConsumeOutcome::Ack, $this->consume($env));
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('processed_events', 1);
    }

    public function test_amplop_malformed_ke_dead_tanpa_notif(): void
    {
        $env = $this->envelope('inventory.low_stock', ['order_id' => 'ORD-5', 'items' => []]);

        $this->assertSame(ConsumeOutcome::Dead, $this->consume($env));
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_event_type_asing_ke_dead(): void
    {
        $env = $this->envelope('order.paid', [
            'order_id' => 'ORD-6',
            'items' => [['ingredient_id' => (string) Str::uuid()]],
        ]);

        $this->assertSame(ConsumeOutcome::Dead, $this->consume($env));
        $this->assertDatabaseCount('notifications', 0);
    }
}
