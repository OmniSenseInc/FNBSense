<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Pengeluaran outlet (F5d). Semua di-scope tenant+outlet dari JWT — pencatat tak
 * bisa nulis/lihat pengeluaran outlet lain.
 */
class ExpenseController extends Controller
{
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $data = $request->validated();

        $expense = Expense::create([
            'tenant_id' => $this->tenantId($request),
            'outlet_id' => $this->outletId($request),
            'category' => $data['category'],
            'amount' => (int) $data['amount'],
            'note' => $data['note'] ?? null,
            // Default now(); backdate hanya kalau kasir kirim spent_at eksplisit.
            'spent_at' => isset($data['spent_at']) ? Carbon::parse($data['spent_at']) : now(),
            'recorded_by' => $this->userId($request),
        ]);

        return response()->json(['data' => $this->present($expense)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        // ponytail: batasi 200 terbaru; ganti ke paginate() kalau UI butuh scroll history.
        $rows = Expense::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            ->orderByDesc('spent_at')
            ->limit(200)
            ->get()
            ->map(fn (Expense $e) => $this->present($e));

        return response()->json(['data' => $rows]);
    }

    /** @return array<string, mixed> */
    private function present(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'outlet_id' => $expense->outlet_id,
            'category' => $expense->category,
            'amount' => $expense->amount,
            'note' => $expense->note,
            'spent_at' => $expense->spent_at,
            'recorded_by' => $expense->recorded_by,
        ];
    }
}
