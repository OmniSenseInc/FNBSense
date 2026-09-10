<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Harga pokok (HPP/COGS) per produk, dihitung dari resep × harga beli bahan.
 * Service-to-service: dipanggil Ordering saat order dibuat supaya HPP
 * di-snapshot ke order_items (harga bahan bisa berubah belakangan, tapi HPP
 * order yang sudah terjual tak boleh ikut berubah).
 *
 * Balasan berbentuk peta product_id => unit_cost (integer rupiah). Produk yang
 * tak punya resep, atau resepnya memakai bahan yang belum dihargai, dihitung 0 —
 * laporan margin yang "terlalu bagus" itulah penanda bagi owner bahwa masih ada
 * bahan yang belum diisi harga belinya.
 */
class ProductCostController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'product_ids' => ['required', 'array', 'min:1', 'max:50'],
            'product_ids.*' => ['required', 'uuid'],
        ]);

        $tenantId = $validated['tenant_id'];
        $productIds = array_values(array_unique($validated['product_ids']));

        // Default 0, bukan null: Ordering/Frontend cuma peduli angka bulat.
        $costs = array_fill_keys($productIds, 0);

        $recipes = Recipe::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('product_id', $productIds)
            ->with('ingredient')
            ->get();

        foreach ($recipes as $recipe) {
            // qty_per_unit adalah decimal(14,3) -> dibaca sebagai string oleh
            // Eloquent; cast ke float aman untuk besaran kecil (gram/ml/pcs).
            $qty = (float) $recipe->qty_per_unit;
            $costPerUnit = (int) $recipe->ingredient->cost_per_unit;
            $costs[$recipe->product_id] += (int) round($qty * $costPerUnit);
        }

        return response()->json(['data' => $costs]);
    }
}
