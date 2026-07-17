<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // tenant_id/outlet_id: referensi LOGIS ke IAM (DB terpisah) -> UUID + index, TANPA FK.
            $table->uuid('tenant_id')->index();
            $table->uuid('outlet_id')->index();
            $table->string('label');
            // qr_token: rahasia-semu yang dicetak di QR. Unique supaya lookup /t/{qr_token}
            // tunggal, dan bisa dirotasi tanpa mengganti id meja.
            $table->string('qr_token', 32)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Label meja tidak boleh bentrok dalam satu outlet ("Meja 1" cuma satu).
            $table->unique(['tenant_id', 'outlet_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
