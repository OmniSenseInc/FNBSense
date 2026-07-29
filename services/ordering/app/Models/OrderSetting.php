<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tarif transaksi milik satu outlet: PPN, service charge, masa berlaku order.
 *
 * Tarif adalah aturan TRANSAKSI, bukan identitas -> tinggal di Ordering, bukan IAM.
 * Yang menghitung total harus memiliki angkanya dan tidak boleh gantung ke service
 * lain saat menghitung. Nilai di sini di-SNAPSHOT ke baris order saat order dibuat:
 * owner mengubah tarif besok tidak boleh mengubah struk kemarin.
 */
class OrderSetting extends Model
{
    use HasUuids;

    /** Order PENDING lewat dari ini dianggap ditinggal pergi -> EXPIRED. */
    public const DEFAULT_EXPIRY_MINUTES = 30;

    /** Batas atas waras masa berlaku order (1 hari). */
    public const MAX_EXPIRY_MINUTES = 1440;

    /**
     * Batas atas tarif. Realita F&B Indonesia: pajak restoran (PB1) 10%,
     * service charge 5-10%. Batas 30% memberi ruang kalau regulasi berubah,
     * tapi menolak salah-ketik ordo besar (11 -> 110 -> 1100) yang menagih
     * customer berlipat ganda.
     *
     * SATU SUMBER untuk tiga tempat: CHECK constraint DB (penegak sebenarnya),
     * aturan UpdateSettingRequest (pesan 422 yang ramah), dan test.
     */
    public const MAX_TAX_PERCENT = 30;

    public const MAX_SERVICE_CHARGE_PERCENT = 30;

    /**
     * Batas panjang alamat gambar QRIS. Satu sumber untuk dua tempat: lebar
     * kolom di migration dan aturan UpdateSettingRequest.
     *
     * 1024 memberi ruang untuk URL bertanda tangan (panjang karena membawa
     * kedaluwarsa & tanda tangan di query string), tanpa membiarkan seseorang
     * menjejalkan data gambar utuh ke dalam kolom teks.
     */
    public const MAX_QRIS_URL_LENGTH = 1024;

    /**
     * tenant_id & outlet_id TIDAK fillable: keduanya berasal dari klaim JWT,
     * diisi eksplisit di controller. Kalau fillable, owner bisa mengirim
     * outlet_id milik siapa pun lewat body dan menulis tarif outlet orang lain.
     */
    protected $fillable = [
        'tax_percent',
        'service_charge_percent',
        'order_expiry_minutes',
        // Aman di-fillable: ini data TAMPILAN milik outlet, bukan penentu
        // kepemilikan seperti tenant_id/outlet_id di atas. Outlet mana yang
        // ditulis tetap ditentukan klaim JWT di controller.
        'qris_image_url',
    ];

    protected function casts(): array
    {
        return [
            'tax_percent' => 'decimal:2',
            'service_charge_percent' => 'decimal:2',
            'order_expiry_minutes' => 'integer',
        ];
    }

    /**
     * Setting bayangan (belum tersimpan) untuk outlet yang belum pernah
     * dikonfigurasi owner. Sengaja bertarif 0 — menebak 11% berarti menarik
     * uang customer atas nama aturan yang tak pernah ditetapkan owner.
     */
    public static function defaultsFor(string $tenantId, string $outletId): self
    {
        $setting = new self([
            'tax_percent' => 0,
            'service_charge_percent' => 0,
            'order_expiry_minutes' => self::DEFAULT_EXPIRY_MINUTES,
            // Belum dikonfigurasi = belum ada QRIS. Layar pelanggan jatuh ke
            // instruksi bayar di kasir, bukan menampilkan kotak kosong.
            'qris_image_url' => null,
        ]);

        // Non-fillable -> harus di-set eksplisit, tidak lewat konstruktor.
        $setting->tenant_id = $tenantId;
        $setting->outlet_id = $outletId;

        return $setting;
    }
}
