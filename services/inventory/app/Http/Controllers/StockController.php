<?php

namespace App\Http\Controllers;

use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Saldo stok: dibaca kasir & owner, DIUBAH owner saja (restock + opname manual).
 *
 * Invarian: qty_on_hand SELALU hasil dari ledger stock_movements, tak pernah
 * ditulis bebas. Tiap perubahan saldo dibarengi 1 baris movement, atomik dalam
 * transaksi + lockForUpdate (cegah lost-update saat 2 aksi barengan).
 *
 * Scoping: tenant_id + outlet_id diambil dari klaim JWT, bukan dari body —
 * owner tak bisa menyentuh stok outlet/tenant lain (anti-IDOR).
 */
class StockController extends Controller
{
    /**
     * Daftar saldo stok outlet ini.
     *
     * Balasannya daftar-izin, bukan model mentah. Endpoint ini dibaca KASIR
     * juga, dan model mentah menerbitkan setiap kolom yang kelak ditambahkan
     * ke tabel: begitu harga beli bahan masuk ke `stock_balances`, angka itu
     * sampai ke layar kasir tanpa satu baris kode pun berubah dan tanpa satu
     * tes pun jadi merah. `tenant_id`/`outlet_id` sengaja tak dikirim — kedua
     * nilai itu berasal dari token si peminta, jadi mengembalikannya cuma
     * memberi tahu dia apa yang sudah dia bawa.
     */
    public function index(Request $request): JsonResponse
    {
        $balances = StockBalance::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            ->orderBy('ingredient_id')
            ->get();

        return response()->json(['data' => $balances->map(fn (StockBalance $saldo) => [
            'ingredient_id' => $saldo->ingredient_id,
            'qty_on_hand' => $saldo->qty_on_hand,
            'min_stock' => $saldo->min_stock,
            'updated_at' => $saldo->updated_at,
        ])->all()]);
    }

    /**
     * Tambah stok (barang masuk). qty selalu positif; saldo bertambah.
     * Opsi A: bahan baru (belum punya baris saldo) lahir otomatis di sini.
     */
    public function restock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredient_id' => ['required', 'uuid'],
            'qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $balance = DB::transaction(function () use ($request, $validated) {
            $balance = $this->lockOrNewBalance($request, $validated['ingredient_id']);
            $balance->qty_on_hand = (float) $balance->qty_on_hand + (float) $validated['qty'];
            $balance->save();

            $this->recordMovement($request, $validated['ingredient_id'], (float) $validated['qty'], 'restock');

            return $balance;
        });

        return response()->json(['data' => $balance], 201);
    }

    /**
     * Opname manual: owner input hasil hitung fisik (counted_qty), sistem hitung
     * selisihnya sendiri lalu catat sebagai movement — ledger tetap jujur.
     */
    public function adjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredient_id' => ['required', 'uuid'],
            'counted_qty' => ['required', 'numeric', 'min:0'], // fisik tak mungkin negatif.
        ]);

        $balance = DB::transaction(function () use ($request, $validated) {
            $balance = $this->lockOrNewBalance($request, $validated['ingredient_id']);
            $delta = (float) $validated['counted_qty'] - (float) $balance->qty_on_hand;

            // Selisih 0 = tak ada koreksi; jangan kotori ledger dengan movement kosong.
            if ($delta !== 0.0) {
                $balance->qty_on_hand = (float) $validated['counted_qty'];
                $balance->save();
                $this->recordMovement($request, $validated['ingredient_id'], $delta, 'manual_adjust');
            }

            return $balance;
        });

        return response()->json(['data' => $balance]);
    }

    /**
     * Ambil baris saldo dengan lock, atau siapkan baris baru (saldo 0) bila belum ada.
     * ponytail: dua restock barengan atas bahan yang SAMA & baru → keduanya lihat null,
     * insert kedua kena unique(outlet_id, ingredient_id) → gagal (owner retry). Baris yang
     * sudah ada aman via lockForUpdate. Ini kasus umum; race bahan-baru cukup dijaga unique DB.
     */
    private function lockOrNewBalance(Request $request, string $ingredientId): StockBalance
    {
        $balance = StockBalance::query()
            ->where('outlet_id', $this->outletId($request))
            ->where('ingredient_id', $ingredientId)
            ->lockForUpdate()
            ->first();

        return $balance ?? new StockBalance([
            'tenant_id' => $this->tenantId($request),
            'outlet_id' => $this->outletId($request),
            'ingredient_id' => $ingredientId,
            'qty_on_hand' => 0,
        ]);
    }

    private function recordMovement(Request $request, string $ingredientId, float $qtyDelta, string $reason): void
    {
        StockMovement::create([
            'tenant_id' => $this->tenantId($request),
            'outlet_id' => $this->outletId($request),
            'ingredient_id' => $ingredientId,
            'order_id' => null, // null = gerakan manual (bukan dari event order).
            'qty_delta' => $qtyDelta,
            'reason' => $reason,
            'occurred_at' => now(),
            'created_by' => $request->attributes->get('user_id'),
        ]);
    }

    private function tenantId(Request $request): string
    {
        return $request->attributes->get('tenant_id');
    }

    private function outletId(Request $request): string
    {
        return $request->attributes->get('outlet_id');
    }
}
