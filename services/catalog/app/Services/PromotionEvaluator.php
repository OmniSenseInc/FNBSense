<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PromotionStatus;
use App\Enums\PromotionTemplate;
use App\Models\Promotion;

class PromotionEvaluator
{
    /**
     * @param  array<int, array{product_id:string,unit_price:int,qty:int,line_total:int}>  $items
     * @return array{discount_total:int,promotion:array<string,mixed>|null}
     */
    public function evaluate(string $tenantId, string $outletId, int $subtotal, array $items): array
    {
        $promotions = Promotion::query()
            ->where('tenant_id', $tenantId)
            ->where('outlet_id', $outletId)
            ->where('status', PromotionStatus::Active->value)
            ->where(fn ($query) => $query
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', now()))
            ->with('products')
            ->get();

        $best = null;

        foreach ($promotions as $promotion) {
            $discount = $this->discount($promotion, $subtotal, $items);

            if ($discount <= 0) {
                continue;
            }

            $candidate = [
                'discount_total' => min($discount, $subtotal),
                'promotion' => $this->snapshot($promotion),
            ];

            if ($best === null
                || $candidate['discount_total'] > $best['discount_total']
                || ($candidate['discount_total'] === $best['discount_total']
                    && $promotion->priority > $best['promotion']['priority'])) {
                $best = $candidate;
            }
        }

        return $best ?? ['discount_total' => 0, 'promotion' => null];
    }

    /**
     * @param  array<int, array{product_id:string,unit_price:int,qty:int,line_total:int}>  $items
     */
    private function discount(Promotion $promotion, int $subtotal, array $items): int
    {
        if ($subtotal < $promotion->min_subtotal) {
            return 0;
        }

        $discount = match ($promotion->template) {
            PromotionTemplate::OrderPercentage => (int) round(
                $subtotal * (int) $promotion->percentage / 100,
            ),
            PromotionTemplate::OrderFixed => (int) $promotion->amount,
            PromotionTemplate::ProductPercentage => $this->productPercentage($promotion, $items),
            PromotionTemplate::BundleFixedPrice => $this->bundleDiscount($promotion, $items),
        };

        if ($promotion->max_discount !== null) {
            $discount = min($discount, $promotion->max_discount);
        }

        return max(0, min($discount, $subtotal));
    }

    /**
     * @param  array<int, array{product_id:string,unit_price:int,qty:int,line_total:int}>  $items
     */
    private function productPercentage(Promotion $promotion, array $items): int
    {
        $targetIds = $promotion->products->pluck('product_id')->flip();
        $eligibleSubtotal = collect($items)
            ->filter(fn (array $item): bool => $targetIds->has($item['product_id']))
            ->sum('line_total');

        return (int) round($eligibleSubtotal * (int) $promotion->percentage / 100);
    }

    /**
     * @param  array<int, array{product_id:string,unit_price:int,qty:int,line_total:int}>  $items
     */
    private function bundleDiscount(Promotion $promotion, array $items): int
    {
        $cart = [];
        foreach ($items as $item) {
            $productId = $item['product_id'];
            $cart[$productId] ??= ['qty' => 0, 'unit_price' => $item['unit_price']];
            $cart[$productId]['qty'] += $item['qty'];
        }

        $sets = null;
        $regularSetPrice = 0;

        foreach ($promotion->products as $target) {
            $line = $cart[$target->product_id] ?? null;
            if ($line === null) {
                return 0;
            }

            $requiredQty = (int) $target->required_qty;
            $setsForProduct = intdiv((int) $line['qty'], $requiredQty);
            $sets = $sets === null ? $setsForProduct : min($sets, $setsForProduct);
            $regularSetPrice += (int) $line['unit_price'] * $requiredQty;
        }

        if ($sets === null || $sets < 1) {
            return 0;
        }

        $discountPerSet = max(0, $regularSetPrice - (int) $promotion->amount);

        return $discountPerSet * $sets;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Promotion $promotion): array
    {
        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'template' => $promotion->template->value,
            'percentage' => $promotion->percentage,
            'amount' => $promotion->amount,
            'min_subtotal' => $promotion->min_subtotal,
            'max_discount' => $promotion->max_discount,
            'priority' => $promotion->priority,
            'products' => $promotion->products->map(fn ($target) => [
                'product_id' => $target->product_id,
                'required_qty' => (int) $target->required_qty,
            ])->values()->all(),
        ];
    }
}
