<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan PAID (F5b) — satu baris per order.paid yang dikonsumsi. Semua angka
 * disalin dari amplop order.paid; Finance tak menghitung ulang uang, cuma mencatat.
 *
 * order_id UNIQUE = pagar idempotensi kedua (selain processed_orders): walau
 * event kembar lolos, DB menolak baris sales dobel. Uang tak boleh dihitung 2x.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_id')->unique();      // dari event; pagar anti-dobel
            $table->uuid('tenant_id');
            $table->uuid('outlet_id');

            // Rupiah integer (konsisten kontrak order.paid — tanpa desimal).
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('service_charge');
            $table->unsignedBigInteger('tax');
            $table->unsignedBigInteger('grand_total');

            $table->string('payment_method')->nullable(); // qris_static / cash
            $table->timestamp('paid_at');                  // occurred_at event
            $table->timestamps();

            // Laporan penjualan selalu di-scope outlet + rentang waktu.
            $table->index(['outlet_id', 'paid_at']);
        });

        // grand_total harus konsisten dengan komponennya (invarian uang di DB,
        // bukan cuma di kode) — sama pola dengan Ordering.
        DB::statement('ALTER TABLE sales ADD CONSTRAINT chk_sales_total CHECK (grand_total = subtotal + service_charge + tax)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
