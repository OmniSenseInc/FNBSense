<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siapa yang boleh melihat notifikasi ini: 'all' atau 'owner'.
 *
 * Sampai sekarang seluruh isi inbox boleh dibaca kasir maupun owner, dan untuk
 * alarm stok itu memang benar — kasirlah yang berdiri di depan lemari bahan.
 * Yang memaksa kolom ini lahir adalah notifikasi pembatalan pesanan: ia
 * melaporkan tindakan KASIR kepada owner, jadi orang yang dilaporkan tak boleh
 * ikut membacanya.
 *
 * Default 'all' supaya seluruh baris lama tetap terlihat persis seperti
 * sebelumnya — tak ada notifikasi yang diam-diam menghilang dari inbox
 * siapa pun gara-gara migrasi ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('audience')->default('all')->after('outlet_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
