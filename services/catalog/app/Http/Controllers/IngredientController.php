<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIngredientRequest;
use App\Http\Requests\UpdateIngredientRequest;
use App\Models\Ingredient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    /**
     * Nama bahan untuk sekumpulan id — service-to-service, bukan untuk browser.
     *
     * Inventory memanggilnya supaya layar stok kasir menampilkan "Susu Full
     * Cream", bukan "9f3a1b2c-…". Alternatifnya adalah membuka index() di bawah
     * ke kasir, dan itu ditolak dengan sengaja: index() mengirim model MENTAH,
     * jadi kolom apa pun yang kelak masuk ke tabel `ingredients` — harga beli
     * cepat atau lambat pasti masuk — akan sampai ke layar kasir tanpa satu
     * baris kode pun berubah. Di sini medannya dipilih satu per satu.
     *
     * Bare array, bukan bungkus `data`, meniru GET /api/recipe: keduanya dibaca
     * mesin, dan dua bentuk balasan untuk dua endpoint bertetangga cuma menambah
     * hal yang harus diingat.
     *
     * Scoping tenant datang dari query, bukan dari token: token service tak
     * mewakili satu kafe, ia mewakili Inventory. Yang memaksa batas tenant di
     * sini adalah klausa where di bawah — dan test tenant-lain yang menjaganya.
     */
    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant' => ['required', 'uuid'],
            'ids' => ['required', 'string'],
        ]);

        // trim tiap id, sepola RecipeController::batch — spasi nyasar tak boleh
        // diam-diam membuat satu bahan kehilangan namanya di layar.
        $ids = array_values(array_filter(array_map('trim', explode(',', $validated['ids']))));

        $bahan = Ingredient::query()
            ->where('tenant_id', $validated['tenant'])
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'unit']);

        return response()->json($bahan->map(fn (Ingredient $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'unit' => $b->unit,
        ])->values());
    }

    /**
     * Semua bahan milik satu tenant — service-to-service untuk Inventory.
     *
     * Dipakai Inventory supaya layar stok menampilkan bahan yang BELUM pernah
     * distok (belum punya baris saldo) dan owner bisa restock dari sana. Tanpa
     * ini, bahan yang baru dibuat di Catalog tak pernah muncul di layar stok
     * dan saldonya tak bisa diisi sama sekali.
     *
     * Bare array, sepola batch(): dibaca mesin, bukan manusia.
     */
    public function all(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant' => ['required', 'uuid'],
        ]);

        $bahan = Ingredient::query()
            ->where('tenant_id', $validated['tenant'])
            ->orderBy('name')
            ->get(['id', 'name', 'unit']);

        return response()->json($bahan->map(fn (Ingredient $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'unit' => $b->unit,
        ])->values());
    }

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
