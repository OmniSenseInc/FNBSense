<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderSetting;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

/**
 * Tombol "sudah bayar" pelanggan.
 *
 * Fokus gigi bukan pada penandanya, melainkan pada TENGGATnya. Klaim pertama
 * memutar ulang jam pasir supaya `orders:expire` tak menghanguskan pesanan yang
 * uangnya sudah masuk; klaim kedua TIDAK boleh memutarnya lagi, sebab kalau
 * boleh, satu orang bisa menahan pesanannya di antrean kasir selamanya.
 *
 * Yang juga dijaga di sini: klaim tak pernah menjadi syarat. Statusnya tetap
 * `pending`, jadi jalur konfirmasi kasir tak tersentuh sama sekali.
 */
class ClaimPaidTest extends TestCase
{
    use MintsToken, RefreshDatabase;

    private const EXPIRY_MENIT = 30;

    private string $tenantId;

    private string $outletId;

    private string $kopiId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();
        $this->kopiId = (string) Str::uuid();

        // Array cache dipakai throttle; flush biar counter tak nyangkut antar-test.
        Cache::flush();

        Http::fake([
            '*/api/menu*' => Http::response(['data' => [
                ['products' => [
                    ['id' => $this->kopiId, 'name' => 'Kopi Susu', 'price' => '20000.00'],
                ]],
            ]]),
        ]);

        $setting = new OrderSetting([
            'tax_percent' => 0,
            'service_charge_percent' => 0,
            'order_expiry_minutes' => self::EXPIRY_MENIT,
        ]);
        $setting->tenant_id = $this->tenantId;
        $setting->outlet_id = $this->outletId;
        $setting->save();
    }

    /** Buat order lewat endpoint sungguhan, kembalikan id-nya. */
    private function buatOrder(): string
    {
        $table = Table::createForOutlet($this->tenantId, $this->outletId, ['label' => 'Meja 1']);

        return $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => $this->kopiId, 'qty' => 1]],
        ])->assertCreated()->json('data.id');
    }

    public function test_klaim_pertama_menandai_pesanan_dan_memutar_ulang_tenggat(): void
    {
        $id = $this->buatOrder();
        $tenggatAwal = Order::query()->findOrFail($id)->expires_at;

        // Waktu berjalan dulu: tanpa ini tenggat baru jatuh di detik yang sama
        // dengan tenggat lama, dan test-nya lolos tanpa membuktikan apa pun.
        $this->travel(10)->minutes();

        $this->postJson("/api/orders/{$id}/claim-paid")
            ->assertOk()
            // Statusnya TETAP pending — bukan status baru. Ini yang menjaga
            // kasir tetap bisa mengonfirmasi pesanan ini.
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('data.payment.claimed_at', fn ($nilai) => $nilai !== null);

        $order = Order::query()->findOrFail($id);

        $this->assertNotNull($order->customer_claimed_paid_at);
        $this->assertTrue(
            $order->expires_at->greaterThan($tenggatAwal),
            'Tenggat harus mundur setelah klaim pertama.',
        );
    }

    /**
     * Penjaga anti-ulang (JEBAKAN 2). Cabut cabang "sudah pernah diklaim" di
     * `claimPaid()` dan test ini merah: tenggatnya ikut mundur untuk kedua
     * kalinya, dan satu orang bisa menahan pesanannya di antrean selamanya.
     */
    public function test_klaim_kedua_tidak_memutar_tenggat_lagi(): void
    {
        $id = $this->buatOrder();

        $this->postJson("/api/orders/{$id}/claim-paid")->assertOk();
        $sesudahKlaimPertama = Order::query()->findOrFail($id);

        $this->travel(5)->minutes();
        $this->postJson("/api/orders/{$id}/claim-paid")->assertOk();

        $sesudahKlaimKedua = Order::query()->findOrFail($id);

        $this->assertTrue(
            $sesudahKlaimKedua->expires_at->equalTo($sesudahKlaimPertama->expires_at),
            'Klaim kedua tidak boleh menggeser tenggat.',
        );
        $this->assertTrue(
            $sesudahKlaimKedua->customer_claimed_paid_at
                ->equalTo($sesudahKlaimPertama->customer_claimed_paid_at),
            'Jam klaim yang tercatat adalah jam laporan PERTAMA.',
        );
    }

    /**
     * Pesanan yang sudah dibayar tak lagi menunggu apa pun. Membiarkannya
     * "diklaim" cuma memindahkan kartu yang sudah selesai ke pucuk antrean.
     */
    public function test_pesanan_yang_sudah_dibayar_menolak_klaim(): void
    {
        $id = $this->buatOrder();
        Order::query()->whereKey($id)->update(['status' => OrderStatus::Paid]);

        $this->postJson("/api/orders/{$id}/claim-paid")->assertStatus(409);
    }

    /**
     * Yang hangus tak boleh dihidupkan lewat pintu ini. Kalau bisa, tenggatnya
     * mundur 30 menit untuk pesanan yang sudah disapu `orders:expire` — dan
     * kasir melihat kartu yang seharusnya sudah hilang muncul lagi di pucuk.
     */
    public function test_pesanan_kedaluwarsa_menolak_klaim(): void
    {
        $id = $this->buatOrder();
        Order::query()->whereKey($id)->update(['status' => OrderStatus::Expired]);

        $this->postJson("/api/orders/{$id}/claim-paid")->assertStatus(409);

        $this->assertNull(Order::query()->findOrFail($id)->customer_claimed_paid_at);
    }

    public function test_pesanan_tak_dikenal_menjawab_404(): void
    {
        $this->postJson('/api/orders/'.Str::uuid().'/claim-paid')->assertNotFound();
    }

    /**
     * Tanpa test ini, penanda boleh saja tersimpan rapi di DB dan tak pernah
     * sampai ke layar kasir — badge-nya diam-diam tak pernah muncul, dan tak
     * ada satu pun test yang merah.
     */
    public function test_kasir_melihat_penanda_klaim_di_antrean(): void
    {
        $id = $this->buatOrder();
        $this->postJson("/api/orders/{$id}/claim-paid")->assertOk();

        $headers = ['Authorization' => 'Bearer '.$this->mintToken(
            $this->tenantId, $this->outletId, 'cashier', (string) Str::uuid(),
        )];

        $this->withHeaders($headers)
            ->getJson('/api/cashier/orders')
            ->assertOk()
            ->assertJsonPath('data.0.customer_claimed_paid_at', fn ($nilai) => $nilai !== null);
    }
}
