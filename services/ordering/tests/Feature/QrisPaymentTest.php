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
use Tests\TestCase;

/**
 * QRIS statis di layar status pelanggan.
 *
 * Fokus gigi: QR HANYA muncul untuk order yang masih menunggu bayar. Dua
 * kerusakan yang dijaga di sini bukan soal tampilan, tapi soal uang —
 * membayar dua kali (status sudah paid), dan membayar untuk pesanan yang
 * sudah hangus (status expired). Penjaganya sengaja di server, supaya UI
 * tak punya kesempatan salah.
 */
class QrisPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const QRIS_A = 'https://contoh.test/qris/outlet-a.png';

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
    }

    private function saveSetting(?string $qris, ?string $outletId = null): void
    {
        $setting = new OrderSetting([
            'tax_percent' => 0,
            'service_charge_percent' => 0,
            'order_expiry_minutes' => 30,
            'qris_image_url' => $qris,
        ]);
        $setting->tenant_id = $this->tenantId;
        $setting->outlet_id = $outletId ?? $this->outletId;
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

    private function qrisDari(string $orderId): ?string
    {
        return $this->getJson("/api/orders/{$orderId}")
            ->assertOk()
            ->json('data.payment.qris_image_url');
    }

    public function test_qris_tampil_saat_order_menunggu_pembayaran(): void
    {
        $this->saveSetting(self::QRIS_A);

        $this->assertSame(self::QRIS_A, $this->qrisDari($this->buatOrder()));
    }

    /**
     * Order yang sudah dibayar TIDAK boleh memajang QR lagi. Kalau tampil,
     * pelanggan yang membuka ulang halamannya bisa membayar untuk kedua kali.
     */
    public function test_qris_disembunyikan_setelah_order_dibayar(): void
    {
        $this->saveSetting(self::QRIS_A);
        $id = $this->buatOrder();

        Order::query()->whereKey($id)->update(['status' => OrderStatus::Paid]);

        $this->assertNull($this->qrisDari($id));
    }

    /**
     * Yang paling mahal dari semuanya: uang masuk untuk pesanan yang sudah
     * hangus, dan kafe yang menanggung ributnya.
     */
    public function test_qris_disembunyikan_setelah_order_kedaluwarsa(): void
    {
        $this->saveSetting(self::QRIS_A);
        $id = $this->buatOrder();

        Order::query()->whereKey($id)->update(['status' => OrderStatus::Expired]);

        $this->assertNull($this->qrisDari($id));
    }

    public function test_qris_disembunyikan_setelah_order_dibatalkan(): void
    {
        $this->saveSetting(self::QRIS_A);
        $id = $this->buatOrder();

        Order::query()->whereKey($id)->update(['status' => OrderStatus::Cancelled]);

        $this->assertNull($this->qrisDari($id));
    }

    /**
     * Outlet yang belum memasang QRIS tetap bisa berjualan — layar pelanggan
     * jatuh ke instruksi bayar di kasir, bukan gagal memuat.
     */
    public function test_outlet_tanpa_qris_mengembalikan_null_bukan_galat(): void
    {
        $this->saveSetting(null);

        $this->assertNull($this->qrisDari($this->buatOrder()));
    }

    /** Outlet yang belum pernah dikonfigurasi sama sekali (nol baris setting). */
    public function test_outlet_tanpa_baris_setting_mengembalikan_null(): void
    {
        $this->assertNull($this->qrisDari($this->buatOrder()));
    }

    /**
     * QRIS outlet LAIN tak boleh nyasar ke sini. Kalau penyaring outlet_id
     * hilang, pelanggan membayar ke rekening kafe yang salah — kerusakan yang
     * tak pernah ketahuan dari layar, cuma dari rekening yang tak kunjung terisi.
     */
    public function test_qris_outlet_lain_tidak_bocor(): void
    {
        $this->saveSetting('https://contoh.test/qris/outlet-tetangga.png', (string) Str::uuid());
        $this->saveSetting(self::QRIS_A);

        $this->assertSame(self::QRIS_A, $this->qrisDari($this->buatOrder()));
    }
}
