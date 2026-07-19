<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRecipeRequest;
use App\Http\Requests\UpdateRecipeRequest;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $recipes = Recipe::query()
            ->where('tenant_id', $this->tenantId($request))
            // Filter opsional per produk: ?product=<uuid>.
            ->when($request->query('product'), fn ($q, $product) => $q->where('product_id', $product))
            ->orderBy('product_id')
            ->get();

        return response()->json(['data' => $recipes]);
    }

    /**
     * Endpoint internal service-to-service (auth: X-Service-Token) untuk Inventory.
     * Balikin resep batch per produk, di-scope tenant dari query — tak bocor lintas-tenant.
     * Produk tanpa resep tak muncul di hasil (consumer memperlakukannya sebagai unmapped).
     */
    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant' => ['required', 'uuid'],
            'products' => ['required', 'string'],
        ]);

        // trim tiap id: spasi nyasar tak boleh diam-diam bikin produk tak ter-match (deduksi keskip).
        $productIds = array_values(array_filter(array_map('trim', explode(',', $validated['products']))));

        $grouped = Recipe::query()
            ->where('tenant_id', $validated['tenant'])
            ->whereIn('product_id', $productIds)
            ->with('ingredient:id,unit') // eager-load: anti N+1 saat baca unit.
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows, $productId) => [
                'product_id' => $productId,
                'ingredients' => $rows->map(fn ($r) => [
                    'ingredient_id' => $r->ingredient_id,
                    'qty_per_unit' => $r->qty_per_unit,
                    'unit' => $r->ingredient?->unit,
                ])->values(),
            ])
            ->values();

        return response()->json($grouped);
    }

    public function store(StoreRecipeRequest $request): JsonResponse
    {
        $recipe = Recipe::create([
            ...$request->validated(),
            'tenant_id' => $this->tenantId($request),
        ]);

        return response()->json(['data' => $recipe], 201);
    }

    public function update(UpdateRecipeRequest $request, string $id): JsonResponse
    {
        $recipe = Recipe::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        $recipe->update($request->validated());

        return response()->json(['data' => $recipe]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $recipe = Recipe::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        $recipe->delete();

        return response()->json(['message' => 'Resep dihapus.']);
    }

    private function tenantId(Request $request): string
    {
        return $request->attributes->get('tenant_id');
    }
}
