<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sales_facts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_fact_id')->constrained('sales_facts')->cascadeOnDelete();
            $table->uuid('product_id');
            $table->string('product_name')->nullable();
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('line_total');
            $table->timestamps();
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sales_facts');
    }
};
