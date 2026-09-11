<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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

    /**
     * Bentuk API shift yang seragam — dipakai ShiftController (layar kasir) dan
     * ReportController (laporan owner/manajer). Satu sumber bentuk, supaya kedua
     * konsumen tak bisa diam-diam berpisah bentuknya.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(bool $withReport = false): array
    {
        $data = [
            'id' => $this->id,
            'outlet_id' => $this->outlet_id,
            'status' => $this->status->value,
            'opened_by' => $this->opened_by,
            'opening_cash' => $this->opening_cash,
            'opened_at' => $this->opened_at,
            'closed_by' => $this->closed_by,
            'closing_cash' => $this->closing_cash,
            'closed_at' => $this->closed_at,
        ];

        if ($withReport) {
            $data['report'] = $this->financialReport();
        }

        return $data;
    }

    /**
     * Laporan keuangan shift — dihitung dari `sales` di window, bukan kolom
     * tersimpan. Shift masih open → window sampai now() (X-report berjalan);
     * sudah closed → sampai closed_at (Z-report final).
     *
     * Batas bawah window = jam TUTUP shift SEBELUMNYA (bukan opened_at sendiri).
     * Tanpa ini, penjualan yang dikonfirmasi di JEDA antar shift — setelah shift
     * lama ditutup, sebelum shift baru dibuka — jatuh di luar window semua shift
     * dan uangnya hilang dari audit kas. Dengan batas "close-to-close", window
     * shift bersambung rapat: [tutup_sebelumnya, tutup_sendiri), tak ada celah
     * tempat uang bisa jatuh. Penjualan jeda diatribusikan ke shift BERIKUTNYA
     * (uangnya memang masuk laci yang dihitung saat shift berikut tutup).
     *
     * @return array<string, mixed>
     */
    public function financialReport(): array
    {
        $upperBound = $this->closed_at ?? Carbon::now();
        $lowerBound = $this->previousClosedAt() ?? $this->opened_at;

        $sales = Sale::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('outlet_id', $this->outlet_id)
            ->where('paid_at', '>=', $lowerBound)
            ->where('paid_at', '<', $upperBound);

        $totalSales = (int) (clone $sales)->sum('grand_total');
        $transactions = (clone $sales)->count();
        // Hanya penjualan CASH yang masuk laci. QRIS tak menyentuh kas fisik →
        // tak dihitung ke expected_cash (kalau ikut, selisih selalu bohong minus).
        $cashSales = (int) (clone $sales)->where('payment_method', 'cash')->sum('grand_total');
        $qrisSales = (int) (clone $sales)->where('payment_method', 'qris_static')->sum('grand_total');

        $expectedCash = $this->opening_cash + $cashSales;
        $countedCash = $this->closing_cash;                       // null saat masih open
        // Selisih BOLEH negatif (kas kurang) — makanya dihitung, bukan disimpan
        // di kolom unsigned. null selama shift belum ditutup.
        $variance = $countedCash === null ? null : $countedCash - $expectedCash;

        return [
            'total_sales' => $totalSales,
            'transactions' => $transactions,
            'cash_sales' => $cashSales,
            'qris_sales' => $qrisSales,
            'opening_cash' => $this->opening_cash,
            'expected_cash' => $expectedCash,
            'counted_cash' => $countedCash,
            'cash_variance' => $variance,
            'window' => ['from' => $lowerBound, 'to' => $this->closed_at],
        ];
    }

    /**
     * Jam tutup shift TERAKHIR yang dibuka sebelum shift ini (null kalau ini
     * shift pertama outlet). Nilai kembali Carbon (cast datetime), konsisten
     * dengan $this->opened_at supaya perbandingan waktu di query seragam.
     */
    private function previousClosedAt(): ?Carbon
    {
        $previous = Shift::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('outlet_id', $this->outlet_id)
            ->where('opened_at', '<', $this->opened_at)
            ->orderByDesc('opened_at')
            ->first(['id', 'closed_at']);

        return $previous?->closed_at;
    }
}
