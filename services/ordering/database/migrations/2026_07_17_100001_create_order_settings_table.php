<?php

use App\Models\OrderSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Referensi LOGIS ke IAM (DB terpisah) -> UUID, TANPA FK.
            $table->uuid('tenant_id')->index();
            // Satu baris setting per outlet -> unique, bukan sekadar index.
            $table->uuid('outlet_id')->unique();
            // Tarif default 0: outlet yang belum dikonfigurasi TIDAK memungut apa pun.
            // Lebih baik gagal-tertutup (tak menarik uang) daripada menebak tarif.
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('service_charge_percent', 5, 2)->default(0);
            // Sumber tunggal, biar default DB & default model tak drift diam-diam.
            $table->unsignedInteger('order_expiry_minutes')->default(OrderSetting::DEFAULT_EXPIRY_MINUTES);
            $table->timestamps();
        });

        // Penegak tarif yang SEBENARNYA. Validasi FormRequest cuma menjaga pintu
        // HTTP; ini menutup semua jalur lain (tinker, seeder, raw SQL, controller
        // yang belum ditulis). Tarif ngaco tidak "ditolak sopan" — insert-nya
        // gagal di MySQL. Batas dibaca dari konstanta model supaya tidak drift.
        // Ditegakkan MySQL >= 8.0.16; server dev/prod kita 8.0.30.
        DB::statement(sprintf(
            'ALTER TABLE order_settings ADD CONSTRAINT chk_order_settings_tax_percent CHECK (tax_percent >= 0 AND tax_percent <= %d)',
            OrderSetting::MAX_TAX_PERCENT,
        ));

        DB::statement(sprintf(
            'ALTER TABLE order_settings ADD CONSTRAINT chk_order_settings_service_charge_percent CHECK (service_charge_percent >= 0 AND service_charge_percent <= %d)',
            OrderSetting::MAX_SERVICE_CHARGE_PERCENT,
        ));

        // Expiry ikut dikunci di DB, bukan cuma di validasi HTTP — kalau tidak,
        // klaim "satu sumber untuk tiga tempat" di OrderSetting cuma separuh benar.
        // Expiry 0 = order mati seketika; expiry setahun = antrean kasir tak pernah bersih.
        DB::statement(sprintf(
            'ALTER TABLE order_settings ADD CONSTRAINT chk_order_settings_expiry_minutes CHECK (order_expiry_minutes >= 1 AND order_expiry_minutes <= %d)',
            OrderSetting::MAX_EXPIRY_MINUTES,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('order_settings');
    }
};
