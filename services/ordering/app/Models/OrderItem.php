<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris pesanan. Semua nilainya (harga, nama, total) di-SNAPSHOT server-side
 * oleh kalkulator saat order dibuat — tak pernah langsung dari body request.
 * product_name disimpan supaya struk lama tetap terbaca walau produk berubah
 * di Catalog.
 */
class OrderItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'product_name',
        'unit_price',
        'qty',
        'line_total',
        'unit_cost',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'qty' => 'integer',
            'line_total' => 'integer',
            'unit_cost' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
