<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductSalesFact extends Model
{
    use HasUuids;

    protected $fillable = [
        'sale_fact_id', 'product_id', 'product_name', 'qty', 'unit_price', 'line_total',
    ];
}
