<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ShiftStatus;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Laporan laba-rugi lintas-shift / harian (F5d). Bukan per-shift (itu F5c) — di
 * sini rentang tanggal bebas. Semua di-scope tenant+outlet dari JWT. Dihitung
 * dari query, tak disimpan.
 *
 * Alur untung: omzet − harga pokok (HPP/COGS) = laba kotor (beserta margin %);
 * laba kotor − pengeluaran = laba bersih. HPP di-snapshot per penjualan oleh
 * consumer order.paid (kolom sales.cogs_total), jadi laporan lama tak berubah
 * walau harga bahan naik.
 *
 * Selisih kas (cash_variance) tiap shift ikut dibawa. Angka ini BUKAN bagian
 * laba-rugi — kas kurang/lebih adalah temuan rekonsiliasi fisik, bukan omzet —
 * jadi ia dilaporkan terpisah dan TIDAK dijumlahkan ke net.
 */
class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $tenant = $this->tenantId($request);
        $outlet = $this->outletId($request);

        // from/to adalah tanggal KALENDER WIB (Asia/Jakarta), bukan UTC — kafe
        // Indonesia menghitung "hari ini" dari tengah malam WIB. paid_at/spent_at
        // tersimpan UTC (app timezone), jadi batasnya dikonversi ke UTC sebelum
        // dibandingkan. Window half-open: [from WIB 00:00, to+1hari WIB 00:00).
        $from = Carbon::parse($v['from'], 'Asia/Jakarta')->startOfDay()->setTimezone('UTC');
        $to = Carbon::parse($v['to'], 'Asia/Jakarta')->startOfDay()->addDay()->setTimezone('UTC');

        $sales = Sale::query()
            ->where('tenant_id', $tenant)->where('outlet_id', $outlet)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to);

        $totalSales = (int) (clone $sales)->sum('grand_total');
        $totalCogs = (int) (clone $sales)->sum('cogs_total');
        $cashSales = (int) (clone $sales)->where('payment_method', 'cash')->sum('grand_total');
        $qrisSales = (int) (clone $sales)->where('payment_method', 'qris_static')->sum('grand_total');
        $transactions = (clone $sales)->count();

        $expenses = Expense::query()
            ->where('tenant_id', $tenant)->where('outlet_id', $outlet)
            ->where('spent_at', '>=', $from)->where('spent_at', '<', $to);

        $totalExpenses = (int) (clone $expenses)->sum('amount');
        $expenseCount = (clone $expenses)->count();
        // Rincian per kategori — yang bikin laporan pengeluaran berguna. Satu query.
        $byCategory = (clone $expenses)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($t) => (int) $t);

        $grossProfit = $totalSales - $totalCogs;
        // Margin dalam persen, 1 desimal. totalSales 0 -> 0.0 (bukan division by zero).
        $marginPercent = $totalSales > 0 ? round($grossProfit / $totalSales * 100, 1) : 0.0;

        // Shift yang DITUTUP dalam rentang — satu-satunya yang punya selisih kas
        // untuk diaudit. Diatribusikan ke hari TUTUP (closed_at), bukan hari buka:
        // selisih baru lahir saat kasir menghitung laci ketika menutup shift.
        $shifts = Shift::query()
            ->where('tenant_id', $tenant)->where('outlet_id', $outlet)
            ->where('status', ShiftStatus::Closed)
            ->where('closed_at', '>=', $from)->where('closed_at', '<', $to)
            ->orderByDesc('closed_at')
            ->get()
            ->map(fn (Shift $shift) => $shift->toApiArray(withReport: true))
            ->values();

        // Jumlahkan selisih (boleh negatif). Bukan sum('cash_variance') karena
        // variance itu hasil hitung, bukan kolom yang tersimpan di DB.
        $totalVariance = $shifts->sum(fn (array $s) => $s['report']['cash_variance'] ?? 0);

        return response()->json(['data' => [
            'period' => ['from' => $v['from'], 'to' => $v['to']],
            'sales' => [
                'total' => $totalSales,
                'cash' => $cashSales,
                'qris' => $qrisSales,
                'transactions' => $transactions,
            ],
            'cogs' => $totalCogs,
            'gross_profit' => $grossProfit,
            'margin_percent' => $marginPercent,
            'expenses' => [
                'total' => $totalExpenses,
                'count' => $expenseCount,
                'by_category' => $byCategory,
            ],
            // Laba bersih = omzet − harga pokok − pengeluaran (boleh negatif = tekor).
            'net' => $grossProfit - $totalExpenses,
            'shifts' => [
                'total_variance' => $totalVariance,
                'count' => $shifts->count(),
                'items' => $shifts,
            ],
        ]]);
    }
}
