<?php

namespace App\Http\Controllers;

use App\Models\StockBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saldo bahan untuk service lain — "berapa sisa bahan-bahan ini di outlet itu?"
 *
 * Ada supaya Catalog bisa menandai produk habis di `/api/menu` TANPA memanggil
 * `/api/availability`: gerbang itu mengambil resep dengan memanggil balik
 * Catalog, jadi Catalog->Inventory->Catalog akan jadi lingkaran yang dipicu
 * tiap pemindaian QR. Di sini pembagiannya lurus — Inventory pemilik saldo,
 * Catalog pemilik resep dan yang menghitung.
 *
 * Bentuk TUNGGAL `balance`, sepola `availability` di sini dan `recipe` /
 * `ingredient` di Catalog: menandai endpoint mesin, bukan CRUD manusia.
 *
 * Bahan yang belum pernah distok sengaja TIDAK muncul di balasan. Menjawab
 * "0" untuknya terlihat lebih ramah tapi menyamarkan dua keadaan berbeda —
 * biar pemanggilnya yang memutuskan, dan keputusan itu memang sudah ada
 * (`AvailabilityController`: tak ada baris = nol, bukan "tak diawasi").
 */
class BalanceController extends Controller
{
    /** Batas id per permintaan — menu terpanjang pun jauh di bawah ini. */
    private const MAX_IDS = 500;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant' => ['required', 'uuid'],
            // Wajib, tak boleh punya default: saldo selalu per-outlet, dan
            // outlet yang diam-diam tertebak berarti menu satu cabang dinilai
            // memakai gudang cabang lain.
            'outlet' => ['required', 'uuid'],
            'ids' => ['required', 'string'],
        ]);

        // trim tiap id, sepola IngredientController::batch di Catalog — spasi
        // nyasar tak boleh diam-diam membuat satu bahan kehilangan saldonya
        // dan produknya dinyatakan habis padahal gudang penuh.
        $ids = array_values(array_filter(array_map('trim', explode(',', $data['ids']))));
        $ids = array_slice($ids, 0, self::MAX_IDS);

        $saldo = StockBalance::query()
            ->where('tenant_id', $data['tenant'])
            ->where('outlet_id', $data['outlet'])
            ->whereIn('ingredient_id', $ids)
            ->get(['ingredient_id', 'qty_on_hand']);

        return response()->json($saldo->map(fn (StockBalance $b) => [
            'ingredient_id' => $b->ingredient_id,
            'qty_on_hand' => $b->qty_on_hand,
        ])->values());
    }
}
