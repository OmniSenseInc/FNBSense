<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Shift kasir. tenant_id/outlet_id UUID milik IAM (tanpa FK, beda DB).
 * open_key = pagar DB "1 shift open per outlet" (lihat migrasi).
 */
class Shift extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'opened_by',
        'opening_cash',
        'opened_at',
        'closed_by',
        'closing_cash',
        'closed_at',
        'status',
        'open_key',
    ];

    protected $casts = [
        'opening_cash' => 'integer',
        'closing_cash' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'status' => ShiftStatus::class,
    ];
}
