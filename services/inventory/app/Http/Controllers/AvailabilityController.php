<?php

namespace App\Http\Controllers;

use App\Exceptions\CatalogUnavailableException;
use App\Models\StockBalance;
use App\Services\CatalogRecipeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Produk mana yang bahannya tak cukup?" — gerbang stok SEBELUM uang berpindah.
 *
 * Sebelum ini stok hanya diperiksa saat `order.paid`, yaitu SESUDAH pelanggan
 * membayar: saldo menjadi negatif, `inventory.shortfall` terbit, dan kasir
 * ditinggal dengan uang yang sudah diterima untuk barang yang tak ada. Endpoint
 * ini memindahkan pemeriksaan itu ke depan.
 *
 * Yang dibalas HANYA daftar product_id, tanpa satu angka saldo pun: pemanggilnya
 * (Ordering) sedang melayani orang tak berakun di ujung sana, dan berapa sisa
 * susu di gudang bukan urusan pelanggan.
 *
 * Catalog mati -> 503, BUKAN "semua tersedia". Gerbang yang membuka lebar saat
 * pengawasnya pingsan bukan gerbang. Ordering yang memutuskan apa artinya itu
 * bagi pelanggan.
 */
class AvailabilityController extends Controller
{
    public function __construct(private readonly CatalogRecipeClient $catalog) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'outlet_id' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.qty' => ['required', 'integer', 'gt:0'],
        ]);

        // Satu produk boleh muncul dua kali dalam satu pesanan (dua baris "Kopi
        // Susu"). Yang menentukan cukup-tidaknya adalah TOTALNYA — memeriksa
        // tiap baris sendiri-sendiri akan meloloskan dua baris yang masing-masing
        // muat tapi bersama-sama tidak.
        $diminta = [];
        foreach ($data['items'] as $baris) {
            $id = $baris['product_id'];
            $diminta[$id] = ($diminta[$id] ?? 0) + (int) $baris['qty'];
        }

        try {
            $resep = $this->catalog->recipesForProducts($data['tenant_id'], array_keys($diminta));
        } catch (CatalogUnavailableException $e) {
            return response()->json(['message' => 'Katalog resep tidak dapat dihubungi.'], 503);
        }

        // Kebutuhan total per bahan, digabung LINTAS produk: espresso dan kopi
        // susu sama-sama memakai biji kopi, dan yang menentukan adalah jumlah
        // keduanya terhadap satu saldo yang sama.
        $butuh = [];
        // Bahan mana dibutuhkan produk mana — dipakai menamai produk yang jatuh.
        $pemakai = [];

        foreach ($diminta as $productId => $qty) {
            foreach ($resep[$productId] ?? [] as $bahan) {
                $ingredientId = $bahan['ingredient_id'] ?? null;
                $per = $bahan['qty_per_unit'] ?? null;

                // Baris resep rusak di-skip, sepola consumer: satu baris cacat
                // tak boleh membuat seluruh pesanan ditolak.
                if (! is_string($ingredientId) || ! is_numeric($per)) {
                    continue;
                }

                $butuh[$ingredientId] = ($butuh[$ingredientId] ?? 0.0) + ((float) $per * $qty);
                $pemakai[$ingredientId][] = $productId;
            }
        }

        $saldo = StockBalance::query()
            ->where('tenant_id', $data['tenant_id'])
            ->where('outlet_id', $data['outlet_id'])
            ->whereIn('ingredient_id', array_keys($butuh))
            ->pluck('qty_on_hand', 'ingredient_id');

        $takCukup = [];
        foreach ($butuh as $ingredientId => $perlu) {
            // Bahan tanpa baris saldo = belum pernah distok = nol, bukan
            // "tak diawasi". Menganggapnya tersedia berarti menjual barang yang
            // belum pernah masuk gudang sama sekali.
            if ((float) ($saldo[$ingredientId] ?? 0) < $perlu) {
                foreach ($pemakai[$ingredientId] as $productId) {
                    $takCukup[$productId] = true;
                }
            }
        }

        return response()->json(['data' => ['unavailable' => array_keys($takCukup)]]);
    }
}
