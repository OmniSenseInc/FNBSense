<?php

namespace Tests\Feature;

use App\Messaging\ConsumeOutcome;
use App\Messaging\EventPublisher;
use App\Messaging\OrderPaidConsumer;
use App\Models\ProcessedOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Test inti consumer order.paid (F4b/F4c) — tanpa broker. Catalog di-fake via
 * Http, penerbit event di-fake via container. Fokus: potong sesuai resep,
 * idempoten (anti dobel), Catalog-down retry, malformed ke DLQ, isolasi outlet,
 * dan saga F4c (event terbit saat MELINTAS ambang, bukan tiap kali di bawahnya).
 */
class StockConsumeTest extends TestCase
{
    use RefreshDatabase;

    /** Penerbit palsu (anonymous class) yang merekam alih-alih menyentuh broker. */
    private $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        // Tanpa ini, tiap test yang memicu saga akan mencoba membuka koneksi
        // RabbitMQ sungguhan — lambat dan gagal di mesin tanpa broker.
        $this->publisher = new class extends EventPublisher
        {
            /** @var array<int, array{type: string, tenant_id: string, outlet_id: string, payload: array<string, mixed>}> */
            public array $published = [];

            /** Diset test yang ingin mensimulasikan broker menolak/mati. */
            public bool $shouldFail = false;

            public function publish(string $eventType, string $tenantId, string $outletId, array $payload): void
            {
                if ($this->shouldFail) {
                    throw new RuntimeException('broker mati (simulasi)');
                }

                $this->published[] = [
                    'type' => $eventType,
                    'tenant_id' => $tenantId,   // direkam: isolasi tenant/outlet ikut diuji
                    'outlet_id' => $outletId,
                    'payload' => $payload,
                ];
            }
        };

        $this->app->instance(EventPublisher::class, $this->publisher);
    }

    private function consumer(): OrderPaidConsumer
    {
        return app(OrderPaidConsumer::class);
    }

    /**
     * Payload event bertipe $type yang sudah terbit.
     *
     * @return array<int, array<string, mixed>>
     */
    private function publishedOf(string $type): array
    {
        return array_values(array_map(
            static fn ($e) => $e['payload'],
            array_filter($this->publisher->published, static fn ($e) => $e['type'] === $type),
        ));
    }

    /** @param array<int, array{product_id: string, qty: int}> $items */
    private function envelope(string $tenant, string $outlet, string $orderId, array $items): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'order.paid',
            'occurred_at' => now()->toIso8601String(),
            'tenant_id' => $tenant,
            'outlet_id' => $outlet,
            'payload' => ['order_id' => $orderId, 'items' => $items],
        ];
    }

    /** @param array<int, array{product_id: string, ingredients: array}> $rows */
    private function fakeRecipe(array $rows): void
    {
        Http::fake(['*/api/recipe*' => Http::response($rows, 200)]);
    }

    private function seedBalance(string $tenant, string $outlet, string $ingredient, float $qty, float $minStock = 0): void
    {
        StockBalance::create([
            'tenant_id' => $tenant, 'outlet_id' => $outlet,
            'ingredient_id' => $ingredient, 'qty_on_hand' => $qty, 'min_stock' => $minStock,
        ]);
    }

    /** Potong saldo = qty_per_unit × qty pesanan; 1 movement order_deduction. */
    public function test_potong_stok_sesuai_resep(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 1000);
        $this->fakeRecipe([
            ['product_id' => $produk, 'ingredients' => [
                ['ingredient_id' => $bahan, 'qty_per_unit' => '200.000', 'unit' => 'ml'],
            ]],
        ]);

        $outcome = $this->consumer()->handle(
            $this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 2]])
        );

        $this->assertSame(ConsumeOutcome::Ack, $outcome);
        $this->assertDatabaseHas('stock_balances', ['ingredient_id' => $bahan, 'qty_on_hand' => 600]); // 1000 - 200*2
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $orderId, 'ingredient_id' => $bahan,
            'qty_delta' => -400, 'reason' => 'order_deduction',
        ]);
        $this->assertDatabaseHas('processed_orders', ['order_id' => $orderId, 'status' => 'deducted']);
    }

    /** IDEMPOTEN: event sama diproses 2x → potong TETAP sekali. (mutasi: buang unique/dedup → merah) */
    public function test_replay_tak_dobel_potong(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 1000);
        $this->fakeRecipe([
            ['product_id' => $produk, 'ingredients' => [
                ['ingredient_id' => $bahan, 'qty_per_unit' => '200.000', 'unit' => 'ml'],
            ]],
        ]);
        $env = $this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 2]]);

        $this->consumer()->handle($env);
        $second = $this->consumer()->handle($env); // replay

        $this->assertSame(ConsumeOutcome::Ack, $second);
        $this->assertDatabaseHas('stock_balances', ['ingredient_id' => $bahan, 'qty_on_hand' => 600]); // BUKAN 200
        $this->assertSame(1, StockMovement::where('order_id', $orderId)->count());
        $this->assertSame(1, ProcessedOrder::where('order_id', $orderId)->count());
    }

    /** Catalog down (5xx) → Requeue; JANGAN tandai processed / potong (tertunda, bukan hilang). */
    public function test_catalog_down_requeue_tanpa_efek(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 1000);
        Http::fake(['*/api/recipe*' => Http::response(null, 503)]);

        $outcome = $this->consumer()->handle(
            $this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 2]])
        );

        $this->assertSame(ConsumeOutcome::Requeue, $outcome);
        $this->assertDatabaseMissing('processed_orders', ['order_id' => $orderId]);
        $this->assertDatabaseMissing('stock_movements', ['order_id' => $orderId]);
        $this->assertDatabaseHas('stock_balances', ['ingredient_id' => $bahan, 'qty_on_hand' => 1000]); // utuh
    }

    /** Amplop tak berbentuk → Dead (DLQ), tak menulis apa pun, tak crash. */
    public function test_amplop_malformed_dead(): void
    {
        // payload tanpa items.
        $bad = [
            'event_type' => 'order.paid', 'tenant_id' => (string) Str::uuid(),
            'outlet_id' => (string) Str::uuid(), 'payload' => ['order_id' => (string) Str::uuid()],
        ];

        $this->assertSame(ConsumeOutcome::Dead, $this->consumer()->handle($bad));
        $this->assertSame(0, ProcessedOrder::count());
        $this->assertSame(0, StockMovement::count());
    }

    /** Produk tanpa resep di Catalog → skip (unmapped), status recipe_missing, tetap Ack & processed. */
    public function test_produk_tanpa_resep_recipe_missing(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $orderId] = [(string) Str::uuid(), (string) Str::uuid()];
        $this->fakeRecipe([]); // Catalog jawab, tapi produk ini tak punya resep

        $outcome = $this->consumer()->handle(
            $this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 1]])
        );

        $this->assertSame(ConsumeOutcome::Ack, $outcome);
        $this->assertDatabaseHas('processed_orders', ['order_id' => $orderId, 'status' => 'recipe_missing']);
        $this->assertSame(0, StockMovement::where('order_id', $orderId)->count()); // tak ada potong
    }

    /** Stok kurang → saldo NEGATIF jujur (no rollback), status shortfall, tetap Ack. */
    public function test_stok_kurang_saldo_negatif_shortfall(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 100); // cuma 100, butuh 400
        $this->fakeRecipe([
            ['product_id' => $produk, 'ingredients' => [
                ['ingredient_id' => $bahan, 'qty_per_unit' => '200.000', 'unit' => 'ml'],
            ]],
        ]);

        $outcome = $this->consumer()->handle(
            $this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 2]])
        );

        $this->assertSame(ConsumeOutcome::Ack, $outcome);
        $this->assertDatabaseHas('stock_balances', ['ingredient_id' => $bahan, 'qty_on_hand' => -300]); // 100 - 400
        $this->assertDatabaseHas('processed_orders', ['order_id' => $orderId, 'status' => 'shortfall']);
    }

    /** Bahan sama di 2 item → kebutuhan diagregasi jadi 1 movement (bukan 2). */
    public function test_agregasi_bahan_lintas_item(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produkA, $produkB, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 1000);
        $this->fakeRecipe([
            ['product_id' => $produkA, 'ingredients' => [['ingredient_id' => $bahan, 'qty_per_unit' => '100.000', 'unit' => 'ml']]],
            ['product_id' => $produkB, 'ingredients' => [['ingredient_id' => $bahan, 'qty_per_unit' => '50.000', 'unit' => 'ml']]],
        ]);

        $this->consumer()->handle($this->envelope($tenant, $outlet, $orderId, [
            ['product_id' => $produkA, 'qty' => 1],
            ['product_id' => $produkB, 'qty' => 1],
        ]));

        $this->assertSame(1, StockMovement::where('order_id', $orderId)->count()); // 1 movement, bukan 2
        $this->assertDatabaseHas('stock_balances', ['ingredient_id' => $bahan, 'qty_on_hand' => 850]); // 1000 - (100+50)
    }

    /** Isolasi outlet: potong outlet A tak menyentuh saldo bahan sama milik outlet B. */
    public function test_potong_tak_menyentuh_outlet_lain(): void
    {
        $tenant = (string) Str::uuid();
        [$outletA, $outletB] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outletB, $bahan, 1000); // stok bahan ini milik outlet B
        $this->fakeRecipe([
            ['product_id' => $produk, 'ingredients' => [['ingredient_id' => $bahan, 'qty_per_unit' => '200.000', 'unit' => 'ml']]],
        ]);

        // Pesanan datang untuk outlet A.
        $this->consumer()->handle($this->envelope($tenant, $outletA, $orderId, [['product_id' => $produk, 'qty' => 2]]));

        $this->assertDatabaseHas('stock_balances', ['outlet_id' => $outletB, 'ingredient_id' => $bahan, 'qty_on_hand' => 1000]); // B utuh
        $this->assertDatabaseHas('stock_balances', ['outlet_id' => $outletA, 'ingredient_id' => $bahan, 'qty_on_hand' => -400]); // A sendiri
    }

    // ---- Saga F4c ----------------------------------------------------------

    /** Saldo BARU jebol ke minus → inventory.shortfall terbit dengan angka jujur. */
    public function test_shortfall_terbit_saat_saldo_baru_jebol(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 100); // punya 100, butuh 400
        $this->fakeRecipe([
            ['product_id' => $produk, 'ingredients' => [['ingredient_id' => $bahan, 'qty_per_unit' => '200.000', 'unit' => 'ml']]],
        ]);

        $this->consumer()->handle($this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 2]]));

        $events = $this->publishedOf('inventory.shortfall');
        $this->assertCount(1, $events);
        $this->assertSame($orderId, $events[0]['order_id']);
        $this->assertSame([
            ['ingredient_id' => $bahan, 'needed' => 400.0, 'on_hand_after' => -300.0],
        ], $events[0]['items']);
    }

    /**
     * INTI F4c: sekali jebol, order berikutnya TIDAK meneriakkan bahan yang sama.
     * Potong tetap jalan (saldo makin minus) — yang diredam cuma alert kembarnya.
     * (mutasi: ganti syarat `before >= 0 && after < 0` jadi `after < 0` → merah)
     */
    public function test_shortfall_tak_terbit_lagi_saat_saldo_sudah_minus(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan] = [(string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 100);
        $this->fakeRecipe([
            ['product_id' => $produk, 'ingredients' => [['ingredient_id' => $bahan, 'qty_per_unit' => '200.000', 'unit' => 'ml']]],
        ]);
        $item = [['product_id' => $produk, 'qty' => 2]]; // 400 per order

        // Order 1: 100 → -300 (melintas, teriak sekali).
        $this->consumer()->handle($this->envelope($tenant, $outlet, (string) Str::uuid(), $item));
        $this->assertCount(1, $this->publishedOf('inventory.shortfall'));

        // Order 2: -300 → -700 (sudah minus sejak awal, tak melintas lagi).
        $this->consumer()->handle($this->envelope($tenant, $outlet, (string) Str::uuid(), $item));

        $this->assertCount(1, $this->publishedOf('inventory.shortfall')); // TETAP 1, bukan 2
        $this->assertDatabaseHas('stock_balances', ['ingredient_id' => $bahan, 'qty_on_hand' => -700]); // potong tetap jalan
    }

    /** Produk tanpa resep → inventory.recipe_missing membawa product_id yang dilewati. */
    public function test_recipe_missing_terbit_dengan_product_id(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $orderId] = [(string) Str::uuid(), (string) Str::uuid()];
        $this->fakeRecipe([]); // Catalog menjawab, tapi produk ini tak punya resep

        $this->consumer()->handle($this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 1]]));

        $events = $this->publishedOf('inventory.recipe_missing');
        $this->assertCount(1, $events);
        $this->assertSame([$produk], $events[0]['product_ids']);
    }

    /** Saldo melintas turun ke <= min_stock → peringatan dini inventory.low_stock. */
    public function test_low_stock_terbit_saat_melintas_ambang(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 1000, minStock: 500); // ambang 500
        $this->fakeRecipe([
            ['product_id' => $produk, 'ingredients' => [['ingredient_id' => $bahan, 'qty_per_unit' => '300.000', 'unit' => 'ml']]],
        ]);

        // 1000 → 400: melintas ambang 500, tapi masih jauh dari minus.
        $this->consumer()->handle($this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 2]]));

        $events = $this->publishedOf('inventory.low_stock');
        $this->assertCount(1, $events);
        $this->assertSame([
            ['ingredient_id' => $bahan, 'on_hand_after' => 400.0, 'min_stock' => 500.0],
        ], $events[0]['items']);
        $this->assertSame([], $this->publishedOf('inventory.shortfall')); // belum minus → jangan teriak jebol
    }

    /** min_stock belum diisi owner (default 0) → order sehat tak menerbitkan apa pun. */
    public function test_order_sehat_tanpa_ambang_tak_terbit_event(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 1000); // min_stock default 0
        $this->fakeRecipe([
            ['product_id' => $produk, 'ingredients' => [['ingredient_id' => $bahan, 'qty_per_unit' => '200.000', 'unit' => 'ml']]],
        ]);

        $this->consumer()->handle($this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 2]]));

        $this->assertSame([], $this->publisher->published); // nol event: jangan berisik saat sehat
    }

    /** Event saga membawa tenant & outlet dari amplop — bukan bocor punya order lain. */
    public function test_event_saga_membawa_tenant_dan_outlet_amplop(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 100);
        $this->fakeRecipe([
            ['product_id' => $produk, 'ingredients' => [['ingredient_id' => $bahan, 'qty_per_unit' => '200.000', 'unit' => 'ml']]],
        ]);

        $this->consumer()->handle($this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 2]]));

        $this->assertSame($tenant, $this->publisher->published[0]['tenant_id']);
        $this->assertSame($outlet, $this->publisher->published[0]['outlet_id']);
    }

    /**
     * Broker mati saat menerbitkan saga → tetap Ack, potong TIDAK di-rollback.
     * Potong sudah commit; requeue cuma bakal ke-dedup, dan exception yang naik
     * bakal menumbangkan daemon. (mutasi: buang try/catch di publishSaga → merah)
     */
    public function test_gagal_terbit_saga_tetap_ack_dan_potong_utuh(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        [$produk, $bahan, $orderId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->seedBalance($tenant, $outlet, $bahan, 100); // bakal jebol → memicu saga
        $this->fakeRecipe([
            ['product_id' => $produk, 'ingredients' => [['ingredient_id' => $bahan, 'qty_per_unit' => '200.000', 'unit' => 'ml']]],
        ]);
        $this->publisher->shouldFail = true;

        $outcome = $this->consumer()->handle(
            $this->envelope($tenant, $outlet, $orderId, [['product_id' => $produk, 'qty' => 2]])
        );

        $this->assertSame(ConsumeOutcome::Ack, $outcome); // BUKAN Requeue, dan tak melempar
        $this->assertDatabaseHas('stock_balances', ['ingredient_id' => $bahan, 'qty_on_hand' => -300]); // potong utuh
        $this->assertDatabaseHas('processed_orders', ['order_id' => $orderId, 'status' => 'shortfall']);
    }
}
