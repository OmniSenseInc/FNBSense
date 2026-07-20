<?php

namespace Tests\Feature;

use App\Messaging\ConsumeOutcome;
use App\Messaging\OrderPaidConsumer;
use App\Models\ProcessedOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test inti consumer order.paid (F4b) — tanpa broker. Catalog di-fake via Http.
 * Fokus: potong sesuai resep, idempoten (anti dobel), Catalog-down retry,
 * malformed ke DLQ, saga stok kurang, isolasi outlet.
 */
class StockConsumeTest extends TestCase
{
    use RefreshDatabase;

    private function consumer(): OrderPaidConsumer
    {
        return app(OrderPaidConsumer::class);
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

    private function seedBalance(string $tenant, string $outlet, string $ingredient, float $qty): void
    {
        StockBalance::create([
            'tenant_id' => $tenant, 'outlet_id' => $outlet,
            'ingredient_id' => $ingredient, 'qty_on_hand' => $qty,
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
}
