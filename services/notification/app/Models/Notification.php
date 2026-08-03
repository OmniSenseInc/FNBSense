<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notification extends Model
{
    use HasUuids;

    public $timestamps = false; // hanya created_at, di-set eksplisit saat konsumsi event

    /** Boleh dibaca kasir maupun owner. */
    public const UNTUK_SEMUA = 'all';

    /** Owner saja — dipakai notifikasi yang melaporkan tindakan kasir. */
    public const UNTUK_OWNER = 'owner';

    protected $fillable = [
        'tenant_id', 'outlet_id', 'audience', 'type', 'severity',
        'title', 'body', 'payload', 'source_event_id', 'created_at',
    ];

    /**
     * Notifikasi yang boleh dibaca peran ini. Peran tak dikenal -> hanya 'all'.
     *
     * Penyaringnya tinggal di model, bukan disalin ke index() dan
     * unreadCount(): dua salinan bisa berselisih diam-diam, dan bentuk
     * selisihnya yang paling mungkin adalah angka lonceng menghitung sesuatu
     * yang tak pernah muncul saat daftarnya dibuka.
     */
    public function scopeUntukPeran(Builder $query, ?string $peran): Builder
    {
        return $query->where(function (Builder $q) use ($peran) {
            $q->where('audience', self::UNTUK_SEMUA);

            if ($peran === self::UNTUK_OWNER) {
                $q->orWhere('audience', self::UNTUK_OWNER);
            }
        });
    }

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
