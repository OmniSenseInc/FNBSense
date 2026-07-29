<?php

use App\Models\OrderSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alamat gambar QRIS statis milik outlet, untuk ditampilkan di layar status
 * pesanan pelanggan.
 *
 * Tinggal di order_settings, bukan di tabel baru: ini setelan per-outlet yang
 * dibaca saat transaksi, persis seperti tarif pajak di sebelahnya — dan
 * outlet_id di tabel ini sudah unique, jadi "satu QRIS per outlet" ditegakkan
 * struktur yang sudah ada tanpa menambah apa pun.
 *
 * NULLABLE dan itu disengaja: outlet yang belum memasang QRIS harus tetap bisa
 * berjualan. Layar pelanggan kembali menyuruh bayar di kasir, bukan gagal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_settings', function (Blueprint $table) {
            // Panjang dibaca dari konstanta model — sumber yang SAMA dengan
            // aturan UpdateSettingRequest, supaya batas DB dan batas validasi
            // tak pernah drift diam-diam.
            $table->string('qris_image_url', OrderSetting::MAX_QRIS_URL_LENGTH)
                ->nullable()
                ->after('order_expiry_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('order_settings', function (Blueprint $table) {
            $table->dropColumn('qris_image_url');
        });
    }
};
