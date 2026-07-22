<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengeluaran outlet (F5d). Angka uang integer rupiah. tenant/outlet UUID IAM.
 */
class Expense extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'category',
        'amount',
        'note',
        'spent_at',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'spent_at' => 'datetime',
    ];
}
