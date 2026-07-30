<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cara bayar yang DIINGINKAN pelanggan saat memesan.
 *
 * Kolom terpisah dari `payment_method`, dan pemisahan itu inti dari perubahan
 * ini — bukan kerapian. `payment_method` adalah pernyataan kasir bahwa uang
 * benar-benar diterima; dialah yang mengalir ke laporan omzet dan ke event
 * `order.paid`. Kolom baru ini cuma niat: orang boleh memilih "e-payment" di
 * meja lalu akhirnya membayar tunai, dan itu wajar.
 *
 * Menyatukan keduanya akan membuat laporan mengatakan "80% QRIS hari ini"
 * berdasarkan apa yang DIMAU pelanggan, bukan apa yang DITERIMA kasir.
 *
 * Nullable, dan sengaja tetap nullable: order yang lahir sebelum fitur ini ada
 * tak punya niat yang tercatat, dan pelanggan yang tak memilih apa pun bukan
 * sebuah kesalahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_preference', 20)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_preference');
        });
    }
};
