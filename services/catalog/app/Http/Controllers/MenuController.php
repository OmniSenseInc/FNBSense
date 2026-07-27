<?php

namespace App\Http\Controllers;

use App\Http\Resources\MenuCategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /**
     * Menu publik: kategori aktif + produk tersedia milik satu tenant.
     * Tenant diidentifikasi lewat query param ?tenant=<uuid> (mis. dari QR).
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant' => ['required', 'uuid'],
        ]);

        $categories = Category::query()
            ->where('tenant_id', $validated['tenant'])
            ->where('is_active', true)
            ->with(['products' => function ($query) {
                $query->where('is_available', true)->orderBy('name');
            }])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Dibungkus Resource, BUKAN dikirim mentah: menu ini publik tanpa auth,
        // jadi hanya kolom yang disebut namanya di Resource yang boleh keluar.
        return response()->json(['data' => MenuCategoryResource::collection($categories)]);
    }
}
