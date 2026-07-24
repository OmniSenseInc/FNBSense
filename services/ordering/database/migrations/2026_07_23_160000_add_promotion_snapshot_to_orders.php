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
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('gross_subtotal')->nullable()->after('status');
            $table->unsignedBigInteger('discount_total')->default(0)->after('gross_subtotal');
            $table->uuid('promotion_id')->nullable()->after('service_charge_percent');
            $table->json('promotion_snapshot')->nullable()->after('promotion_id');
            $table->index(['tenant_id', 'promotion_id']);
        });

        DB::table('orders')->whereNull('gross_subtotal')->update([
            'gross_subtotal' => DB::raw('subtotal'),
        ]);
        DB::statement('ALTER TABLE orders MODIFY gross_subtotal BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_orders_discount CHECK (subtotal = gross_subtotal - discount_total AND discount_total <= gross_subtotal)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CHECK chk_orders_discount');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'promotion_id']);
            $table->dropColumn([
                'gross_subtotal',
                'discount_total',
                'promotion_id',
                'promotion_snapshot',
            ]);
        });
    }
};
