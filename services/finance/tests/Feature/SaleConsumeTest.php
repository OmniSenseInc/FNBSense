<?php

namespace Tests\Feature;

use App\Messaging\ConsumeOutcome;
use App\Messaging\OrderPaidConsumer;
use App\Models\ProcessedOrder;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test inti consumer order.paid (F5b) — tanpa broker. Finance cuma mencatat:
 * salin uang dari amplop apa adanya. Fokus: catat sesuai amplop, idempoten
 * (dua pagar: dedup cepat + sales.order_id unique), amplop rusak/uang tak
 * konsisten ke DLQ, line_total dihitung ulang & dikunci CHECK.
 */
class SaleConsumeTest extends TestCase
{
    use RefreshDatabase;

    private function consumer(): OrderPaidConsumer
    {
        return app(OrderPaidConsumer::class);
    }

    /**
     * @param  array{subtotal:int,service_charge:int,tax:int,grand_total:int}  $totals
     * @param  array<int, array{product_id:string,qty:int,unit_price:int}>  $items
     */
    private function envelope(
        string $tenant,
        string $outlet,
        string $orderId,
        array $totals,
        array $items,
        string $payment = 'cash',
        ?array $promotion = null,
    ): array {
        $payload = [
            'order_id' => $orderId,
            'payment_method' => $payment,
            'totals' => $totals,
            'items' => $items,
        ];
        if ($promotion !== null) {
            $payload['promotion'] = $promotion;
        }

        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'order.paid',
            'occurred_at' => now()->toIso8601String(),
            'tenant_id' => $tenant,
            'outlet_id' => $outlet,
            'payload' => $payload,
        ];
    }

    /** Happy path: 1 baris sales dgn uang persis amplop + processed_orders recorded. */
    public function test_catat_penjualan_dari_amplop(): void
    {
        [$tenant, $outlet, $orderId, $produk] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $outcome = $this->consumer()->handle($this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => 20000, 'service_charge' => 1000, 'tax' => 2100, 'grand_total' => 23100],
            [['product_id' => $produk, 'qty' => 2, 'unit_price' => 10000]],
            'qris_static',
        ));

        $this->assertSame(ConsumeOutcome::Ack, $outcome);
        $this->assertDatabaseHas('sales', [
            'order_id' => $orderId, 'tenant_id' => $tenant, 'outlet_id' => $outlet,
            'subtotal' => 20000, 'service_charge' => 1000, 'tax' => 2100, 'grand_total' => 23100,
            'payment_method' => 'qris_static',
        ]);
        $this->assertDatabaseHas('processed_orders', ['order_id' => $orderId, 'status' => 'recorded']);
    }

    public function test_catat_diskon_dan_snapshot_promo_tanpa_mengubah_subtotal_net(): void
    {
        [$tenant, $outlet, $orderId, $produk, $promotionId] = [
            (string) Str::uuid(),
            (string) Str::uuid(),
            (string) Str::uuid(),
            (string) Str::uuid(),
            (string) Str::uuid(),
        ];

        $outcome = $this->consumer()->handle($this->envelope(
            $tenant,
            $outlet,
            $orderId,
            [
                'gross_subtotal' => 20000,
                'discount_total' => 5000,
                'subtotal' => 15000,
                'service_charge' => 750,
                'tax' => 1575,
                'grand_total' => 17325,
            ],
            [['product_id' => $produk, 'qty' => 2, 'unit_price' => 10000]],
            promotion: [
                'id' => $promotionId,
                'name' => 'Diskon Launching',
                'template' => 'order_fixed',
            ],
        ));

        $this->assertSame(ConsumeOutcome::Ack, $outcome);
        $this->assertDatabaseHas('sales', [
            'order_id' => $orderId,
            'gross_subtotal' => 20000,
            'discount_total' => 5000,
            'subtotal' => 15000,
            'promotion_id' => $promotionId,
            'promotion_name' => 'Diskon Launching',
            'promotion_template' => 'order_fixed',
        ]);
    }

    /** line_total dihitung ulang server-side (unit_price*qty), bukan disalin dari client. */
    public function test_line_total_dihitung_dari_unit_price_kali_qty(): void
    {
        [$tenant, $outlet, $orderId, $produk] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $this->consumer()->handle($this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => 15000, 'service_charge' => 0, 'tax' => 0, 'grand_total' => 15000],
            [['product_id' => $produk, 'qty' => 3, 'unit_price' => 5000]],
        ));

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $produk, 'qty' => 3, 'unit_price' => 5000, 'line_total' => 15000,
        ]);
    }

    /** Banyak item → tiap baris sale_items tertulis dgn line_total sendiri. */
    public function test_banyak_item_tercatat_semua(): void
    {
        [$tenant, $outlet, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        [$produkA, $produkB] = [(string) Str::uuid(), (string) Str::uuid()];

        $this->consumer()->handle($this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => 25000, 'service_charge' => 0, 'tax' => 0, 'grand_total' => 25000],
            [
                ['product_id' => $produkA, 'qty' => 1, 'unit_price' => 10000],
                ['product_id' => $produkB, 'qty' => 3, 'unit_price' => 5000],
            ],
        ));

        $sale = Sale::where('order_id', $orderId)->firstOrFail();
        $this->assertSame(2, SaleItem::where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('sale_items', ['product_id' => $produkA, 'line_total' => 10000]);
        $this->assertDatabaseHas('sale_items', ['product_id' => $produkB, 'line_total' => 15000]);
    }

    /** IDEMPOTEN (pagar 1: dedup cepat): event sama 2x → tetap 1 sales. */
    public function test_replay_tak_dobel_catat(): void
    {
        [$tenant, $outlet, $orderId, $produk] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $env = $this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => 10000, 'service_charge' => 0, 'tax' => 0, 'grand_total' => 10000],
            [['product_id' => $produk, 'qty' => 1, 'unit_price' => 10000]],
        );

        $this->consumer()->handle($env);
        $second = $this->consumer()->handle($env); // replay

        $this->assertSame(ConsumeOutcome::Ack, $second);
        $this->assertSame(1, Sale::where('order_id', $orderId)->count());
        $this->assertSame(1, ProcessedOrder::where('order_id', $orderId)->count());
    }

    /**
     * IDEMPOTEN (pagar 2: sales.order_id unique di DB). Walau dedup cepat dilewati
     * (baris processed_orders hilang), DB tetap menolak sales dobel → Ack, bukan crash.
     * (mutasi: buang unique di sales → test merah, uang jadi dobel)
     */
    public function test_unique_sales_menahan_dobel_saat_dedup_dilewati(): void
    {
        [$tenant, $outlet, $orderId, $produk] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $env = $this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => 10000, 'service_charge' => 0, 'tax' => 0, 'grand_total' => 10000],
            [['product_id' => $produk, 'qty' => 1, 'unit_price' => 10000]],
        );

        $this->consumer()->handle($env);
        // Simulasi pagar dedup bocor (race): hapus jejak processed_orders.
        ProcessedOrder::where('order_id', $orderId)->delete();

        $second = $this->consumer()->handle($env);

        $this->assertSame(ConsumeOutcome::Ack, $second); // DB unique → ditangkap → Ack
        $this->assertSame(1, Sale::where('order_id', $orderId)->count()); // TETAP 1
    }

    /** Amplop tak berbentuk (tanpa totals) → Dead, tak menulis apa pun, tak crash. */
    public function test_amplop_malformed_dead(): void
    {
        $bad = [
            'event_type' => 'order.paid',
            'occurred_at' => now()->toIso8601String(),
            'tenant_id' => (string) Str::uuid(),
            'outlet_id' => (string) Str::uuid(),
            'payload' => ['order_id' => (string) Str::uuid(), 'items' => []], // tanpa totals
        ];

        $this->assertSame(ConsumeOutcome::Dead, $this->consumer()->handle($bad));
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, ProcessedOrder::count());
    }

    /**
     * Uang tak konsisten (grand_total != subtotal+sc+tax) → Dead, BUKAN requeue.
     * Kalau lolos, INSERT ditolak CHECK → pesan racun requeue selamanya.
     * (mutasi: buang cek aritmatika di validEnvelope → test ini merah / berubah jadi loop)
     */
    public function test_uang_tak_konsisten_dead(): void
    {
        [$tenant, $outlet, $orderId, $produk] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $outcome = $this->consumer()->handle($this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => 20000, 'service_charge' => 1000, 'tax' => 2100, 'grand_total' => 99999], // bohong
            [['product_id' => $produk, 'qty' => 1, 'unit_price' => 20000]],
        ));

        $this->assertSame(ConsumeOutcome::Dead, $outcome);
        $this->assertSame(0, Sale::count());
    }

    /** Item qty < 1 → Dead (dilindungi CHECK qty>=1 di sale_items; tolak di gerbang). */
    public function test_item_qty_nol_dead(): void
    {
        [$tenant, $outlet, $orderId, $produk] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $outcome = $this->consumer()->handle($this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => 0, 'service_charge' => 0, 'tax' => 0, 'grand_total' => 0],
            [['product_id' => $produk, 'qty' => 0, 'unit_price' => 10000]],
        ));

        $this->assertSame(ConsumeOutcome::Dead, $outcome);
        $this->assertSame(0, Sale::count());
    }

    /**
     * Nominal NEGATIF (aritmatika konsisten) → Dead, BUKAN requeue. Kolom unsigned
     * bakal menolaknya → tanpa gerbang ini jadi pesan racun yang requeue selamanya.
     * (mutasi: buang cek `>= 0` di nonNegativeInt → test merah)
     */
    public function test_nominal_negatif_dead(): void
    {
        [$tenant, $outlet, $orderId, $produk] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $outcome = $this->consumer()->handle($this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => -1000, 'service_charge' => 0, 'tax' => 0, 'grand_total' => -1000],
            [['product_id' => $produk, 'qty' => 1, 'unit_price' => 1000]],
        ));

        $this->assertSame(ConsumeOutcome::Dead, $outcome);
        $this->assertSame(0, Sale::count());
    }

    /** Nominal PECAHAN ("20000.5") → Dead, bukan dibulatkan diam-diam (rupiah = integer). */
    public function test_nominal_pecahan_dead(): void
    {
        [$tenant, $outlet, $orderId, $produk] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $outcome = $this->consumer()->handle($this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => '20000.5', 'service_charge' => 0, 'tax' => 0, 'grand_total' => '20000.5'],
            [['product_id' => $produk, 'qty' => 1, 'unit_price' => 20000]],
        ));

        $this->assertSame(ConsumeOutcome::Dead, $outcome);
        $this->assertSame(0, Sale::count());
    }

    /** occurred_at bukan tanggal → Dead, bukan requeue (kolom timestamp bakal menolak). */
    public function test_occurred_at_bukan_tanggal_dead(): void
    {
        [$tenant, $outlet, $orderId, $produk] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $env = $this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => 10000, 'service_charge' => 0, 'tax' => 0, 'grand_total' => 10000],
            [['product_id' => $produk, 'qty' => 1, 'unit_price' => 10000]],
        );
        $env['occurred_at'] = 'bukan-tanggal';

        $this->assertSame(ConsumeOutcome::Dead, $this->consumer()->handle($env));
        $this->assertSame(0, Sale::count());
    }

    /**
     * id non-scalar (array) lolos empty() → tanpa cek is_string jadi TypeError saat
     * INSERT yang menumbangkan daemon. Harus ditolak di gerbang → Dead.
     * (mutasi: kembalikan empty() → test merah / berubah jadi TypeError)
     */
    public function test_id_non_scalar_dead(): void
    {
        [$outlet, $orderId, $produk] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $env = $this->envelope(
            'placeholder', $outlet, $orderId,
            ['subtotal' => 10000, 'service_charge' => 0, 'tax' => 0, 'grand_total' => 10000],
            [['product_id' => $produk, 'qty' => 1, 'unit_price' => 10000]],
        );
        $env['tenant_id'] = ['bukan' => 'string']; // array, bukan uuid

        $this->assertSame(ConsumeOutcome::Dead, $this->consumer()->handle($env));
        $this->assertSame(0, Sale::count());
    }

    /**
     * Cabang QueryException non-unique → Requeue & TIDAK menandai processed (potong
     * TERTUNDA, bukan hilang). Dipicu product_id melebihi kolom uuid(36) → "Data too
     * long" (QueryException generik, bukan unique). Transaksi rollback penuh.
     * (mutasi: petakan QueryException ke Ack/Dead → test merah)
     */
    public function test_galat_db_transient_requeue(): void
    {
        [$tenant, $outlet, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $outcome = $this->consumer()->handle($this->envelope(
            $tenant, $outlet, $orderId,
            ['subtotal' => 10000, 'service_charge' => 0, 'tax' => 0, 'grand_total' => 10000],
            [['product_id' => str_repeat('a', 100), 'qty' => 1, 'unit_price' => 10000]], // > uuid(36)
        ));

        $this->assertSame(ConsumeOutcome::Requeue, $outcome);
        $this->assertDatabaseMissing('sales', ['order_id' => $orderId]);          // rollback
        $this->assertDatabaseMissing('processed_orders', ['order_id' => $orderId]); // tak ditandai
    }
}
