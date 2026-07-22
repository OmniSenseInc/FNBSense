<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengeluaran outlet (F5d) — kas keluar yang dicatat kasir/owner (beli bahan,
 * bayar galon, dll). Diatribusikan ke laporan lewat RENTANG WAKTU (outlet_id +
 * spent_at), sama pola dengan sales — bukan FK ke shift, jadi lintas-shift/harian.
 *
 * amount rupiah integer > 0 (kunci di DB, bukan cuma FormRequest — invarian uang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('outlet_id');

            $table->string('category');                     // bahan | operasional | gaji | lain
            $table->unsignedBigInteger('amount');           // rupiah keluar
            $table->string('note')->nullable();
            $table->timestamp('spent_at');                  // kapan uang keluar (boleh backdate)
            $table->uuid('recorded_by');                    // sub pencatat dari JWT

            $table->timestamps();

            $table->index(['outlet_id', 'spent_at']);       // scan laporan per outlet+rentang
        });

        // Pengeluaran nol/negatif tak bermakna — tolak di DB.
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT chk_expenses_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
