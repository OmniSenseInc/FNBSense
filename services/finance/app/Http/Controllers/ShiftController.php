<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ShiftStatus;
use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\OpenShiftRequest;
use App\Models\Shift;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shift kasir + laporan keuangan per-shift (F5c). Penjualan diatribusikan lewat
 * RENTANG WAKTU (outlet + paid_at dalam [opened_at, closed_at)) — sales tak
 * dimodifikasi, consumer F5b tak tersentuh. Semua di-scope tenant+outlet dari JWT.
 *
 * Bentuk API shift + hitungan laporannya tinggal di model Shift (toApiArray /
 * financialReport) supaya ReportController — laporan owner/manajer — memakai
 * bentuk & angka yang SAMA persis dengan layar kasir.
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

        return response()->json(['data' => $shift->toApiArray()], 201);
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

            return response()->json(['data' => $shift->toApiArray(withReport: true)]);
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

        return response()->json(['data' => $shift === null ? null : $shift->toApiArray(withReport: true)]);
    }

    /**
     * Riwayat shift outlet ini (yang sudah DITUTUP), terbaru di atas.
     *
     * Berbeda dari `current`: yang itu shift berjalan (atau null), yang ini daftar
     * masa lalu — inilah yang membuat shift tertutup TETAP terlihat setelah layar
     * di-refresh (sebelumnya tak ada pintu baca untuknya). Dibatasi 20 terakhir:
     * riwayat lebih dalam jadi urusan laporan owner, bukan layar kasir yang cuma
     * butuh konteks beberapa hari terakhir.
     */
    public function index(Request $request): JsonResponse
    {
        $shifts = Shift::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            ->where('status', ShiftStatus::Closed)
            ->orderByDesc('opened_at')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $shifts
                ->map(fn (Shift $shift) => $shift->toApiArray(withReport: true))
                ->values(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $shift = $this->findScoped($request, $id);

        return response()->json(['data' => $shift->toApiArray(withReport: true)]);
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
}
