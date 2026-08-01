<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order — kepemilikan tunggal atas status "sudah dibayar atau belum".
 *
 * fillable sengaja RAMPING. Yang TIDAK fillable dan alasannya (skrutini #5):
 * uang (subtotal/service_charge/tax/grand_total), tarif snapshot, status,
 * confirmed_by/at, tenant_id/outlet_id, order_number, expires_at — semuanya
 * ditentukan server di service layer, tak boleh datang dari body request.
 * Klien tak boleh menentukan uang, status, atau kepemilikan.
 */
class Order extends Model
{
    use HasUuids;

    protected $fillable = [
        'table_id',
        'order_type',
        'customer_name',
        'note',
        // Boleh fillable karena ia NIAT pelanggan, sederajat dengan
        // customer_name — bukan uang, bukan status, bukan bukti bayar. Lihat
        // migrasi payment_preference untuk kenapa ia tak boleh menyentuh
        // payment_method.
        'payment_preference',
    ];

    /**
     * Samakan default instance dengan default kolom DB. Tanpa ini, order hasil
     * create() memegang status=null (DB-nya sendiri sudah 'pending') sampai
     * refresh(), dan response JSON POST /api/orders mengirim "status": null.
     */
    protected $attributes = [
        'status' => OrderStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'order_type' => OrderType::class,
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_preference' => PaymentMethod::class,
            'gross_subtotal' => 'integer',
            'discount_total' => 'integer',
            'subtotal' => 'integer',
            'service_charge' => 'integer',
            'tax' => 'integer',
            'grand_total' => 'integer',
            'tax_percent' => 'decimal:2',
            'service_charge_percent' => 'decimal:2',
            'promotion_snapshot' => 'array',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
            // Tak fillable, sama seperti confirmed_at: ini catatan kejadian yang
            // ditulis server, bukan isian yang boleh datang dari body request.
            'customer_claimed_paid_at' => 'datetime',
            'ready_at' => 'datetime',
        ];
    }

    /**
     * Pelanggan menyatakan akan membayar tunai di kasir.
     *
     * Pengetahuannya ditaruh di sini, bukan di controller, supaya pemanggil tak
     * perlu mengenal enum-nya — dan supaya "apa artinya memilih tunai" punya
     * satu tempat saat nanti cara bayar bertambah.
     */
    public function inginTunai(): bool
    {
        return $this->payment_preference === PaymentMethod::Cash;
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }
}
