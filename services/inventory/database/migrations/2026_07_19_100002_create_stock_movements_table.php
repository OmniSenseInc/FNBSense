<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('outlet_id');
            $table->uuid('ingredient_id');
            $table->uuid('order_id')->nullable(); // null = opname/restock manual.
            $table->decimal('qty_delta', 14, 3);  // signed: negatif = potong.
            $table->enum('reason', ['order_deduction', 'manual_adjust', 'restock']);
            $table->dateTime('occurred_at');
            $table->uuid('created_by')->nullable(); // user; null = event.
            $table->timestamps();

            // Telusur cepat per outlet/bahan (rekonstruksi saldo) & per order (audit deduksi).
            $table->index(['outlet_id', 'ingredient_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
