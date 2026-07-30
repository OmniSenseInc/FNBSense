<?php

namespace Tests\Feature;

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
 * Niat bayar yang dipilih pelanggan di keranjang.
 *
 * Fokus gigi ada di satu invarian: niat TIDAK PERNAH menjadi bukti. Pelanggan
 * boleh memilih e-payment lalu membayar tunai di kasir, jadi `payment_method`
 * — satu-satunya yang mengalir ke laporan omzet dan event order.paid — harus
 * tetap kosong sampai kasir menyatakannya sendiri.
 */
class PaymentPreferenceTest extends TestCase
{
    use MintsToken, RefreshDatabase;

    private const QRIS = 'https://contoh.test/qris/outlet-a.png';

    private string $tenantId;

    private string $outletId;

    private string $kopiId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();
        $this->kopiId = (string) Str::uuid();

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
            'order_expiry_minutes' => 30,
            'qris_image_url' => self::QRIS,
        ]);
        $setting->tenant_id = $this->tenantId;
        $setting->outlet_id = $this->outletId;
        $setting->save();
    }

    /** @param array<string, mixed> $tambahan */
    private function pesan(array $tambahan = []): string
    {
        $table = Table::createForOutlet($this->tenantId, $this->outletId, [
            'label' => 'Meja '.Str::random(4),
        ]);

        return $this->postJson('/api/orders', array_merge([
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'items' => [['product_id' => $this->kopiId, 'qty' => 1]],
        ], $tambahan))->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function layarPelanggan(string $id): array
    {
        return $this->getJson("/api/orders/{$id}")->assertOk()->json('data.payment');
    }

    public function test_niat_bayar_tersimpan_dan_terlihat_di_layar_pelanggan(): void
    {
        $id = $this->pesan(['payment_preference' => 'qris_static']);

        $this->assertSame('qris_static', $this->layarPelanggan($id)['preference']);
    }

    /**
     * Inilah alasan kolomnya dipisah. Kalau niat ikut mengisi payment_method,
     * laporan omzet akan melaporkan apa yang DIMAU pelanggan sebagai uang yang
     * DITERIMA — padahal pesanan ini bahkan belum dibayar sama sekali.
     */
    public function test_niat_pelanggan_tidak_mengisi_cara_bayar_resmi(): void
    {
        $id = $this->pesan(['payment_preference' => 'cash']);

        $order = Order::query()->findOrFail($id);

        $this->assertNull($order->payment_method);
        $this->assertSame('cash', $order->payment_preference->value);
    }

    public function test_memilih_tunai_menyembunyikan_qris(): void
    {
        // Outlet ini PUNYA QRIS terpasang; yang menyembunyikannya adalah pilihan
        // pelanggan. Kalau QR tetap tampil, orang bisa memindainya lalu membayar
        // lagi di kasir — dua kali bayar untuk satu pesanan.
        $id = $this->pesan(['payment_preference' => 'cash']);

        $this->assertNull($this->layarPelanggan($id)['qris_image_url']);
    }

    public function test_memilih_e_payment_tetap_menampilkan_qris(): void
    {
        $id = $this->pesan(['payment_preference' => 'qris_static']);

        $this->assertSame(self::QRIS, $this->layarPelanggan($id)['qris_image_url']);
    }

    public function test_tanpa_memilih_apa_pun_pesanan_tetap_diterima(): void
    {
        // Klien versi lama, dan pelanggan yang belum memutuskan. Dua-duanya
        // bukan kesalahan — QR tetap disodorkan sebagai jalur bayar default.
        $payment = $this->layarPelanggan($this->pesan());

        $this->assertNull($payment['preference']);
        $this->assertSame(self::QRIS, $payment['qris_image_url']);
    }

    public function test_cara_bayar_di_luar_daftar_ditolak(): void
    {
        $table = Table::createForOutlet($this->tenantId, $this->outletId, ['label' => 'Meja Z']);

        $this->postJson('/api/orders', [
            'qr_token' => $table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
            'payment_preference' => 'kartu_kredit',
            'items' => [['product_id' => $this->kopiId, 'qty' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('payment_preference');
    }

    public function test_kasir_melihat_niat_bayar_pelanggan(): void
    {
        $this->pesan(['payment_preference' => 'cash']);

        $this->getJson('/api/cashier/orders?status=pending', $this->authHeaders(
            $this->tenantId,
            $this->outletId,
            'cashier',
        ))->assertOk()->assertJsonPath('data.0.payment_preference', 'cash');
    }
}
