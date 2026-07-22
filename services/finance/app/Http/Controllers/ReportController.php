<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Laporan keuangan lintas-shift / harian (F5d). Bukan per-shift (itu F5c) — di
 * sini rentang tanggal bebas: omzet (sales) dikurangi pengeluaran (expenses),
 * semuanya di-scope tenant+outlet dari JWT. Dihitung dari query, tak disimpan.
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

        // Window inklusif per-hari, half-open: [from 00:00, to+1hari 00:00).
        $from = Carbon::parse($v['from'])->startOfDay();
        $to = Carbon::parse($v['to'])->startOfDay()->addDay();

        $sales = Sale::query()
            ->where('tenant_id', $tenant)->where('outlet_id', $outlet)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to);

        $totalSales = (int) (clone $sales)->sum('grand_total');
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

        return response()->json(['data' => [
            'period' => ['from' => $v['from'], 'to' => $v['to']],
            'sales' => [
                'total' => $totalSales,
                'cash' => $cashSales,
                'qris' => $qrisSales,
                'transactions' => $transactions,
            ],
            'expenses' => [
                'total' => $totalExpenses,
                'count' => $expenseCount,
                'by_category' => $byCategory,
            ],
            // Arus kas bersih = omzet − pengeluaran (boleh negatif = tekor).
            'net' => $totalSales - $totalExpenses,
        ]]);
    }
}
