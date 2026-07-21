<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagar idempotensi konsumsi order.paid (F5b) — pola sama Inventory. order_id
 * (dari event) = PK: INSERT kedua atas order sama gagal unique → consumer ACK
 * tanpa mencatat penjualan dobel. Uang tak diproses dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_orders', function (Blueprint $table) {
            $table->string('order_id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('outlet_id');
            $table->string('status'); // recorded (F5b nanti isi)
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_orders');
    }
};
