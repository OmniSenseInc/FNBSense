<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga beli per SATUAN dasar bahan (g/ml/pcs), integer rupiah. Dasar hitung
 * harga pokok (HPP/COGS) per produk dari resepnya. 0 = belum diisi owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_per_unit')->default(0)->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('cost_per_unit');
        });
    }
};
