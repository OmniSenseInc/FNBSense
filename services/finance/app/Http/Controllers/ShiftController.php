<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ShiftStatus;
use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\OpenShiftRequest;
use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shift kasir + laporan keuangan per-shift (F5c). Penjualan diatribusikan lewat
 * RENTANG WAKTU (outlet + paid_at dalam [opened_at, closed_at)) — sales tak
 * dimodifikasi, consumer F5b tak tersentuh. Semua di-scope tenant+outlet dari JWT.
 */
class ShiftController extends Controller
{
    public function open(OpenShiftRequest $request): JsonResponse
    {
        $outletId = $this->outletId($request);

        // open_key = outlet_id (unik) → open kedua utk outlet yang sama gagal di DB
        // (bukan cuma cek aplikasi). Dua window open = penjualan kehitung dobel.
        try {
            $shift = Shift::create([
                'tenant_id' => $this->tenantId($request),
                'outlet_id' => $outletId,
                'opened_by' => $this->userId($request),
                'opening_cash' => (int) $request->validated()['opening_cash'],
                'opened_at' => now(),
                'status' => ShiftStatus::Open,
                'open_key' => $outletId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Sudah ada shift terbuka untuk outlet ini.'], 409);
        }

        return response()->json(['data' => $this->present($shift)], 201);
    }

    public function close(CloseShiftRequest $request, string $id): JsonResponse
    {
        // lockForUpdate dalam transaksi: dua close bersamaan → yang kedua lihat
        // status closed → 409, bukan menimpa selisih kas yang sudah final.
        return DB::transaction(function () use ($request, $id) {
            $shift = $this->findScoped($request, $id, lock: true);

            if ($shift->status === ShiftStatus::Closed) {
                return response()->json(['message' => 'Shift sudah ditutup.'], 409);
            }

            $shift->update([
                'closed_by' => $this->userId($request),
                'closing_cash' => (int) $request->validated()['closing_cash'],
                'closed_at' => now(),
                'status' => ShiftStatus::Closed,
                'open_key' => null, // lepas guard → outlet boleh buka shift baru
            ]);

            return response()->json(['data' => $this->present($shift, withReport: true)]);
        });
    }

    /**
     * Shift yang sedang terbuka di outlet ini, atau `data: null`.
     *
     * Sumber kebenarannya server, bukan localStorage: kasir berikutnya sering
     * memakai device lain, dan shift terbuka harus bisa ditutup oleh siapa pun
     * yang memegang laci. 200 + null (bukan 404) karena "belum ada shift" itu
     * keadaan normal pagi hari, bukan kesalahan.
     */
    public function current(Request $request): JsonResponse
    {
        $shift = Shift::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            ->where('status', ShiftStatus::Open)
            ->first();

        return response()->json(['data' => $shift === null ? null : $this->present($shift, withReport: true)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $shift = $this->findScoped($request, $id);

        return response()->json(['data' => $this->present($shift, withReport: true)]);
    }

    private function findScoped(Request $request, string $id, bool $lock = false): Shift
    {
        $query = Shift::query()
            ->where('id', $id)
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail(); // ModelNotFound → 404 (isolasi tenant/outlet)
    }

    /** @return array<string, mixed> */
    private function present(Shift $shift, bool $withReport = false): array
    {
        $data = [
            'id' => $shift->id,
            'outlet_id' => $shift->outlet_id,
            'status' => $shift->status->value,
            'opened_by' => $shift->opened_by,
            'opening_cash' => $shift->opening_cash,
            'opened_at' => $shift->opened_at,
            'closed_by' => $shift->closed_by,
            'closing_cash' => $shift->closing_cash,
            'closed_at' => $shift->closed_at,
        ];

        if ($withReport) {
            $data['report'] = $this->report($shift);
        }

        return $data;
    }

    /**
     * Laporan keuangan shift — dihitung dari `sales` di window, bukan kolom tersimpan.
     * Shift masih open → window sampai now() (X-report berjalan); sudah closed →
     * sampai closed_at (Z-report final).
     *
     * @return array<string, mixed>
     */
    private function report(Shift $shift): array
    {
        $upperBound = $shift->closed_at ?? Carbon::now();

        $totalSales = (int) $this->salesInWindow($shift, $upperBound)->sum('grand_total');
        $transactions = $this->salesInWindow($shift, $upperBound)->count();
        // Hanya penjualan CASH yang masuk laci. QRIS tak menyentuh kas fisik →
        // tak dihitung ke expected_cash (kalau ikut, selisih selalu bohong minus).
        $cashSales = (int) $this->salesInWindow($shift, $upperBound)->where('payment_method', 'cash')->sum('grand_total');
        $qrisSales = (int) $this->salesInWindow($shift, $upperBound)->where('payment_method', 'qris_static')->sum('grand_total');

        $expectedCash = $shift->opening_cash + $cashSales;
        $countedCash = $shift->closing_cash;                       // null saat masih open
        // Selisih BOLEH negatif (kas kurang) — makanya dihitung, bukan disimpan
        // di kolom unsigned. null selama shift belum ditutup.
        $variance = $countedCash === null ? null : $countedCash - $expectedCash;

        return [
            'total_sales' => $totalSales,
            'transactions' => $transactions,
            'cash_sales' => $cashSales,
            'qris_sales' => $qrisSales,
            'opening_cash' => $shift->opening_cash,
            'expected_cash' => $expectedCash,
            'counted_cash' => $countedCash,
            'cash_variance' => $variance,
            'window' => ['from' => $shift->opened_at, 'to' => $shift->closed_at],
        ];
    }

    /** Query dasar penjualan dalam window shift; half-open [opened_at, upper). */
    private function salesInWindow(Shift $shift, Carbon $upperBound): Builder
    {
        return Sale::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('outlet_id', $shift->outlet_id)
            ->where('paid_at', '>=', $shift->opened_at)
            ->where('paid_at', '<', $upperBound);
    }
}
