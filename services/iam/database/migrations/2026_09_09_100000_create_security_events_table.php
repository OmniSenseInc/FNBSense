<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak audit keamanan IAM.
     *
     * Dibuat untuk fase blue-team (pentest 2026-09-09): login gagal, throttle
     * yang terpicu, dan percobaan registrasi direkam di sini — bukti serangan
     * yang bisa dibaca owner (endpoint /api/security/events), bukan cuma
     * baris log yang hilang ikut container.
     */
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('tenant_id', 36)->nullable()->index();
            $table->string('type', 40)->index();
            $table->string('email', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};