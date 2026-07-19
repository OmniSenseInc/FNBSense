<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIngredientRequest;
use App\Http\Requests\UpdateIngredientRequest;
use App\Models\Ingredient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ingredients = Ingredient::query()
            ->where('tenant_id', $this->tenantId($request))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $ingredients]);
    }

    public function store(StoreIngredientRequest $request): JsonResponse
    {
        $ingredient = Ingredient::create([
            ...$request->validated(),
            'tenant_id' => $this->tenantId($request),
        ]);

        return response()->json(['data' => $ingredient], 201);
    }

    public function update(UpdateIngredientRequest $request, string $id): JsonResponse
    {
        $ingredient = Ingredient::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        $ingredient->update($request->validated());

        return response()->json(['data' => $ingredient]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $ingredient = Ingredient::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        $ingredient->delete();

        return response()->json(['message' => 'Bahan dihapus.']);
    }

    private function tenantId(Request $request): string
    {
        return $request->attributes->get('tenant_id');
    }
}
