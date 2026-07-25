<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status "dibaca" per-user. Inbox owner terpisah dari inbox tiap kasir:
 * masing-masing menandai bacaannya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            // user_id: referensi LOGIS ke IAM -> UUID tanpa FK.
            $table->uuid('user_id');
            $table->timestamp('read_at');
            // 1 user menandai 1 notif tepat sekali.
            $table->unique(['notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
    }
};
