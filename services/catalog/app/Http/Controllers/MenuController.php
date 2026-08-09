<?php

namespace App\Http\Controllers;

use App\Exceptions\InventoryUnavailableException;
use App\Http\Resources\MenuCategoryResource;
use App\Models\Category;
use App\Models\Recipe;
use App\Services\InventoryBalanceClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MenuController extends Controller
{
    /**
     * Umur cache daftar habis, detik.
     *
     * Menu ini PUBLIK tanpa auth (throttle 60/menit), jadi tanpa cache tiap
     * pemindaian QR jadi pengeras suara ke Inventory — service yang sengaja
     * ditutup dari internet. Pendek karena kasir yang baru merestok tak boleh
     * menunggu lama.
     */
    private const TTL_HABIS = 15;

    /**
     * Menu publik: kategori aktif + produk tersedia milik satu tenant.
     * Tenant diidentifikasi lewat query param ?tenant=<uuid> (mis. dari QR).
     *
     * ?outlet=<uuid> OPSIONAL. Ada -> tiap produk ikut membawa penanda habis
     * yang dihitung dari resep lokal + saldo bahan di outlet itu. Tidak ada ->
     * menu apa adanya, semua produk tak bertanda. Sengaja opsional supaya
     * kontrak lama (Ordering, dan app pelanggan sebelum diperbarui) tak pecah.
     */
    public function show(Request $request, InventoryBalanceClient $inventory): JsonResponse
    {
        $validated = $request->validate([
            'tenant' => ['required', 'uuid'],
            'outlet' => ['sometimes', 'uuid'],
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

        $habis = isset($validated['outlet'])
            ? $this->habis($validated['tenant'], $validated['outlet'], $categories, $inventory)
            : [];

        foreach ($categories as $kategori) {
            foreach ($kategori->products as $produk) {
                $produk->setAttribute('is_out_of_stock', isset($habis[$produk->id]));
            }
        }

        // Dibungkus Resource, BUKAN dikirim mentah: menu ini publik tanpa auth,
        // jadi hanya kolom yang disebut namanya di Resource yang boleh keluar.
        return response()->json(['data' => MenuCategoryResource::collection($categories)]);
    }

    /**
     * Produk mana yang salah satu bahannya tak cukup untuk SATU porsi.
     *
     * @param  Collection<int, Category>  $categories
     * @return array<string, true> peta product_id => true
     */
    private function habis(
        string $tenantId,
        string $outletId,
        Collection $categories,
        InventoryBalanceClient $inventory,
    ): array {
        $produkIds = $categories->pluck('products')->flatten()->pluck('id')->all();

        if ($produkIds === []) {
            return [];
        }

        // Kegagalan ikut tersimpan (sebagai peta kosong). Disengaja: Inventory
        // yang sedang sekarat justru tak boleh dihujani ulang tiap permintaan.
        return Cache::remember(
            "menu-habis:{$tenantId}:{$outletId}",
            self::TTL_HABIS,
            fn () => $this->hitung($tenantId, $outletId, $produkIds, $inventory),
        );
    }

    /**
     * @param  array<int, string>  $produkIds
     * @return array<string, true>
     */
    private function hitung(
        string $tenantId,
        string $outletId,
        array $produkIds,
        InventoryBalanceClient $inventory,
    ): array {
        $resep = Recipe::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('product_id', $produkIds)
            ->get(['product_id', 'ingredient_id', 'qty_per_unit']);

        // Produk tanpa baris resep tak pernah tertandai — sepola gerbang stok,
        // dan layar staf sudah menyebutnya "Tanpa resep · stoknya tak pernah
        // diperiksa" supaya owner tahu itu bukan jaminan tersedia.
        if ($resep->isEmpty()) {
            return [];
        }

        try {
            $saldo = $inventory->balances(
                $tenantId,
                $outletId,
                $resep->pluck('ingredient_id')->all(),
            );
        } catch (InventoryUnavailableException $e) {
            // Fail-open, sepola gerbang stok di Ordering: kafe tak boleh
            // terlihat kehabisan segalanya gara-gara service yang tak memegang
            // uang sedang mati. Jaring pengamannya tetap gerbang 422 saat kirim.
            Log::warning('Saldo stok tak terbaca; menu tampil tanpa penanda habis.', [
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'alasan' => $e->getMessage(),
            ]);

            return [];
        }

        $habis = [];

        foreach ($resep as $baris) {
            // Bahan tanpa baris saldo = belum pernah distok = nol, bukan "tak
            // diawasi" — sepola AvailabilityController. Yang dibandingkan
            // takaran SATU porsi: menu bukan pesanan, jadi kebutuhan TIDAK
            // digabung lintas produk seperti di gerbang stok.
            if ((float) ($saldo[$baris->ingredient_id] ?? 0) < (float) $baris->qty_per_unit) {
                $habis[$baris->product_id] = true;
            }
        }

        return $habis;
    }
}
