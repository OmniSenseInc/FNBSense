<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_orders', function (Blueprint $table) {
            // order_id = PK & pagar idempotensi: satu order PAID hanya diproses sekali.
            $table->uuid('order_id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('outlet_id');
            $table->enum('status', ['deducted', 'shortfall', 'recipe_missing']);
            $table->dateTime('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_orders');
    }
};
