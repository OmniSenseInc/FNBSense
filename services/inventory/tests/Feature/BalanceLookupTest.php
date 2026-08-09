<?php

namespace Tests\Feature;

use App\Models\StockBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Saldo bahan untuk service lain (GET /api/balance) — dipanggil Catalog supaya
 * `/api/menu` bisa menandai produk yang bahannya habis.
 *
 * Kenapa Catalog yang menghitung, bukan Inventory: resep tinggal di Catalog.
 * Kalau Catalog memanggil `/api/availability`, gerbang itu memanggil BALIK
 * Catalog untuk mengambil resep — lingkaran yang dipicu tiap pemindaian QR.
 * Endpoint ini memotongnya jadi satu lompatan: Inventory pemilik saldo,
 * Catalog pemilik resep.
 */
class BalanceLookupTest extends TestCase
{
    use RefreshDatabase;

    private string $tenant;

    private string $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = (string) Str::uuid();
        $this->outlet = (string) Str::uuid();
        config(['services.internal_token' => 'rahasia-uji']);
    }

    private function saldo(string $ingredientId, string $qty, ?string $outlet = null): void
    {
        StockBalance::create([
            'tenant_id' => $this->tenant,
            'outlet_id' => $outlet ?? $this->outlet,
            'ingredient_id' => $ingredientId,
            'qty_on_hand' => $qty,
            'min_stock' => '0',
        ]);
    }

    /** @param array<int, string> $ids */
    private function minta(array $ids, string $token = 'rahasia-uji', ?string $outlet = null)
    {
        $query = http_build_query([
            'tenant' => $this->tenant,
            'outlet' => $outlet ?? $this->outlet,
            'ids' => implode(',', $ids),
        ]);

        return $this->getJson("/api/balance?{$query}", ['X-Service-Token' => $token]);
    }

    public function test_mengembalikan_saldo_bahan_yang_diminta(): void
    {
        $kopi = (string) Str::uuid();
        $this->saldo($kopi, '500.000');

        $res = $this->minta([$kopi])->assertOk();

        $this->assertSame($kopi, $res->json('0.ingredient_id'));
        $this->assertSame('500.000', $res->json('0.qty_on_hand'));
    }

    /**
     * Bahan yang belum pernah distok TIDAK muncul. Pemanggilnya wajib
     * memperlakukan yang absen sebagai NOL, bukan "tak diawasi" — mengarang
     * baris nol di sini cuma memindahkan keputusan itu ke tempat yang salah.
     */
    public function test_bahan_tanpa_baris_saldo_tak_dikarang_jadi_nol(): void
    {
        $ada = (string) Str::uuid();
        $belum = (string) Str::uuid();
        $this->saldo($ada, '10.000');

        $res = $this->minta([$ada, $belum])->assertOk();

        $this->assertCount(1, $res->json());
        $this->assertSame($ada, $res->json('0.ingredient_id'));
    }

    /**
     * Saldo SELALU per-outlet. Kebocoran di sini membuat menu cabang satu
     * dinilai memakai gudang cabang dua.
     */
    public function test_saldo_outlet_lain_tak_ikut_terbawa(): void
    {
        $kopi = (string) Str::uuid();
        $outletLain = (string) Str::uuid();
        $this->saldo($kopi, '999.000', $outletLain);

        $this->minta([$kopi])->assertOk()->assertJsonCount(0);
    }

    /** Tenant lain tak pernah terlihat, bahkan dengan id bahan yang benar. */
    public function test_saldo_tenant_lain_tak_ikut_terbawa(): void
    {
        $kopi = (string) Str::uuid();
        StockBalance::create([
            'tenant_id' => (string) Str::uuid(),
            'outlet_id' => $this->outlet,
            'ingredient_id' => $kopi,
            'qty_on_hand' => '999.000',
            'min_stock' => '0',
        ]);

        $this->minta([$kopi])->assertOk()->assertJsonCount(0);
    }

    public function test_tanpa_token_pintunya_tertutup(): void
    {
        $this->minta([(string) Str::uuid()], 'token-salah')->assertUnauthorized();
    }

    public function test_spasi_nyasar_di_daftar_id_tak_menghilangkan_saldo(): void
    {
        $kopi = (string) Str::uuid();
        $susu = (string) Str::uuid();
        $this->saldo($kopi, '1.000');
        $this->saldo($susu, '2.000');

        $query = http_build_query([
            'tenant' => $this->tenant,
            'outlet' => $this->outlet,
            'ids' => "{$kopi} , {$susu}",
        ]);

        $this->getJson("/api/balance?{$query}", ['X-Service-Token' => 'rahasia-uji'])
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_permintaan_tanpa_outlet_ditolak(): void
    {
        $query = http_build_query([
            'tenant' => $this->tenant,
            'ids' => (string) Str::uuid(),
        ]);

        $this->getJson("/api/balance?{$query}", ['X-Service-Token' => 'rahasia-uji'])
            ->assertStatus(422);
    }
}
