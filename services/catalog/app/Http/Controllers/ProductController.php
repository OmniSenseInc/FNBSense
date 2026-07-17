<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->where('tenant_id', $this->tenantId($request))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $products]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create([
            ...$request->validated(),
            'tenant_id' => $this->tenantId($request),
        ]);

        return response()->json(['data' => $product], 201);
    }

    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        $product = Product::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        $product->update($request->validated());

        return response()->json(['data' => $product]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $product = Product::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        $product->delete();

        return response()->json(['message' => 'Produk dihapus.']);
    }

    private function tenantId(Request $request): string
    {
        return $request->attributes->get('tenant_id');
    }
}
