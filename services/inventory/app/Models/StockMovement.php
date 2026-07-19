<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'ingredient_id',
        'order_id',
        'qty_delta',
        'reason',
        'occurred_at',
        'created_by',
    ];

    protected $casts = [
        'qty_delta' => 'decimal:3',
        'occurred_at' => 'datetime',
    ];
}
