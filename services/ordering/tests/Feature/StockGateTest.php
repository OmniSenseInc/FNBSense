<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gerbang stok saat order dibuat (bukan saat dibayar).
 *
 * Yang dijaga di sini adalah urutan waktunya: dulu bahan habis baru ketahuan
 * SESUDAH pelanggan membayar, dan pembatalan sesudah PAID tak ada. Sekali
 * gerbang ini lepas, kafe kembali menerima uang untuk barang yang tak ada.
 */
class StockGateTest extends TestCase
{
    use RefreshDatabase;

    private string $produkId;

    private Table $meja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->produkId = (string) Str::uuid();

        // Lewat factory milik model, bukan new Table(...): qr_token diterbitkan
        // di dalam sana (dan tak fillable), jadi meja rakitan tangan tak pernah
        // ditemukan dan seluruh berkas ini membalas 404.
        $this->meja = Table::createForOutlet(
            (string) Str::uuid(),
            (string) Str::uuid(),
            ['label' => 'Meja 1'],
        );
    }

    /** @param array<int, string> $habis */
    private function fake(array $habis): void
    {
        Http::fake([
            '*/api/menu*' => Http::response(['data' => [
                ['products' => [
                    ['id' => $this->produkId, 'name' => 'Kopi Susu', 'price' => '25000.00'],
                ]],
            ]]),
            '*/api/availability*' => Http::response(['data' => ['unavailable' => $habis]]),
        ]);
    }

    private function pesan(int $qty = 1)
    {
        return $this->postJson('/api/orders', [
            'qr_token' => $this->meja->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Uji',
            'items' => [['product_id' => $this->produkId, 'qty' => $qty]],
        ]);
    }

    public function test_bahan_cukup_maka_order_dibuat(): void
    {
        $this->fake([]);

        $this->pesan()->assertCreated();
        $this->assertSame(1, Order::count());
    }

    public function test_bahan_habis_ditolak_dan_tak_ada_order_tersimpan(): void
    {
        // Yang paling penting bukan status 422-nya, tapi bahwa tak ada baris
        // order yang lahir: order PENDING atas barang yang tak ada tetap bisa
        // dibayar kasir beberapa menit kemudian.
        $this->fake([$this->produkId]);

        $this->pesan()->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_pesan_penolakannya_menyebut_nama_produk_bukan_uuid(): void
    {
        $this->fake([$this->produkId]);

        $res = $this->pesan()->assertStatus(422);

        $this->assertStringContainsString('Kopi Susu', (string) $res->json('message'));
        $this->assertStringNotContainsString($this->produkId, (string) $res->json('message'));
    }

    /**
     * Inventory mati -> order TETAP dibuat (fail-open yang disengaja; jaring
     * pengamannya `inventory.shortfall` saat order.paid). Test ini ada supaya
     * keputusan itu tak pernah berubah diam-diam.
     */
    public function test_inventory_mati_order_tetap_jalan(): void
    {
        Http::fake([
            '*/api/menu*' => Http::response(['data' => [
                ['products' => [
                    ['id' => $this->produkId, 'name' => 'Kopi Susu', 'price' => '25000.00'],
                ]],
            ]]),
            '*/api/availability*' => Http::response('gateway error', 503),
        ]);

        $this->pesan()->assertCreated();
        $this->assertSame(1, Order::count());
    }
}
