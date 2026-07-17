<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // tenant_id: referensi LOGIS ke IAM (DB terpisah) -> UUID + index, TANPA FK.
            $table->uuid('tenant_id')->index();
            // category_id: relasi INTERNAL Catalog -> boleh FK. Nullable (produk tanpa kategori).
            $table->foreignUuid('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->boolean('is_available')->default(true);
            $table->string('image_url')->nullable();
            $table->timestamps();

            // Query menu publik: produk aktif milik tenant.
            $table->index(['tenant_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
