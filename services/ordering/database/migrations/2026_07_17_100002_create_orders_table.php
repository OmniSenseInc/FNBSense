<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Referensi LOGIS ke IAM (DB terpisah) -> UUID + index, TANPA FK.
            $table->uuid('tenant_id');
            $table->uuid('outlet_id');
            // table_id: relasi INTERNAL Ordering -> boleh FK. Null utk takeaway.
            $table->foreignUuid('table_id')->nullable()->constrained('tables')->nullOnDelete();

            $table->string('order_number');
            $table->string('order_type');        // enum OrderType (dine_in/takeaway)
            $table->string('customer_name');
            $table->string('status')->default('pending'); // enum OrderStatus

            // Uang: rupiah BULAT, unsignedBigInteger -> mustahil negatif by type.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('service_charge');
            $table->unsignedBigInteger('tax');
            $table->unsignedBigInteger('grand_total');

            // Tarif di-SNAPSHOT ke baris ini: ubah tarif besok != ubah struk kemarin.
            $table->decimal('tax_percent', 5, 2);
            $table->decimal('service_charge_percent', 5, 2);

            $table->string('payment_method')->nullable(); // enum PaymentMethod, diisi saat PAID
            $table->uuid('confirmed_by')->nullable();      // kasir yang mengonfirmasi (UUID IAM)
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expires_at');
            $table->text('note')->nullable();
            $table->timestamps();

            // Antrean kasir per outlet berdasarkan status.
            $table->index(['tenant_id', 'outlet_id', 'status']);
            // Scheduler kedaluwarsa: cari PENDING yang lewat expires_at.
            $table->index(['status', 'expires_at']);
            // Nomor order tak boleh dobel dalam satu outlet.
            $table->unique(['outlet_id', 'order_number']);
        });

        // Kunci konsistensi aritmatika di DB, bukan cuma percaya kalkulator:
        // grand_total WAJIB = subtotal + service_charge + tax. Kalkulator bug
        // yang menghasilkan total tak konsisten -> insert gagal, bukan struk salah.
        DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_orders_grand_total CHECK (grand_total = subtotal + service_charge + tax)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
