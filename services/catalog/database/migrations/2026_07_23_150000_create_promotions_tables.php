<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('outlet_id');
            $table->string('name');
            $table->string('template');
            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('percentage')->nullable();
            $table->unsignedBigInteger('amount')->nullable();
            $table->unsignedBigInteger('min_subtotal')->default(0);
            $table->unsignedBigInteger('max_discount')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'outlet_id', 'status'], 'promotions_scope_status_idx');
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('promotion_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedSmallInteger('required_qty')->default(1);
            $table->timestamps();

            $table->unique(['promotion_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_products');
        Schema::dropIfExists('promotions');
    }
};
