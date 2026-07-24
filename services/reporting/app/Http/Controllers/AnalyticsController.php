<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductSalesFact;
use App\Models\SalesFact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AnalyticsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        [$from, $to, $labels] = $this->period($request);
        $sales = $this->scopedSales($request)->where('paid_at', '>=', $from)->where('paid_at', '<', $to);

        $transactions = (clone $sales)->count();
        $revenue = (int) (clone $sales)->sum('grand_total');
        $grossSubtotal = (int) (clone $sales)->sum('gross_subtotal');
        $discountTotal = (int) (clone $sales)->sum('discount_total');
        $netSubtotal = (int) (clone $sales)->sum('subtotal');
        $byPayment = (clone $sales)
            ->selectRaw("COALESCE(payment_method, 'unknown') as method, COUNT(*) as transactions, SUM(grand_total) as revenue")
            ->groupBy('method')->get()
            ->map(fn (SalesFact $row) => [
                'method' => $row->getAttribute('method'),
                'transactions' => (int) $row->getAttribute('transactions'),
                'revenue' => (int) $row->getAttribute('revenue'),
            ])->values();

        return response()->json(['data' => [
            'period' => $labels,
            'revenue' => $revenue,
            'gross_subtotal' => $grossSubtotal,
            'discount_total' => $discountTotal,
            'net_subtotal' => $netSubtotal,
            'transactions' => $transactions,
            'average_order_value' => $transactions === 0 ? 0 : intdiv($revenue, $transactions),
            'by_payment_method' => $byPayment,
        ]]);
    }

    public function dailyTrend(Request $request): JsonResponse
    {
        [$from, $to, $labels] = $this->period($request);
        $rows = $this->scopedSales($request)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)
            ->selectRaw('DATE(paid_at) as date, COUNT(*) as transactions, SUM(grand_total) as revenue')
            ->groupByRaw('DATE(paid_at)')->orderBy('date')->get()
            ->map(fn (SalesFact $row) => [
                'date' => $row->getAttribute('date'),
                'transactions' => (int) $row->getAttribute('transactions'),
                'revenue' => (int) $row->getAttribute('revenue'),
            ]);

        return response()->json(['data' => ['period' => $labels, 'points' => $rows]]);
    }

    public function topProducts(Request $request): JsonResponse
    {
        [$from, $to, $labels] = $this->period($request);
        $validated = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $limit = (int) ($validated['limit'] ?? 10);

        $rows = ProductSalesFact::query()
            ->join('sales_facts', 'sales_facts.id', '=', 'product_sales_facts.sale_fact_id')
            ->where('sales_facts.tenant_id', $this->tenantId($request))
            ->where('sales_facts.outlet_id', $this->outletId($request))
            ->where('sales_facts.paid_at', '>=', $from)->where('sales_facts.paid_at', '<', $to)
            ->selectRaw('product_sales_facts.product_id, MAX(product_sales_facts.product_name) as product_name, SUM(product_sales_facts.qty) as qty, SUM(product_sales_facts.line_total) as revenue')
            ->groupBy('product_sales_facts.product_id')->orderByDesc('qty')->orderByDesc('revenue')
            ->limit($limit)->get()
            ->map(fn (ProductSalesFact $row) => [
                'product_id' => $row->getAttribute('product_id'),
                'product_name' => $row->getAttribute('product_name'),
                'qty' => (int) $row->getAttribute('qty'),
                'revenue' => (int) $row->getAttribute('revenue'),
            ]);

        return response()->json(['data' => ['period' => $labels, 'products' => $rows]]);
    }

    public function promotionPerformance(Request $request): JsonResponse
    {
        [$from, $to, $labels] = $this->period($request);

        $rows = $this->scopedSales($request)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->whereNotNull('promotion_id')
            ->selectRaw(
                'promotion_id, MAX(promotion_name) as promotion_name, '.
                'MAX(promotion_template) as promotion_template, '.
                'COUNT(*) as transactions, SUM(gross_subtotal) as gross_subtotal, '.
                'SUM(discount_total) as discount_total, SUM(subtotal) as net_subtotal, '.
                'SUM(grand_total) as revenue'
            )
            ->groupBy('promotion_id')
            ->orderByDesc('transactions')
            ->orderByDesc('discount_total')
            ->get()
            ->map(fn (SalesFact $row) => [
                'promotion_id' => $row->getAttribute('promotion_id'),
                'promotion_name' => $row->getAttribute('promotion_name'),
                'promotion_template' => $row->getAttribute('promotion_template'),
                'transactions' => (int) $row->getAttribute('transactions'),
                'gross_subtotal' => (int) $row->getAttribute('gross_subtotal'),
                'discount_total' => (int) $row->getAttribute('discount_total'),
                'net_subtotal' => (int) $row->getAttribute('net_subtotal'),
                'revenue' => (int) $row->getAttribute('revenue'),
            ]);

        return response()->json(['data' => [
            'period' => $labels,
            'promotions' => $rows,
        ]]);
    }

    /** @return array{0:Carbon,1:Carbon,2:array{from:string,to:string}} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = Carbon::createFromFormat('Y-m-d', $validated['from'])->startOfDay();
        $toInclusive = Carbon::createFromFormat('Y-m-d', $validated['to'])->startOfDay();
        if ($from->diffInDays($toInclusive) > 366) {
            throw ValidationException::withMessages(['to' => 'Rentang laporan maksimal 366 hari.']);
        }

        return [$from, $toInclusive->copy()->addDay(), $validated];
    }

    private function scopedSales(Request $request): Builder
    {
        return SalesFact::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request));
    }
}
