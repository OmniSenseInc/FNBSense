<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_publik_tanpa_auth_menampilkan_kategori_aktif_dan_produk_tersedia(): void
    {
        $tenantId = (string) Str::uuid();
        $kategori = Category::create(['tenant_id' => $tenantId, 'name' => 'Kopi', 'is_active' => true]);
        Product::create([
            'tenant_id' => $tenantId,
            'category_id' => $kategori->id,
            'name' => 'Espresso',
            'price' => 18000,
            'is_available' => true,
        ]);

        $this->getJson('/api/menu?tenant='.$tenantId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Kopi')
            ->assertJsonPath('data.0.products.0.name', 'Espresso');
    }

    public function test_kategori_inaktif_dan_produk_tidak_tersedia_disembunyikan(): void
    {
        $tenantId = (string) Str::uuid();

        $aktif = Category::create(['tenant_id' => $tenantId, 'name' => 'Aktif', 'is_active' => true]);
        Category::create(['tenant_id' => $tenantId, 'name' => 'Nonaktif', 'is_active' => false]);

        Product::create([
            'tenant_id' => $tenantId, 'category_id' => $aktif->id,
            'name' => 'Ready', 'price' => 1000, 'is_available' => true,
        ]);
        Product::create([
            'tenant_id' => $tenantId, 'category_id' => $aktif->id,
            'name' => 'Habis', 'price' => 1000, 'is_available' => false,
        ]);

        $this->getJson('/api/menu?tenant='.$tenantId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Aktif')
            ->assertJsonCount(1, 'data.0.products')
            ->assertJsonPath('data.0.products.0.name', 'Ready');
    }

    public function test_menu_terisolasi_per_tenant(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        Category::create(['tenant_id' => $tenantA, 'name' => 'Punya A']);
        Category::create(['tenant_id' => $tenantB, 'name' => 'Punya B']);

        $this->getJson('/api/menu?tenant='.$tenantA)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Punya A');
    }

    /**
     * Penjaga kebocoran kolom.
     *
     * Tes ini SENGAJA memeriksa daftar kunci secara persis, bukan sekadar
     * "tidak ada tenant_id". Alasannya: ancaman sesungguhnya adalah kolom yang
     * BELUM ADA hari ini — harga modal/COGS di F7b. Dengan perbandingan
     * persis, kolom baru apa pun di tabel products langsung membuat tes ini
     * merah, jadi orang yang menambahkannya dipaksa memutuskan sadar apakah
     * kolom itu boleh dilihat pelanggan.
     */
    public function test_menu_publik_hanya_membocorkan_kolom_yang_diizinkan(): void
    {
        $tenantId = (string) Str::uuid();
        $kategori = Category::create(['tenant_id' => $tenantId, 'name' => 'Kopi', 'is_active' => true]);
        Product::create([
            'tenant_id' => $tenantId,
            'category_id' => $kategori->id,
            'name' => 'Espresso',
            'price' => 18000,
            'is_available' => true,
        ]);

        $data = $this->getJson('/api/menu?tenant='.$tenantId)->assertOk()->json('data');

        $this->assertSame(['id', 'name', 'products'], array_keys($data[0]));
        $this->assertSame(
            ['id', 'name', 'description', 'price', 'image_url', 'is_out_of_stock'],
            array_keys($data[0]['products'][0]),
        );
        // Tanpa ?outlet= tak ada yang bisa dinilai, dan "tak tahu" harus
        // terbaca sebagai tidak-habis — bukan menyembunyikan medannya.
        $this->assertFalse($data[0]['products'][0]['is_out_of_stock']);
    }

    public function test_param_tenant_wajib_422(): void
    {
        $this->getJson('/api/menu')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant');
    }
}
