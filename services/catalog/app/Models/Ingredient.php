<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'unit',
        'cost_per_unit',
    ];

    protected function casts(): array
    {
        return [
            'cost_per_unit' => 'integer',
        ];
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }
}
