<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pagar idempotensi konsumsi order.paid. order_id (dari event) = PK, bukan auto-UUID.
 */
class ProcessedOrder extends Model
{
    protected $primaryKey = 'order_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false; // hanya processed_at eksplisit.

    protected $fillable = [
        'order_id',
        'tenant_id',
        'outlet_id',
        'status',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
