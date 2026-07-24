<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PromotionStatus;
use App\Enums\PromotionTemplate;
use App\Http\Requests\StorePromotionRequest;
use App\Http\Requests\UpdatePromotionRequest;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PromotionController extends Controller
{
    public function templates(): JsonResponse
    {
        return response()->json(['data' => PromotionTemplate::definitions()]);
    }

    public function index(Request $request): JsonResponse
    {
        $promotions = $this->scoped($request)
            ->with('products')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $promotions->map(fn (Promotion $promotion) => $this->present($promotion)),
        ]);
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $promotion = DB::transaction(function () use ($request): Promotion {
            $validated = $request->validated();
            $promotion = new Promotion(Arr::except($validated, 'products'));
            $promotion->tenant_id = $this->tenantId($request);
            $promotion->outlet_id = $this->outletId($request);
            $promotion->save();
            $this->replaceProducts($promotion, $validated['products'] ?? []);

            return $promotion->load('products');
        });

        return response()->json(['data' => $this->present($promotion)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'data' => $this->present($this->findScoped($request, $id)->load('products')),
        ]);
    }

    public function update(UpdatePromotionRequest $request, string $id): JsonResponse
    {
        $promotion = DB::transaction(function () use ($request, $id): Promotion {
            $promotion = $this->findScoped($request, $id);
            $validated = $request->validated();
            $promotion->update(Arr::except($validated, 'products'));
            $this->replaceProducts($promotion, $validated['products'] ?? []);

            return $promotion->load('products');
        });

        return response()->json(['data' => $this->present($promotion)]);
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        $promotion = $this->findScoped($request, $id);

        if ($promotion->ends_at !== null && $promotion->ends_at->isPast()) {
            throw ValidationException::withMessages([
                'ends_at' => 'Promo yang sudah berakhir tidak dapat diaktifkan.',
            ]);
        }

        $promotion->status = PromotionStatus::Active;
        $promotion->save();

        return response()->json(['data' => $this->present($promotion->load('products'))]);
    }

    public function pause(Request $request, string $id): JsonResponse
    {
        $promotion = $this->findScoped($request, $id);
        $promotion->status = PromotionStatus::Paused;
        $promotion->save();

        return response()->json(['data' => $this->present($promotion->load('products'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $promotion = $this->findScoped($request, $id);

        if ($promotion->status === PromotionStatus::Active) {
            throw new HttpException(409, 'Pause promo sebelum menghapusnya.');
        }

        $promotion->delete();

        return response()->json(['message' => 'Promo dihapus.']);
    }

    private function scoped(Request $request): Builder
    {
        return Promotion::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request));
    }

    private function findScoped(Request $request, string $id): Promotion
    {
        return $this->scoped($request)->findOrFail($id);
    }

    private function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('tenant_id');
    }

    private function outletId(Request $request): string
    {
        $outletId = $request->attributes->get('outlet_id');

        if (! is_string($outletId) || $outletId === '') {
            throw new HttpException(403, 'Akun owner belum terikat outlet.');
        }

        return $outletId;
    }

    /**
     * @param  array<int, array{product_id:string,required_qty?:int}>  $products
     */
    private function replaceProducts(Promotion $promotion, array $products): void
    {
        $promotion->products()->delete();

        foreach ($products as $product) {
            $promotion->products()->create([
                'product_id' => $product['product_id'],
                'required_qty' => $product['required_qty'] ?? 1,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Promotion $promotion): array
    {
        $effectiveStatus = $promotion->status->value;

        if ($promotion->status === PromotionStatus::Active
            && $promotion->starts_at !== null && $promotion->starts_at->isFuture()) {
            $effectiveStatus = 'scheduled';
        } elseif ($promotion->status === PromotionStatus::Active
            && $promotion->ends_at !== null && $promotion->ends_at->isPast()) {
            $effectiveStatus = 'expired';
        }

        return [
            'id' => $promotion->id,
            'tenant_id' => $promotion->tenant_id,
            'outlet_id' => $promotion->outlet_id,
            'name' => $promotion->name,
            'template' => $promotion->template->value,
            'status' => $promotion->status->value,
            'effective_status' => $effectiveStatus,
            'percentage' => $promotion->percentage,
            'amount' => $promotion->amount,
            'min_subtotal' => $promotion->min_subtotal,
            'max_discount' => $promotion->max_discount,
            'priority' => $promotion->priority,
            'starts_at' => $promotion->starts_at,
            'ends_at' => $promotion->ends_at,
            'products' => $promotion->products->map(fn ($target) => [
                'product_id' => $target->product_id,
                'required_qty' => (int) $target->required_qty,
            ])->values()->all(),
        ];
    }
}
