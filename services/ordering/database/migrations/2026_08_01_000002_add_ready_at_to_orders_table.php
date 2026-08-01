<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "pesanan siap diantar".
 *
 * Kolom, BUKAN nilai baru di `orders.status` — dan ini bukan sekadar selera.
 * Kolom `status` memikul invarian uang: `confirmPayment()` menolak apa pun yang
 * bukan `pending`, `orders:expire` menyapu berdasarkan `pending`, dan `paid`
 * adalah terminal. Menaruh kabar dapur di situ berarti mengutak-atik jalur uang
 * demi sesuatu yang tak ada hubungannya dengan uang. Alasan yang sama persis
 * seperti `customer_claimed_paid_at`.
 *
 * Waktu, bukan boolean: pelanggan ingin tahu "sejak kapan siap", dan garis
 * kemajuan di layarnya menuliskan jamnya di bawah titik. Boolean membuang
 * keterangan itu tanpa menghemat apa pun.
 *
 * Sengaja diisi manusia — kasir hari ini, layar dapur (KDS) nanti. Keduanya
 * memakai kolom dan endpoint yang SAMA, jadi KDS tinggal dikaitkan tanpa
 * mengubah apa pun di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('ready_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ready_at');
        });
    }
};
