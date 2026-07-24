<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromotionStatus;
use App\Enums\PromotionTemplate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'template',
        'percentage',
        'amount',
        'min_subtotal',
        'max_discount',
        'priority',
        'starts_at',
        'ends_at',
    ];

    protected $attributes = [
        'status' => PromotionStatus::Draft->value,
        'min_subtotal' => 0,
        'priority' => 100,
    ];

    protected function casts(): array
    {
        return [
            'template' => PromotionTemplate::class,
            'status' => PromotionStatus::class,
            'percentage' => 'integer',
            'amount' => 'integer',
            'min_subtotal' => 'integer',
            'max_discount' => 'integer',
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(PromotionProduct::class);
    }
}
