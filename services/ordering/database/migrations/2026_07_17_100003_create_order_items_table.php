<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Relasi INTERNAL Ordering -> FK, cascade: item ikut terhapus bila order dihapus.
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            // product_id: referensi LOGIS ke Catalog -> UUID, TANPA FK.
            $table->uuid('product_id');
            // Nama di-SNAPSHOT: struk lama tetap terbaca walau produk dihapus/di-rename di Catalog.
            $table->string('product_name');

            $table->unsignedBigInteger('unit_price'); // rupiah bulat, hasil snapshot
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('line_total');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // qty minimal 1 (baris item nol tak bermakna) & line_total konsisten
        // dengan unit_price * qty — dikunci di DB, bukan cuma di kalkulator.
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_qty CHECK (qty >= 1)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_line_total CHECK (line_total = unit_price * qty)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
