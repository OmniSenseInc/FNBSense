<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notification extends Model
{
    use HasUuids;

    public $timestamps = false; // hanya created_at, di-set eksplisit saat konsumsi event

    protected $fillable = [
        'tenant_id', 'outlet_id', 'type', 'severity',
        'title', 'body', 'payload', 'source_event_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function reads(): HasMany
    {
        return $this->hasMany(NotificationRead::class);
    }
}
