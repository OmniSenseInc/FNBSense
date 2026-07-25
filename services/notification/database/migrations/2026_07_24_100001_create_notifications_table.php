<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox notifikasi (F8a). Satu baris per event alarm dari Inventory. Notif
 * di-scope per outlet; status "dibaca" per-user disimpan di notification_reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // tenant_id/outlet_id: referensi LOGIS ke IAM (DB terpisah) -> UUID, TANPA FK.
            $table->uuid('tenant_id');
            $table->uuid('outlet_id');
            $table->string('type');                       // low_stock | shortfall | recipe_missing
            $table->string('severity')->default('info');  // info | warning | critical
            $table->string('title');
            $table->text('body');
            $table->json('payload')->nullable();          // detail mentah dari event sumber
            // 1 event sumber = 1 notif (pagar idempoten kedua selain processed_events).
            $table->uuid('source_event_id')->unique();
            $table->timestamp('created_at');
            // Inbox selalu di-scope outlet + terbaru dulu.
            $table->index(['tenant_id', 'outlet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
