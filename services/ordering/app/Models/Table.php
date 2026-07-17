<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Meja fisik di sebuah outlet. Dicetak jadi QR yang memuat `qr_token`.
 *
 * `qr_token` sengaja BUKAN turunan dari id meja: QR harus bisa dirotasi
 * (mis. QR lama tersebar/difoto) tanpa mengganti identitas mejanya.
 */
class Table extends Model
{
    use HasUuids;

    /** Panjang qr_token; disamakan dengan batas kolom string(32) di migration. */
    public const QR_TOKEN_LENGTH = 32;

    /**
     * Sengaja RAMPING. Yang TIDAK boleh fillable dan alasannya:
     * - `qr_token`  : dibangkitkan server-side; kalau mass-assignable, klien bisa
     *                 menentukan token QR-nya sendiri.
     * - `tenant_id` : identitas pemilik data, hanya boleh dari klaim JWT.
     * - `outlet_id` : idem — kalau fillable, owner tenant A bisa menitipkan
     *                 outlet_id tenant B lewat body dan menanam meja di sana.
     * Ketiganya diisi lewat createForOutlet() / generateQrToken(), bukan request.
     */
    protected $fillable = [
        'label',
        'is_active',
    ];

    /** qr_token = kredensial cetak. Jangan ikut terserialisasi tanpa sengaja. */
    protected $hidden = [
        'qr_token',
    ];

    /**
     * Samakan default instance dengan default kolom DB. Tanpa ini, model hasil
     * create() memegang is_active=null (DB-nya sendiri sudah 1) sampai refresh(),
     * dan response JSON POST /api/tables mengirim "is_active": null ke klien.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Terbitkan qr_token otomatis sebelum insert.
     *
     * Kolomnya NOT NULL tapi sengaja non-fillable, jadi tanpa hook ini setiap
     * Table::create() gagal dan pemanggil harus ingat pola new->set->save.
     * Membangkitkannya di sini menutup jebakan itu tanpa membuka mass-assignment.
     */
    protected static function booted(): void
    {
        static::creating(function (self $table): void {
            $table->qr_token ??= self::generateQrToken();
        });
    }

    /** Str::random() memakai CSPRNG — jangan diganti rand()/uniqid(). */
    public static function generateQrToken(): string
    {
        return Str::random(self::QR_TOKEN_LENGTH);
    }

    /**
     * Satu-satunya jalan membuat meja.
     *
     * tenant_id & outlet_id WAJIB lewat argumen (isi dari klaim JWT), tak pernah
     * dari $attributes — itu yang menjaga meja tak bisa ditanam di outlet tenant
     * lain lewat body request. $attributes cuma boleh berisi kolom fillable.
     */
    public static function createForOutlet(string $tenantId, string $outletId, array $attributes): self
    {
        $table = new self($attributes);
        $table->tenant_id = $tenantId;
        $table->outlet_id = $outletId;
        $table->save();

        return $table;
    }
}
