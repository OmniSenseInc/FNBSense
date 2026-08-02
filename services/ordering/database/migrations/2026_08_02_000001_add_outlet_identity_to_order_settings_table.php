<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas outlet untuk kepala struk.
 *
 * Nama kafe tidak tersimpan di mana pun di sisi Ordering — yang ada baru tarif
 * dan QRIS. Akibatnya struk yang dipegang pelanggan lahir tanpa kepala.
 *
 * Menumpang `order_settings`, bukan tabel baru dan bukan diambil dari IAM saat
 * mencetak. Dua alasan: `outlet_id` di tabel ini sudah unique sehingga "satu
 * identitas per outlet" ditegakkan struktur yang ada, dan panggilan lintas-
 * service DI DETIK PENCETAKAN berarti struk gagal keluar setiap kali IAM
 * sedang lambat.
 *
 * Semuanya nullable: outlet yang sudah jalan tak punya baris ini sama sekali,
 * dan struk tanpa nama masih berguna — struk yang menolak keluar tidak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_settings', function (Blueprint $table) {
            $table->string('outlet_name', 60)->nullable()->after('outlet_id');
            $table->string('outlet_address', 120)->nullable()->after('outlet_name');
            $table->string('outlet_phone', 30)->nullable()->after('outlet_address');
        });
    }

    public function down(): void
    {
        Schema::table('order_settings', function (Blueprint $table) {
            $table->dropColumn(['outlet_name', 'outlet_address', 'outlet_phone']);
        });
    }
};
