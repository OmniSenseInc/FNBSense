<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // tenant_id: referensi LOGIS ke IAM (DB terpisah) -> UUID + index, TANPA FK.
            $table->uuid('tenant_id')->index();
            $table->string('name');
            // Satuan dasar bahan. Stok fisik & resep pakai satuan ini.
            $table->enum('unit', ['g', 'ml', 'pcs']);
            $table->timestamps();

            // Nama bahan unik per tenant (anti duplikat "Kopi" dua kali).
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
