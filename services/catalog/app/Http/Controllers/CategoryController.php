<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->where('tenant_id', $this->tenantId($request))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create([
            ...$request->validated(),
            'tenant_id' => $this->tenantId($request),
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function update(UpdateCategoryRequest $request, string $id): JsonResponse
    {
        $category = Category::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        $category->update($request->validated());

        return response()->json(['data' => $category]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $category = Category::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        // Tolak kalau masih ada produk di dalamnya: menu menelusuri produk lewat
        // kategori, jadi menghapus kategori berisi produk = produk-produk itu
        // lenyap dari menu tanpa satu pun pesan. Owner pindah/hapus produk dulu.
        if (Product::where('category_id', $category->id)->exists()) {
            return response()->json(['message' => 'Kategori masih berisi produk. Pindahkan atau hapus produknya dulu.'], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Kategori dihapus.']);
    }

    private function tenantId(Request $request): string
    {
        return $request->attributes->get('tenant_id');
    }
}
