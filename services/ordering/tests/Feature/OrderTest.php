<?php

namespace Tests\Feature;

use App\Models\OrderSetting;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Endpoint customer publik: scan meja, buat order, polling.
 *
 * Fokus gigi: harga TAK PERNAH dari client, produk lintas-tenant ditolak,
 * Catalog down menolak order (bukan menebak harga), field internal tak bocor.
 */
class OrderTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $outletId;

    private string $espressoId;

    private string $latteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();
        $this->espressoId = (string) Str::uuid();
        $this->latteId = (string) Str::uuid();

        // Array cache dipakai throttle; flush biar counter tak nyangkut antar-test.
        Cache::flush();
    }

    private function makeTable(bool $active = true): Table
    {
        $table = Table::createForOutlet($this->tenantId, $this->outletId, ['label' => 'Meja 1']);

        if (! $active) {
            $table->is_active = false;
            $table->save();
        }

        return $table;
    }

    /** Palsukan /api/menu Catalog: dua produk milik tenant ini. */
    private function fakeMenu(): void
    {
        Http::fake([
            '*/api/menu*' => Http::response(['data' => [
                ['products' => [
                    ['id' => $this->espressoId, 'name' => 'Espresso', 'price' => '10000.00'],
                    ['id' => $this->latteId, 'name' => 'Latte', 'price' => '25000.00'],
                ]],
            ]]),
        ]);
    }

    private function saveSetting(float $tax, float $serviceCharge): void
    {
        $setting = new OrderSetting([
            'tax_percent' => $tax,
            'service_charge_percent' => $serviceCharge,
            'order_expiry_minutes' => 30,
        ]);
        $setting->tenant_id = $this->tenantId;
        $setting->outlet_id = $this->outletId;
        $setting->save();
    }

    // ---- GET /api/t/{qrToken} -------------------------------------------------

    public function test_scan_qr_meja_aktif_mengembalikan_identitas(): void
    {
        $table = $this->makeTable();

        $this->getJson("/api/t/{$table->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $this->tenantId)
            ->assertJsonPath('data.outlet_id', $this->outletId)
            ->assertJsonPath('data.table_id', $table->id)
            ->assertJsonPath('data.label', 'Meja 1');
    }

    public function test_qr_token_tak_dikenal_404(): void
    {
        $this->getJson('/api/t/token-ngasal-tak-ada')->assertNotFound();
    }

    /** Meja non-aktif tak boleh bocor sebagai "ada tapi mati" -> 404. */
    public function test_qr_meja_nonaktif_404(): void
    {
        $table = $this->makeTable(active: false);

        $this->getJson("/api/t/{$table->qr_token}")->assertNotFound();
    }

    // ---- POST /api/orders -----------------------------------------------------

    /** Jalur bahagia: total dihitung server dari tarif outlet, order lahir PENDING. */
    public function test_buat_order_menghitung_total_server_side(): void
    {
        $this->fakeMenu();
        $this->saveSetting(tax: 11, serviceCharge: 5);
        $table = $this->makeTable();

        $response = $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [
                ['product_id' => $this->espressoId, 'qty' => 2], // 2 x 10000 = 20000
            ],
        ]);

        // subtotal 20000; sc 20000*5% = 1000; tax (20000+1000)*11% = 2310.
        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', 20000)
            ->assertJsonPath('data.service_charge', 1000)
            ->assertJsonPath('data.tax', 2310)
            ->assertJsonPath('data.grand_total', 23310)
            ->assertJsonPath('data.items.0.product_name', 'Espresso')
            ->assertJsonPath('data.items.0.unit_price', 10000)
            ->assertJsonPath('data.items.0.line_total', 20000);

        $this->assertNotEmpty($response->json('data.order_number'));

        // Invarian uang terkunci di DB: grand_total = subtotal + sc + tax.
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'tenant_id' => $this->tenantId,
            'outlet_id' => $this->outletId,
            'table_id' => $table->id,
            'status' => 'pending',
            'subtotal' => 20000,
            'service_charge' => 1000,
            'tax' => 2310,
            'grand_total' => 23310,
            // Tarif di-snapshot ke baris order.
            'tax_percent' => 11,
            'service_charge_percent' => 5,
        ]);
    }

    /**
     * GIGI skrutini #1: harga/total kiriman client DIABAIKAN. Client mengirim
     * unit_price 1 rupiah; server tetap memakai 10000 dari Catalog.
     */
    public function test_harga_dari_client_diabaikan(): void
    {
        $this->fakeMenu();
        $table = $this->makeTable();

        $response = $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Curang',
            'items' => [
                [
                    'product_id' => $this->espressoId,
                    'qty' => 1,
                    'unit_price' => 1,      // upaya menyetir harga
                    'price' => 1,
                    'line_total' => 1,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', 10000)
            ->assertJsonPath('data.items.0.unit_price', 10000)
            ->assertJsonPath('data.grand_total', 10000);
    }

    /** Tarif belum diset -> default 0 (bukan menebak 11%); grand = subtotal. */
    public function test_tanpa_setting_tarif_nol(): void
    {
        $this->fakeMenu();
        $table = $this->makeTable();

        $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => $this->latteId, 'qty' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 25000)
            ->assertJsonPath('data.service_charge', 0)
            ->assertJsonPath('data.tax', 0)
            ->assertJsonPath('data.grand_total', 25000);
    }

    /** GIGI skrutini #3: produk di luar menu tenant -> 422, bukan menebak harga. */
    public function test_produk_bukan_milik_tenant_ditolak_422(): void
    {
        $this->fakeMenu();
        $table = $this->makeTable();

        $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => (string) Str::uuid(), 'qty' => 1]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * GIGI: produk berharga rusak (null/non-numeric) TIDAK boleh jadi item Rp0.
     * Ia lenyap dari peta -> 422 kalau dipesan; produk waras di menu sama tetap jalan.
     */
    public function test_produk_harga_rusak_tak_bisa_dipesan(): void
    {
        $rusakId = (string) Str::uuid();
        Http::fake([
            '*/api/menu*' => Http::response(['data' => [
                ['products' => [
                    ['id' => $this->espressoId, 'name' => 'Espresso', 'price' => '10000.00'],
                    ['id' => $rusakId, 'name' => 'Tanpa Harga', 'price' => null],
                ]],
            ]]),
        ]);
        $table = $this->makeTable();

        // Produk harga rusak -> 422 (bukan order gratis).
        $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => $rusakId, 'qty' => 1]],
        ])->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);

        // Satu baris rusak tak menumbangkan menu: produk waras tetap bisa dipesan.
        $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => $this->espressoId, 'qty' => 1]],
        ])->assertCreated()->assertJsonPath('data.grand_total', 10000);
    }

    /** Catalog down -> 503, order TIDAK dibuat (lebih baik tolak daripada tebak). */
    public function test_catalog_down_503(): void
    {
        Http::fake(['*/api/menu*' => Http::response('gateway error', 503)]);
        $table = $this->makeTable();

        $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => $this->espressoId, 'qty' => 1]],
        ])->assertStatus(503);

        $this->assertDatabaseCount('orders', 0);
    }

    /** Takeaway: tetap scan meja (penentu tenant/outlet), tapi table_id null. */
    public function test_takeaway_tanpa_meja(): void
    {
        $this->fakeMenu();
        $table = $this->makeTable();

        $response = $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'takeaway',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => $this->espressoId, 'qty' => 1]],
        ])->assertCreated();

        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'tenant_id' => $this->tenantId,
            'outlet_id' => $this->outletId,
            'table_id' => null,
            'order_type' => 'takeaway',
        ]);
    }

    public function test_qr_token_tak_dikenal_saat_order_404(): void
    {
        $this->fakeMenu();

        $this->postJson('/api/orders', [
            'qr_token' => 'token-ngasal',
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => $this->espressoId, 'qty' => 1]],
        ])->assertNotFound();
    }

    public function test_qty_di_atas_batas_ditolak_422(): void
    {
        $table = $this->makeTable();

        $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => $this->espressoId, 'qty' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.qty');
    }

    public function test_order_tanpa_item_ditolak_422(): void
    {
        $table = $this->makeTable();

        $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    // ---- GET /api/orders/{id} -------------------------------------------------

    /** Polling customer: status tampil, tapi field internal TIDAK bocor. */
    public function test_polling_status_tak_membocorkan_field_internal(): void
    {
        $this->fakeMenu();
        $table = $this->makeTable();

        $id = $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => $this->espressoId, 'qty' => 1]],
        ])->json('data.id');

        $response = $this->getJson("/api/orders/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.grand_total', 10000);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('confirmed_by', $data);
        $this->assertArrayNotHasKey('tenant_id', $data);
        $this->assertArrayNotHasKey('outlet_id', $data);
    }

    public function test_polling_order_tak_ada_404(): void
    {
        $this->getJson('/api/orders/'.Str::uuid())->assertNotFound();
    }
}
