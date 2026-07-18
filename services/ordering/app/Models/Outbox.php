<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Baris outbox transaksional. Ditulis dalam transaksi yang SAMA dengan
 * perubahan status PAID (langkah 8): barisnya yang mengunci invarian keuangan,
 * relay ke RabbitMQ (F3/F4) cuma pengangkut. published_at null = belum di-relay.
 */
class Outbox extends Model
{
    use HasUuids;

    protected $table = 'outbox';

    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'occurred_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
