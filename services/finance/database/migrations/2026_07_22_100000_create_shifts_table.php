<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift kasir (F5c). Penjualan diatribusikan ke shift lewat RENTANG WAKTU
 * (outlet_id + paid_at antara opened_at..closed_at) — bukan FK di sales, jadi
 * consumer F5b tak tersentuh.
 *
 * Invarian: paling banyak SATU shift open per outlet (kalau dua window numpuk,
 * penjualan kehitung dobel di dua laporan). Dikunci DI DB lewat kolom `open_key`
 * = outlet_id saat open, NULL saat close, dengan UNIQUE. NULL boleh berulang →
 * banyak shift closed OK; tapi cuma satu yang boleh pegang open_key=outlet_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('outlet_id');

            $table->uuid('opened_by');                          // sub kasir dari JWT
            $table->unsignedBigInteger('opening_cash');         // modal awal laci (rupiah)
            $table->timestamp('opened_at');

            $table->uuid('closed_by')->nullable();
            $table->unsignedBigInteger('closing_cash')->nullable(); // kas fisik dihitung saat tutup
            $table->timestamp('closed_at')->nullable();

            $table->string('status')->default('open');          // open | closed (ShiftStatus)

            // Guard idempotensi buka: unik saat open, dilepas (NULL) saat close.
            $table->uuid('open_key')->nullable();

            $table->timestamps();

            $table->unique('open_key');                          // ← 1 shift open / outlet
            $table->index(['outlet_id', 'opened_at']);           // scan laporan per outlet
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
