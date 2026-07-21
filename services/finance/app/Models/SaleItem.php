<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rincian item penjualan. product_id UUID milik Catalog (tanpa FK, beda DB).
 */
class SaleItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'sale_id',
        'product_id',
        'qty',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'integer',
        'line_total' => 'integer',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
