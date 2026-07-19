<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'ingredient_id',
        'qty_on_hand',
        'min_stock',
    ];

    protected $casts = [
        'qty_on_hand' => 'decimal:3',
        'min_stock' => 'decimal:3',
    ];
}
