<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('outlet_id');
            $table->uuid('ingredient_id'); // milik Catalog, tanpa FK (lintas-DB).
            $table->decimal('qty_on_hand', 14, 3)->default(0);
            $table->decimal('min_stock', 14, 3)->default(0); // low-stock; F8 Notification pakai nanti.
            $table->timestamps();

            // Satu saldo per bahan per outlet -> target lockForUpdate saat potong.
            $table->unique(['outlet_id', 'ingredient_id']);
        });

        // min_stock adalah ambang, tak masuk akal negatif. qty_on_hand SENGAJA tak dikunci
        // >=0 karena deduksi order boleh bikin negatif (saga stok kurang, sinyal jujur).
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT chk_min_stock_nonneg CHECK (min_stock >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
