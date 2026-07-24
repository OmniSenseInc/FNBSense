<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesFact extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id', 'order_id', 'tenant_id', 'outlet_id', 'gross_subtotal',
        'discount_total', 'subtotal', 'service_charge', 'tax', 'grand_total',
        'promotion_id', 'promotion_name', 'promotion_template',
        'payment_method', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_subtotal' => 'integer',
            'discount_total' => 'integer',
            'subtotal' => 'integer',
            'service_charge' => 'integer',
            'tax' => 'integer',
            'grand_total' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(ProductSalesFact::class, 'sale_fact_id');
    }
}
