<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Penanda habis di menu publik: `/api/menu?tenant=&outlet=`.
 *
 * Catalog yang menghitung, bukan Inventory: resep tinggal di sini, jadi
 * memanggil `POST /api/availability` akan membuat Catalog->Inventory->Catalog
 * — lingkaran yang dipicu tiap pemindaian QR. Yang diambil dari Inventory cuma
 * saldo mentah (`GET /api/balance`).
 */
class MenuStockTest extends TestCase
{
    use RefreshDatabase;

    private string $tenant;

    private string $outlet;

    private Category $kategori;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = (string) Str::uuid();
        $this->outlet = (string) Str::uuid();
        config(['services.inventory.base_url' => 'http://inventory.test']);
        config(['services.inventory.service_token' => 'rahasia-uji']);
        $this->kategori = Category::create([
            'tenant_id' => $this->tenant,
            'name' => 'Kopi',
            'is_active' => true,
        ]);
    }

    private function produk(string $nama): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant,
            'category_id' => $this->kategori->id,
            'name' => $nama,
            'price' => 18000,
            'is_available' => true,
        ]);
    }

    private function bahan(string $nama): Ingredient
    {
        return Ingredient::create([
            'tenant_id' => $this->tenant,
            'name' => $nama,
            'unit' => 'g',
        ]);
    }

    private function resep(Product $produk, Ingredient $bahan, string $qtyPerUnit): void
    {
        Recipe::create([
            'tenant_id' => $this->tenant,
            'product_id' => $produk->id,
            'ingredient_id' => $bahan->id,
            'qty_per_unit' => $qtyPerUnit,
        ]);
    }

    /** @param  array<string, string>  $saldo  peta ingredient_id => qty_on_hand */
    private function inventoryPunya(array $saldo): void
    {
        Http::fake(['inventory.test/api/balance*' => Http::response(
            array_map(
                fn (string $id, string $qty) => ['ingredient_id' => $id, 'qty_on_hand' => $qty],
                array_keys($saldo),
                array_values($saldo),
            ),
        )]);
    }

    private function menu(?string $outlet = null)
    {
        $query = http_build_query([
            'tenant' => $this->tenant,
            'outlet' => $outlet ?? $this->outlet,
        ]);

        return $this->getJson("/api/menu?{$query}");
    }

    public function test_produk_yang_bahannya_kurang_ditandai_habis(): void
    {
        $espresso = $this->produk('Espresso');
        $biji = $this->bahan('Biji kopi');
        $this->resep($espresso, $biji, '18.000');
        $this->inventoryPunya([$biji->id => '5.000']);

        $this->menu()
            ->assertOk()
            ->assertJsonPath('data.0.products.0.name', 'Espresso')
            ->assertJsonPath('data.0.products.0.is_out_of_stock', true);
    }

    public function test_produk_yang_bahannya_cukup_tidak_ditandai(): void
    {
        $espresso = $this->produk('Espresso');
        $biji = $this->bahan('Biji kopi');
        $this->resep($espresso, $biji, '18.000');
        $this->inventoryPunya([$biji->id => '500.000']);

        $this->menu()->assertOk()->assertJsonPath('data.0.products.0.is_out_of_stock', false);
    }

    /**
     * Bahan yang belum pernah distok tak punya baris saldo, jadi Inventory tak
     * menyebutnya sama sekali. Itu NOL, bukan "tak diawasi" — menganggapnya
     * tersedia berarti menjanjikan barang yang belum pernah masuk gudang.
     */
    public function test_bahan_tanpa_baris_saldo_dihitung_nol(): void
    {
        $espresso = $this->produk('Espresso');
        $biji = $this->bahan('Biji kopi');
        $this->resep($espresso, $biji, '1.000');
        $this->inventoryPunya([]);

        $this->menu()->assertOk()->assertJsonPath('data.0.products.0.is_out_of_stock', true);
    }

    /**
     * Satu bahan kurang sudah cukup menjatuhkan produknya, meski bahan lain
     * melimpah. Kalau perbandingannya tertukar, test ini yang menangkap.
     */
    public function test_satu_bahan_kurang_menjatuhkan_produknya(): void
    {
        $latte = $this->produk('Latte');
        $biji = $this->bahan('Biji kopi');
        $susu = $this->bahan('Susu');
        $this->resep($latte, $biji, '18.000');
        $this->resep($latte, $susu, '150.000');
        $this->inventoryPunya([$biji->id => '9999.000', $susu->id => '10.000']);

        $this->menu()->assertOk()->assertJsonPath('data.0.products.0.is_out_of_stock', true);
    }

    /** Produk tanpa resep tak pernah tertandai — gerbangnya memang tak melihatnya. */
    public function test_produk_tanpa_resep_tak_pernah_ditandai(): void
    {
        $this->produk('Air Putih');
        Http::fake();

        $this->menu()->assertOk()->assertJsonPath('data.0.products.0.is_out_of_stock', false);
        Http::assertNothingSent();
    }

    /** Tanpa ?outlet= tak ada yang bisa dinilai — dan Inventory tak boleh disentuh. */
    public function test_tanpa_outlet_menu_utuh_tanpa_memanggil_inventory(): void
    {
        $espresso = $this->produk('Espresso');
        $biji = $this->bahan('Biji kopi');
        $this->resep($espresso, $biji, '18.000');
        Http::fake();

        $this->getJson('/api/menu?tenant='.$this->tenant)
            ->assertOk()
            ->assertJsonPath('data.0.products.0.is_out_of_stock', false);

        Http::assertNothingSent();
    }

    /**
     * Inventory mati -> menu tetap tampil UTUH tanpa penanda (fail-open),
     * sepola gerbang stok di Ordering. Kafe tak boleh terlihat kehabisan
     * segalanya gara-gara service yang tak memegang uang sedang pingsan.
     */
    public function test_inventory_mati_menu_tetap_tampil_tanpa_penanda(): void
    {
        $espresso = $this->produk('Espresso');
        $biji = $this->bahan('Biji kopi');
        $this->resep($espresso, $biji, '18.000');
        Http::fake(['inventory.test/api/balance*' => Http::response('gateway error', 503)]);

        $this->menu()->assertOk()->assertJsonPath('data.0.products.0.is_out_of_stock', false);
    }

    /** Badan 200 yang bukan array (mis. HTML dari proxy) tak boleh jadi "semua nol". */
    public function test_balasan_tak_dikenali_tak_menandai_seluruh_menu_habis(): void
    {
        $espresso = $this->produk('Espresso');
        $biji = $this->bahan('Biji kopi');
        $this->resep($espresso, $biji, '18.000');
        Http::fake(['inventory.test/api/balance*' => Http::response('<html>maintenance</html>')]);

        $this->menu()->assertOk()->assertJsonPath('data.0.products.0.is_out_of_stock', false);
    }

    public function test_token_service_dikirim_ke_inventory(): void
    {
        $espresso = $this->produk('Espresso');
        $biji = $this->bahan('Biji kopi');
        $this->resep($espresso, $biji, '18.000');
        $this->inventoryPunya([$biji->id => '500.000']);

        $this->menu()->assertOk();

        Http::assertSent(fn ($r) => $r->hasHeader('X-Service-Token', 'rahasia-uji')
            && $r['tenant'] === $this->tenant
            && $r['outlet'] === $this->outlet
            && $r['ids'] === $biji->id);
    }

    /** Resep tenant lain tak boleh ikut menjatuhkan produk tenant ini. */
    public function test_resep_tenant_lain_tak_ikut_dihitung(): void
    {
        $espresso = $this->produk('Espresso');
        $biji = $this->bahan('Biji kopi');
        // Baris resep milik tenant LAIN yang menunjuk produk yang sama.
        Recipe::create([
            'tenant_id' => (string) Str::uuid(),
            'product_id' => $espresso->id,
            'ingredient_id' => $biji->id,
            'qty_per_unit' => '9999.000',
        ]);
        Http::fake();

        $this->menu()->assertOk()->assertJsonPath('data.0.products.0.is_out_of_stock', false);
        Http::assertNothingSent();
    }

    /** Menu publik dipindai tiap tamu; tanpa cache ia jadi pengeras suara ke Inventory. */
    public function test_permintaan_kedua_tak_memanggil_inventory_lagi(): void
    {
        $espresso = $this->produk('Espresso');
        $biji = $this->bahan('Biji kopi');
        $this->resep($espresso, $biji, '18.000');
        $this->inventoryPunya([$biji->id => '500.000']);

        $this->menu()->assertOk();
        $this->menu()->assertOk();

        Http::assertSentCount(1);
    }

    /**
     * Satu kafe, dua cabang. Cache yang tak memuat outlet akan menilai menu
     * cabang kedua memakai gudang cabang pertama.
     */
    public function test_cache_tidak_tertukar_antar_outlet_tenant_yang_sama(): void
    {
        $espresso = $this->produk('Espresso');
        $biji = $this->bahan('Biji kopi');
        $this->resep($espresso, $biji, '18.000');
        $outletKedua = (string) Str::uuid();

        Http::fake(['inventory.test/api/balance*' => function ($request) use ($biji, $outletKedua) {
            $qty = $request['outlet'] === $outletKedua ? '1.000' : '500.000';

            return Http::response([['ingredient_id' => $biji->id, 'qty_on_hand' => $qty]]);
        }]);

        $this->menu()->assertOk()->assertJsonPath('data.0.products.0.is_out_of_stock', false);
        $this->menu($outletKedua)->assertOk()->assertJsonPath('data.0.products.0.is_out_of_stock', true);

        Http::assertSentCount(2);
    }

    public function test_outlet_bukan_uuid_ditolak(): void
    {
        $this->getJson('/api/menu?tenant='.$this->tenant.'&outlet=bukan-uuid')
            ->assertStatus(422)
            ->assertJsonValidationErrors('outlet');
    }
}
