<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian item per penjualan — dasar laporan produk terlaris & (nanti) COGS.
 * product_id UUID milik Catalog, tanpa FK (beda DB). line_total disalin dari event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->uuid('product_id');
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('unit_price'); // rupiah integer
            $table->unsignedBigInteger('line_total');
            $table->timestamps();

            $table->index('product_id'); // agregasi per produk lintas penjualan
        });

        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT chk_sale_items_line CHECK (line_total = unit_price * qty)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT chk_sale_items_qty CHECK (qty >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
