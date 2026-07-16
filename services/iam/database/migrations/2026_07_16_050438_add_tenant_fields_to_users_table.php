<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->after('tenant_id')->constrained('outlets')->nullOnDelete();
            $table->string('role')->default('cashier')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropConstrainedForeignId('outlet_id');
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
