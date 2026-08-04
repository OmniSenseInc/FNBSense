<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Endpoint internal service-to-service GET /api/ingredient (auth X-Service-Token).
 *
 * Dikonsumsi Inventory supaya layar stok kasir menampilkan NAMA bahan, bukan
 * deretan UUID. Sepola GET /api/recipe: sengaja lewat token service, bukan
 * dengan membuka CRUD bahan (owner-only) ke kasir — kasir tak pernah menyentuh
 * Catalog, dan kolom apa pun yang kelak ditambahkan ke tabel `ingredients`
 * (harga beli adalah yang paling mungkin) tak ikut terbawa hanya karena
 * seseorang menambah kolom.
 */
class IngredientBatchTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-secret-catalog'; // sama dengan phpunit.xml CATALOG_SERVICE_TOKEN.

    /** @return array<string, string> */
    private function serviceHeader(): array
    {
        return ['X-Service-Token' => self::TOKEN];
    }

    private function buatBahan(string $tenantId, string $nama = 'Susu Full Cream', string $unit = 'ml'): Ingredient
    {
        return Ingredient::create(['tenant_id' => $tenantId, 'name' => $nama, 'unit' => $unit]);
    }

    public function test_balikin_nama_bahan_untuk_id_yang_diminta(): void
    {
        $tenantId = (string) Str::uuid();
        $bahan = $this->buatBahan($tenantId, 'Susu Full Cream', 'ml');

        $this->withHeaders($this->serviceHeader())
            ->getJson("/api/ingredient?tenant={$tenantId}&ids={$bahan->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $bahan->id)
            ->assertJsonPath('0.name', 'Susu Full Cream')
            ->assertJsonPath('0.unit', 'ml');
    }

    /**
     * Inilah gigi berkas ini.
     *
     * assertJsonPath cuma memeriksa yang ADA; ia diam untuk medan yang tak
     * seharusnya ikut. Endpoint ini berujung di layar kasir, jadi yang dijaga
     * bukan "nama muncul" melainkan "tak ada yang lain ikut muncul". Tambah
     * kolom cost_per_unit ke tabel ingredients lalu kirim model mentah — test
     * ini merah, dan itu memang yang kita mau.
     */
    public function test_hanya_mengirim_id_nama_unit(): void
    {
        $tenantId = (string) Str::uuid();
        $bahan = $this->buatBahan($tenantId);

        $baris = $this->withHeaders($this->serviceHeader())
            ->getJson("/api/ingredient?tenant={$tenantId}&ids={$bahan->id}")
            ->assertOk()
            ->json('0');

        $kunci = array_keys($baris);
        sort($kunci);

        $this->assertSame(['id', 'name', 'unit'], $kunci);
    }

    public function test_tidak_bocor_bahan_tenant_lain(): void
    {
        $bahanB = $this->buatBahan((string) Str::uuid(), 'Rahasia Dapur B');

        // Minta pakai tenant A (acak) tapi id milik B -> harus kosong.
        $this->withHeaders($this->serviceHeader())
            ->getJson('/api/ingredient?tenant='.Str::uuid()."&ids={$bahanB->id}")
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_id_tak_dikenal_dilewati_tanpa_menumbangkan_sisanya(): void
    {
        // Bahan bisa dihapus owner sementara saldo stoknya masih ada di Inventory.
        // Kalau id yatim membuat seluruh permintaan gagal, satu bahan terhapus
        // cukup untuk mematikan layar stok — padahal sisanya masih benar.
        $tenantId = (string) Str::uuid();
        $ada = $this->buatBahan($tenantId, 'Kopi Arabika', 'g');
        $yatim = (string) Str::uuid();

        $this->withHeaders($this->serviceHeader())
            ->getJson("/api/ingredient?tenant={$tenantId}&ids={$ada->id},{$yatim}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ada->id);
    }

    public function test_spasi_setelah_koma_tetap_ter_match(): void
    {
        // "a, b" dan bukan "a,b" — bentuk yang keluar dari implode(', ') atau
        // dari query yang pernah disusun tangan. Tanpa trim, id kedua berangkat
        // sebagai " <uuid>" dan tak pernah cocok: satu bahan kehilangan namanya
        // di layar, sementara yang lain baik-baik saja. Kegagalan separuh
        // seperti itu jauh lebih lama tak ketahuan daripada gagal total.
        //
        // Spasi di UJUNG URI sengaja tak diuji: Symfony menolaknya di lapisan
        // HTTP sebelum permintaan sampai ke aplikasi, jadi yang teruji cuma
        // Symfony, bukan kode ini.
        $tenantId = (string) Str::uuid();
        $satu = $this->buatBahan($tenantId, 'Gula Aren', 'g');
        $dua = $this->buatBahan($tenantId, 'Kopi Arabika', 'g');

        $this->withHeaders($this->serviceHeader())
            ->getJson("/api/ingredient?tenant={$tenantId}&ids={$satu->id}, {$dua->id}")
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_tanpa_token_ditolak_401(): void
    {
        $this->getJson('/api/ingredient?tenant='.Str::uuid().'&ids='.Str::uuid())
            ->assertUnauthorized();
    }

    public function test_token_salah_ditolak_401(): void
    {
        $this->withHeaders(['X-Service-Token' => 'salah'])
            ->getJson('/api/ingredient?tenant='.Str::uuid().'&ids='.Str::uuid())
            ->assertUnauthorized();
    }

    public function test_tenant_wajib_422(): void
    {
        $this->withHeaders($this->serviceHeader())
            ->getJson('/api/ingredient?ids='.Str::uuid())
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant');
    }
}
