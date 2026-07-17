<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTableRequest;
use App\Http\Requests\UpdateTableRequest;
use App\Models\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manajemen meja — owner saja.
 *
 * qr_token default-nya $hidden di model (biar tak bocor tak sengaja di endpoint
 * lain), tapi di SINI justru harus tampak: owner perlu token itu untuk mencetak
 * QR mejanya. Karena itu tiap response memanggil makeVisible() secara sadar.
 */
class TableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tables = $this->scoped($request)
            ->orderBy('label')
            ->get()
            ->makeVisible('qr_token');

        return response()->json(['data' => $tables]);
    }

    public function store(StoreTableRequest $request): JsonResponse
    {
        // tenant_id & outlet_id lewat argumen, TIDAK dari body — inilah yang
        // menjaga meja tak bisa ditanam di outlet milik orang lain.
        $table = Table::createForOutlet(
            $this->tenantId($request),
            $this->outletId($request),
            $request->validated(),
        );

        return response()->json(['data' => $table->makeVisible('qr_token')], 201);
    }

    public function update(UpdateTableRequest $request, string $id): JsonResponse
    {
        $table = $this->scoped($request)->findOrFail($id);

        $table->update($request->validated());

        return response()->json(['data' => $table->makeVisible('qr_token')]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $table = $this->scoped($request)->findOrFail($id);

        $table->delete();

        return response()->json(['message' => 'Meja dihapus.']);
    }

    /**
     * Terbitkan qr_token baru — QR lama langsung mati.
     *
     * Dipakai saat QR tersebar/difoto orang. Identitas meja (id) sengaja tidak
     * berubah supaya riwayat order lama tetap menempel ke meja yang sama.
     */
    public function rotateQr(Request $request, string $id): JsonResponse
    {
        $table = $this->scoped($request)->findOrFail($id);

        $table->qr_token = Table::generateQrToken();
        $table->save();

        return response()->json(['data' => $table->makeVisible('qr_token')]);
    }

    /**
     * Semua query meja WAJIB lewat sini.
     *
     * Di-scope tenant_id DAN outlet_id dari klaim JWT, jadi findOrFail() pada
     * meja milik tenant/outlet lain menghasilkan 404 — bukan 403 yang justru
     * membocorkan bahwa meja itu ada.
     */
    private function scoped(Request $request): Builder
    {
        return Table::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request));
    }
}
