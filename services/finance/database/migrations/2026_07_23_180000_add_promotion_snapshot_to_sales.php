<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->unsignedBigInteger('gross_subtotal')->nullable()->after('outlet_id');
            $table->unsignedBigInteger('discount_total')->default(0)->after('gross_subtotal');
            $table->uuid('promotion_id')->nullable()->after('grand_total');
            $table->string('promotion_name')->nullable()->after('promotion_id');
            $table->string('promotion_template')->nullable()->after('promotion_name');
            $table->index(['tenant_id', 'outlet_id', 'promotion_id'], 'sales_promotion_scope_index');
        });

        DB::table('sales')->whereNull('gross_subtotal')->update([
            'gross_subtotal' => DB::raw('subtotal'),
        ]);
        DB::statement('ALTER TABLE sales MODIFY gross_subtotal BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT chk_sales_discount CHECK (subtotal = gross_subtotal - discount_total AND discount_total <= gross_subtotal)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales DROP CHECK chk_sales_discount');

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_promotion_scope_index');
            $table->dropColumn([
                'gross_subtotal',
                'discount_total',
                'promotion_id',
                'promotion_name',
                'promotion_template',
            ]);
        });
    }
};
