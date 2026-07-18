<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type');   // "order"
            $table->uuid('aggregate_id');        // id order-nya
            $table->string('event_type');        // "order.paid"
            $table->json('payload');
            $table->timestamp('occurred_at');
            // null = belum di-relay ke RabbitMQ. Menumpuk null itu NORMAL sampai
            // ada consumer (F3/F4) — barisnya yang mengunci invarian, relay nyusul.
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // Relay worker nanti memindai baris yang published_at masih null.
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
