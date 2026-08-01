<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "pelanggan mengaku sudah transfer".
 *
 * SENGAJA sebuah kolom, BUKAN status baru. `CashierOrderController` menegakkan
 * aturan "hanya PENDING yang boleh menjadi PAID"; status seperti
 * AWAITING_VERIFICATION akan membuat kasir tak bisa mengonfirmasi justru
 * pesanan yang paling mungkin uangnya sudah masuk — kebalikan dari tujuan
 * fitur ini.
 *
 * Sekali terisi ia tak pernah kembali null, dan itu disengaja: kolom ini juga
 * penjaga anti-ulang. Klaim kedua tak boleh memperpanjang tenggat lagi, dan
 * kolom inilah yang membedakan klaim pertama dari klaim berikutnya tanpa
 * tabel tambahan.
 *
 * Nol index: tak ada query yang memfilter atau mengurutkan berdasarkan kolom
 * ini. Antrean kasir tetap diambil apa adanya per outlet; pengangkatan
 * pesanan yang mengklaim ke pucuk dikerjakan di layar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('customer_claimed_paid_at')->nullable()->after('payment_preference');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_claimed_paid_at');
        });
    }
};
