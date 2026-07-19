<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // tenant_id: referensi LOGIS ke IAM (DB terpisah) -> UUID + index, TANPA FK.
            $table->uuid('tenant_id')->index();
            // Relasi INTERNAL Catalog -> FK. Resep tak valid tanpa produk/bahan -> cascade.
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            // Berapa `unit` bahan per 1 produk (mis. 18.000 g kopi per 1 espresso).
            $table->decimal('qty_per_unit', 14, 3);
            $table->timestamps();

            // Satu bahan hanya boleh muncul sekali di resep sebuah produk.
            $table->unique(['product_id', 'ingredient_id']);
        });

        // Invarian di DB, bukan cuma FormRequest: takaran resep harus positif.
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT chk_recipes_qty_positive CHECK (qty_per_unit > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
