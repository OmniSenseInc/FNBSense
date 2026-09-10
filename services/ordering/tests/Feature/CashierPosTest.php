<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

/**
 * POS kasir (walk-in / telepon / meja): baca menu, daftar meja aktif, buat order.
 *
 * Fokus gigi: order dibuat kasir masuk antrean PENDING dengan harga dari server
 * (bukan client), dine-in wajib meja aktif, dan manager (read-only) tak boleh
 * membuat order.
 */
class CashierPosTest extends TestCase
{
    use MintsToken, RefreshDatabase;

    private string $tenantId;

    private string $outletId;

    private string $cashierId;

    private string $espressoId;

    private string $latteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();
        $this->cashierId = (string) Str::uuid();
        $this->espressoId = (string) Str::uuid();
        $this->latteId = (string) Str::uuid();
    }

    /** @return array<string, string> */
    private function headers(string $role = 'cashier'): array
    {
        return ['Authorization' => 'Bearer '.$this->mintToken($this->tenantId, $this->outletId, $role, $this->cashierId)];
    }

    private function makeTable(string $label = 'Meja 1'): Table
    {
        return Table::createForOutlet($this->tenantId, $this->outletId, ['label' => $label]);
    }

    /** Palsukan /api/menu Catalog: satu kategori, dua produk. */
    private function fakeMenu(): void
    {
        Http::fake([
            '*/api/menu*' => Http::response(['data' => [
                [
                    'id' => (string) Str::uuid(),
                    'name' => 'Kopi',
                    'products' => [
                        ['id' => $this->espressoId, 'name' => 'Espresso', 'price' => '10000.00', 'is_out_of_stock' => false],
                        ['id' => $this->latteId, 'name' => 'Latte', 'price' => '25000.00', 'is_out_of_stock' => false],
                    ],
                ],
            ]]),
        ]);
    }

    // ---- GET /api/cashier/menu ------------------------------------------

    public function test_menu_kasir_mengembalikan_kategori_dan_produk(): void
    {
        $this->fakeMenu();

        $this->getJson('/api/cashier/menu', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Kopi')
            ->assertJsonPath('data.0.products.0.name', 'Espresso')
            ->assertJsonPath('data.0.products.0.price', '10000.00');
    }

    // ---- GET /api/cashier/tables ----------------------------------------

    public function test_daftar_meja_hanya_aktif(): void
    {
        $this->makeTable('Meja 1');
        $this->makeTable('Meja 2');
        $nonaktif = $this->makeTable('Meja Mati');
        $nonaktif->is_active = false;
        $nonaktif->save();

        $this->getJson('/api/cashier/tables', $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.label', 'Meja 1');
    }

    // ---- POST /api/cashier/orders ---------------------------------------

    public function test_buat_order_takeaway_tanpa_meja(): void
    {
        $this->fakeMenu();

        $this->postJson('/api/cashier/orders', [
            'order_type' => 'takeaway',
            'customer_name' => 'Budi',
            'items' => [['product_id' => $this->espressoId, 'qty' => 2]],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.order_type', 'takeaway')
            ->assertJsonPath('data.table_id', null)
            ->assertJsonPath('data.subtotal', 20000);

        $this->assertDatabaseHas('orders', [
            'tenant_id' => $this->tenantId,
            'outlet_id' => $this->outletId,
            'table_id' => null,
            'status' => 'pending',
        ]);
    }

    public function test_buat_order_dine_in_dengan_meja(): void
    {
        $this->fakeMenu();
        $table = $this->makeTable('Meja 3');

        $this->postJson('/api/cashier/orders', [
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'table_id' => $table->id,
            'items' => [['product_id' => $this->latteId, 'qty' => 1]],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.order_type', 'dine_in')
            ->assertJsonPath('data.table_id', $table->id)
            ->assertJsonPath('data.table_label', 'Meja 3')
            ->assertJsonPath('data.subtotal', 25000);
    }

    /** Dine-in tanpa meja -> 422 (kasir lupa pilih meja / daftar basi). */
    public function test_dine_in_tanpa_meja_ditolak(): void
    {
        $this->fakeMenu();

        $this->postJson('/api/cashier/orders', [
            'order_type' => 'dine_in',
            'items' => [['product_id' => $this->espressoId, 'qty' => 1]],
        ], $this->headers())
            ->assertStatus(422);
    }

    /** Manager read-only tak boleh membuat order. */
    public function test_manager_tak_boleh_buat_order(): void
    {
        $this->fakeMenu();

        $this->postJson('/api/cashier/orders', [
            'order_type' => 'takeaway',
            'items' => [['product_id' => $this->espressoId, 'qty' => 1]],
        ], $this->headers('manager'))
            ->assertStatus(403);
    }
}
