<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_facts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->string('order_id')->unique();
            $table->uuid('tenant_id');
            $table->uuid('outlet_id');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('service_charge');
            $table->unsignedBigInteger('tax');
            $table->unsignedBigInteger('grand_total');
            $table->string('payment_method')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->index(['tenant_id', 'outlet_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_facts');
    }
};
