<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penjualan PAID. Angka uang integer rupiah (disalin dari amplop order.paid).
 */
class Sale extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'tenant_id',
        'outlet_id',
        'gross_subtotal',
        'discount_total',
        'subtotal',
        'service_charge',
        'tax',
        'grand_total',
        'promotion_id',
        'promotion_name',
        'promotion_template',
        'payment_method',
        'paid_at',
    ];

    protected $casts = [
        'gross_subtotal' => 'integer',
        'discount_total' => 'integer',
        'subtotal' => 'integer',
        'service_charge' => 'integer',
        'tax' => 'integer',
        'grand_total' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
