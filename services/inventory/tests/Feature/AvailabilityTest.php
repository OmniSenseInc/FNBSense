<?php

namespace Tests\Feature;

use App\Exceptions\CatalogUnavailableException;
use App\Models\StockBalance;
use App\Services\CatalogRecipeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gerbang stok (POST /api/availability) — dipanggil Ordering SEBELUM order
 * dibuat. Fokus gigi: kebutuhan digabung lintas baris & lintas produk, bahan
 * tanpa saldo dihitung nol, isolasi outlet, dan pintunya tertutup tanpa token.
 */
class AvailabilityTest extends TestCase
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

    /** @param array<string, array<int, array{ingredient_id: string, qty_per_unit: mixed, unit: ?string}>> $resep */
    private function catalogPalsu(array $resep): void
    {
        $palsu = new class($resep) extends CatalogRecipeClient
        {
            public function __construct(private array $resep) {}

            public function recipesForProducts(string $tenantId, array $productIds): array
            {
                return $this->resep;
            }
        };

        $this->app->instance(CatalogRecipeClient::class, $palsu);
    }

    private function saldo(string $ingredientId, float $qty): void
    {
        StockBalance::create([
            'tenant_id' => $this->tenant,
            'outlet_id' => $this->outlet,
            'ingredient_id' => $ingredientId,
            'qty_on_hand' => $qty,
        ]);
    }

    /** @param array<int, array{product_id: string, qty: int}> $items */
    private function tanya(array $items, string $token = 'rahasia-uji')
    {
        return $this->postJson('/api/availability', [
            'tenant_id' => $this->tenant,
            'outlet_id' => $this->outlet,
            'items' => $items,
        ], ['X-Service-Token' => $token]);
    }

    public function test_bahan_cukup_maka_tak_ada_yang_habis(): void
    {
        $produk = (string) Str::uuid();
        $bahan = (string) Str::uuid();
        $this->catalogPalsu([$produk => [['ingredient_id' => $bahan, 'qty_per_unit' => 10, 'unit' => 'g']]]);
        $this->saldo($bahan, 100);

        $this->tanya([['product_id' => $produk, 'qty' => 2]])
            ->assertOk()
            ->assertJsonPath('data.unavailable', []);
    }

    public function test_bahan_kurang_maka_produknya_disebut(): void
    {
        $produk = (string) Str::uuid();
        $bahan = (string) Str::uuid();
        $this->catalogPalsu([$produk => [['ingredient_id' => $bahan, 'qty_per_unit' => 10, 'unit' => 'g']]]);
        $this->saldo($bahan, 15); // butuh 20

        $this->tanya([['product_id' => $produk, 'qty' => 2]])
            ->assertOk()
            ->assertJsonPath('data.unavailable', [$produk]);
    }

    /**
     * Dua BARIS produk yang sama digabung. Tanpa penggabungan, tiap baris
     * diperiksa sendiri (1×10 <= 15, lolos) padahal totalnya 20 dan tak cukup.
     */
    public function test_dua_baris_produk_sama_dijumlahkan(): void
    {
        $produk = (string) Str::uuid();
        $bahan = (string) Str::uuid();
        $this->catalogPalsu([$produk => [['ingredient_id' => $bahan, 'qty_per_unit' => 10, 'unit' => 'g']]]);
        $this->saldo($bahan, 15);

        $this->tanya([
            ['product_id' => $produk, 'qty' => 1],
            ['product_id' => $produk, 'qty' => 1],
        ])
            ->assertOk()
            ->assertJsonPath('data.unavailable', [$produk]);
    }

    /**
     * Dua PRODUK berbeda yang berbagi satu bahan. Masing-masing muat sendiri,
     * bersama-sama tidak — dan keduanya harus ikut disebut, bukan salah satu.
     */
    public function test_dua_produk_berbagi_bahan_dijumlahkan(): void
    {
        $kopi = (string) Str::uuid();
        $latte = (string) Str::uuid();
        $biji = (string) Str::uuid();
        $this->catalogPalsu([
            $kopi => [['ingredient_id' => $biji, 'qty_per_unit' => 10, 'unit' => 'g']],
            $latte => [['ingredient_id' => $biji, 'qty_per_unit' => 10, 'unit' => 'g']],
        ]);
        $this->saldo($biji, 15);

        $res = $this->tanya([
            ['product_id' => $kopi, 'qty' => 1],
            ['product_id' => $latte, 'qty' => 1],
        ])->assertOk();

        $habis = $res->json('data.unavailable');
        sort($habis);
        $harusnya = [$kopi, $latte];
        sort($harusnya);
        $this->assertSame($harusnya, $habis);
    }

    /** Bahan yang belum pernah distok = nol, bukan "tak diawasi". */
    public function test_bahan_tanpa_baris_saldo_dianggap_nol(): void
    {
        $produk = (string) Str::uuid();
        $this->catalogPalsu([$produk => [['ingredient_id' => (string) Str::uuid(), 'qty_per_unit' => 1, 'unit' => 'g']]]);

        $this->tanya([['product_id' => $produk, 'qty' => 1]])
            ->assertOk()
            ->assertJsonPath('data.unavailable', [$produk]);
    }

    /** Produk tanpa resep tak punya bahan -> tak ada yang membatasinya. */
    public function test_produk_tanpa_resep_lolos(): void
    {
        $this->catalogPalsu([]);

        $this->tanya([['product_id' => (string) Str::uuid(), 'qty' => 99]])
            ->assertOk()
            ->assertJsonPath('data.unavailable', []);
    }

    /** Saldo outlet LAIN tak boleh ikut menutupi kekurangan di outlet ini. */
    public function test_isolasi_outlet(): void
    {
        $produk = (string) Str::uuid();
        $bahan = (string) Str::uuid();
        $this->catalogPalsu([$produk => [['ingredient_id' => $bahan, 'qty_per_unit' => 10, 'unit' => 'g']]]);

        StockBalance::create([
            'tenant_id' => $this->tenant,
            'outlet_id' => (string) Str::uuid(), // outlet lain, stok melimpah
            'ingredient_id' => $bahan,
            'qty_on_hand' => 9999,
        ]);

        $this->tanya([['product_id' => $produk, 'qty' => 1]])
            ->assertOk()
            ->assertJsonPath('data.unavailable', [$produk]);
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->catalogPalsu([]);

        $this->tanya([['product_id' => (string) Str::uuid(), 'qty' => 1]], 'token-salah')
            ->assertStatus(401);
    }

    /** Catalog mati -> 503, BUKAN "semua tersedia". */
    public function test_catalog_mati_menolak_menjawab(): void
    {
        $palsu = new class extends CatalogRecipeClient
        {
            public function __construct() {}

            public function recipesForProducts(string $tenantId, array $productIds): array
            {
                throw new CatalogUnavailableException('mati');
            }
        };
        $this->app->instance(CatalogRecipeClient::class, $palsu);

        $this->tanya([['product_id' => (string) Str::uuid(), 'qty' => 1]])
            ->assertStatus(503);
    }
}
