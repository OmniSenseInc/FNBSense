<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingRequest;
use App\Models\OrderSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tarif transaksi outlet — owner saja.
 *
 * Outlet yang belum pernah dikonfigurasi TIDAK punya baris di DB. show() sengaja
 * mengembalikan default bertarif 0 tanpa menulis apa pun: membaca setting tidak
 * boleh diam-diam membuat data.
 */
class SettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $setting = $this->find($request)
            ?? OrderSetting::defaultsFor($this->tenantId($request), $this->outletId($request));

        return response()->json(['data' => $setting]);
    }

    public function update(UpdateSettingRequest $request): JsonResponse
    {
        $setting = $this->find($request);

        if ($setting !== null) {
            return response()->json(['data' => $this->applyTo($setting, $request)]);
        }

        // Baris lahir saat owner pertama kali menyimpan tarif. Ini titik balapan:
        // dua request bersamaan (double-klik Save, retry klien) sama-sama melihat
        // "belum ada baris" lalu sama-sama INSERT. Yang kalah ditolak unique
        // constraint outlet_id. Cek-lalu-tulis TIDAK bisa menutup ini — DB yang
        // memutuskan siapa menang, jadi kekalahan itu ditangkap dan diselesaikan
        // sebagai update, bukan dibiarkan bocor jadi 500 ke owner.
        $baru = OrderSetting::defaultsFor($this->tenantId($request), $this->outletId($request));

        try {
            return response()->json(['data' => $this->applyTo($baru, $request)]);
        } catch (UniqueConstraintViolationException) {
            $pemenang = $this->find($request);

            return response()->json(['data' => $this->applyTo($pemenang, $request)]);
        }
    }

    private function applyTo(OrderSetting $setting, UpdateSettingRequest $request): OrderSetting
    {
        $setting->fill($request->validated());
        $setting->save();

        return $setting;
    }

    /**
     * Setting outlet ini, atau null kalau belum pernah dikonfigurasi.
     *
     * Di-scope tenant_id DAN outlet_id: outlet_id memang unique global, tapi
     * menyaring tenant juga bikin baris milik tenant lain mustahil tersentuh
     * walau outlet_id-nya entah bagaimana ketebak.
     */
    private function find(Request $request): ?OrderSetting
    {
        return OrderSetting::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            ->first();
    }
}
