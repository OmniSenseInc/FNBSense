<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

/**
 * Owner mengunggah foto produk.
 *
 * Sepola QrisUploadTest di Ordering: berkas tak pernah disimpan apa adanya
 * (di-tulis ulang server), yang boleh mengunggah cuma owner, dan berkas lama
 * dibersihkan saat diganti. Foto ini yang tampil di menu pelanggan.
 */
class ProductImageUploadTest extends TestCase
{
    use MintsToken, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        Storage::fake('public');
    }

    private function produk(): Product
    {
        return Product::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Kopi Susu',
            'price' => 25000,
        ]);
    }

    private function unggah(Product $produk, UploadedFile $berkas, string $role = 'owner')
    {
        return $this->post(
            "/api/products/{$produk->id}/image",
            ['image' => $berkas],
            $this->authHeaders($this->tenantId, $role),
        );
    }

    public function test_owner_mengunggah_foto_dan_berkasnya_tersimpan(): void
    {
        $res = $this->unggah($this->produk(), UploadedFile::fake()->image('kopi.jpg', 400, 400));

        $res->assertOk();

        $url = $res->json('data.image_url');
        // Bentuk alamatnya ikut diuji: layar pelanggan & layar menu menyusun
        // alamat lengkap dari nilai ini, jadi awalannya bagian kontrak.
        $this->assertStringStartsWith('/storage/products/', $url);
        Storage::disk('public')->assertExists('products/'.basename($url));
    }

    public function test_foto_lama_dihapus_saat_diganti(): void
    {
        $produk = $this->produk();
        $lama = $this->unggah($produk, UploadedFile::fake()->image('a.jpg', 200, 200))
            ->json('data.image_url');

        $this->unggah($produk, UploadedFile::fake()->image('b.jpg', 200, 200))->assertOk();

        // Tanpa ini, tiap owner ganti foto satu berkas yatim tertinggal selamanya.
        Storage::disk('public')->assertMissing('products/'.basename($lama));
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_svg_ditolak_walau_berisi_markup_gambar_sah(): void
    {
        $jahat = UploadedFile::fake()->createWithContent(
            'x.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $res = $this->unggah($this->produk(), $jahat);

        $res->assertStatus(422);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_kasir_tidak_boleh_mengunggah(): void
    {
        $this->unggah($this->produk(), UploadedFile::fake()->image('q.jpg', 200, 200), 'cashier')
            ->assertForbidden();
    }

    public function test_produk_tenant_lain_tidak_tersentuh(): void
    {
        $milikB = Product::create([
            'tenant_id' => (string) Str::uuid(),
            'name' => 'Rahasia B',
            'price' => 1000,
        ]);

        $this->unggah($milikB, UploadedFile::fake()->image('q.jpg', 200, 200))
            ->assertNotFound();
    }
}
