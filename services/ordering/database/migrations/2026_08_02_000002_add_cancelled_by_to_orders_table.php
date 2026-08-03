<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siapa yang membatalkan pesanan. Kembaran confirmed_by yang sudah ada.
 *
 * Ditulis di sini, bukan cuma di dalam event `order.cancelled`: pesan bisa
 * gagal terkirim, antrean bisa dikuras, notifikasi bisa dihapus. Fakta audit
 * yang hidupnya cuma di dalam pesan bukan jejak audit. Ordering adalah pemilik
 * catatan pesanan, jadi di sinilah tempatnya.
 *
 * Tanpa FK: user tinggal di IAM, database terpisah — sepola confirmed_by.
 *
 * TIDAK ada `cancelled_at` yang menyertainya, dan itu disengaja: pembatalan
 * adalah mutasi terakhir yang mungkin terjadi pada sebuah pesanan, jadi
 * `updated_at` sudah menjawab "kapan" tanpa kolom kelima belas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('cancelled_by')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cancelled_by');
        });
    }
};
