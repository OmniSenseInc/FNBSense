<?php

namespace Tests\Feature;

use App\Models\OrderSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use MintsToken, RefreshDatabase;

    private string $tenantId;

    private string $outletId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();
    }

    /**
     * Outlet baru belum punya baris setting. Membacanya harus mengembalikan
     * tarif 0 — dan TIDAK boleh diam-diam menulis baris ke DB.
     */
    public function test_outlet_belum_dikonfigurasi_dapat_tarif_nol(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId))
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.tax_percent', '0.00')
            ->assertJsonPath('data.service_charge_percent', '0.00')
            ->assertJsonPath('data.order_expiry_minutes', OrderSetting::DEFAULT_EXPIRY_MINUTES);

        $this->assertDatabaseCount('order_settings', 0);
    }

    /** Simpan pertama kali: baris lahir di sini. */
    public function test_owner_bisa_menyimpan_tarif(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId))
            ->putJson('/api/settings', ['tax_percent' => 11, 'service_charge_percent' => 5])
            ->assertOk()
            ->assertJsonPath('data.tax_percent', '11.00')
            ->assertJsonPath('data.service_charge_percent', '5.00');

        $this->assertDatabaseHas('order_settings', [
            'tenant_id' => $this->tenantId,
            'outlet_id' => $this->outletId,
            'tax_percent' => 11.00,
        ]);
    }

    /** Simpan kedua kali harus MENGUBAH baris yang sama, bukan bikin baris baru. */
    public function test_simpan_ulang_tidak_menggandakan_baris(): void
    {
        $headers = $this->authHeaders($this->tenantId, $this->outletId);

        $this->withHeaders($headers)->putJson('/api/settings', ['tax_percent' => 11])->assertOk();
        $this->withHeaders($headers)->putJson('/api/settings', ['tax_percent' => 12])->assertOk();

        $this->assertDatabaseCount('order_settings', 1);
        $this->assertDatabaseHas('order_settings', ['outlet_id' => $this->outletId, 'tax_percent' => 12.00]);
    }

    /** Salah ketik ordo besar (11 -> 110) harus ditolak 422, bukan menagih 110%. */
    public function test_tarif_di_atas_batas_ditolak(): void
    {
        $headers = $this->authHeaders($this->tenantId, $this->outletId);

        $this->withHeaders($headers)->putJson('/api/settings', ['tax_percent' => 110])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax_percent');

        $this->withHeaders($headers)->putJson('/api/settings', ['service_charge_percent' => 110])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_charge_percent');

        // Tepat di atas batas pun ditolak — batasnya inklusif, bukan kira-kira.
        $this->withHeaders($headers)->putJson('/api/settings', ['tax_percent' => OrderSetting::MAX_TAX_PERCENT + 1])
            ->assertStatus(422);

        $this->assertDatabaseCount('order_settings', 0);
    }

    /** Tarif tepat di batas harus tetap boleh. */
    public function test_tarif_tepat_di_batas_diterima(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId))
            ->putJson('/api/settings', ['tax_percent' => OrderSetting::MAX_TAX_PERCENT])
            ->assertOk();
    }

    /** Tarif negatif = mengembalikan uang ke customer. Tolak. */
    public function test_tarif_negatif_ditolak(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId))
            ->putJson('/api/settings', ['tax_percent' => -1])
            ->assertStatus(422);
    }

    /** Setting outlet lain tak boleh tertimpa — tiap outlet barisnya sendiri. */
    public function test_setting_outlet_lain_tidak_tertimpa(): void
    {
        $outletLain = (string) Str::uuid();

        $this->withHeaders($this->authHeaders($this->tenantId, $outletLain))
            ->putJson('/api/settings', ['tax_percent' => 11])
            ->assertOk();

        $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId))
            ->putJson('/api/settings', ['tax_percent' => 5])
            ->assertOk();

        $this->assertDatabaseCount('order_settings', 2);
        $this->assertDatabaseHas('order_settings', ['outlet_id' => $outletLain, 'tax_percent' => 11.00]);
        $this->assertDatabaseHas('order_settings', ['outlet_id' => $this->outletId, 'tax_percent' => 5.00]);
    }

    /** Kasir tak boleh mengubah tarif. */
    public function test_kasir_tidak_boleh_mengubah_tarif(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId, 'cashier'))
            ->putJson('/api/settings', ['tax_percent' => 0])
            ->assertForbidden();
    }

    /** Aturan outlet 403 hidup di base Controller — buktikan berlaku di sini juga. */
    public function test_akun_tanpa_outlet_ditolak(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, null))
            ->getJson('/api/settings')
            ->assertForbidden();

        $this->withHeaders($this->authHeaders($this->tenantId, null))
            ->putJson('/api/settings', ['tax_percent' => 11])
            ->assertForbidden();
    }

    /** Tanpa token -> 401, bukan 500. */
    public function test_tanpa_token_ditolak_401(): void
    {
        $this->getJson('/api/settings')->assertUnauthorized();
    }

    /** Expiry 0 = order mati seketika; setahun = antrean kasir tak pernah bersih. */
    public function test_expiry_di_luar_batas_ditolak(): void
    {
        $headers = $this->authHeaders($this->tenantId, $this->outletId);

        $this->withHeaders($headers)
            ->putJson('/api/settings', ['order_expiry_minutes' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_expiry_minutes');

        $this->withHeaders($headers)
            ->putJson('/api/settings', ['order_expiry_minutes' => OrderSetting::MAX_EXPIRY_MINUTES + 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_expiry_minutes');

        $this->withHeaders($headers)
            ->putJson('/api/settings', ['order_expiry_minutes' => OrderSetting::MAX_EXPIRY_MINUTES])
            ->assertOk();
    }
}
