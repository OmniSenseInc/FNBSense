<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PromotionEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PromotionEvaluationController extends Controller
{
    public function __invoke(Request $request, PromotionEvaluator $evaluator): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'outlet_id' => ['required', 'uuid'],
            'subtotal' => ['required', 'integer', 'min:0', 'max:49500000000000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.line_total' => ['required', 'integer', 'min:0', 'max:990000000000'],
        ]);

        $calculatedSubtotal = 0;
        foreach ($validated['items'] as $index => $item) {
            if ($item['line_total'] !== $item['unit_price'] * $item['qty']) {
                throw ValidationException::withMessages([
                    "items.{$index}.line_total" => 'Line total tidak konsisten.',
                ]);
            }
            $calculatedSubtotal += $item['line_total'];
        }

        if ($calculatedSubtotal !== $validated['subtotal']) {
            throw ValidationException::withMessages([
                'subtotal' => 'Subtotal tidak konsisten dengan item.',
            ]);
        }

        return response()->json(['data' => $evaluator->evaluate(
            $validated['tenant_id'],
            $validated['outlet_id'],
            $validated['subtotal'],
            $validated['items'],
        )]);
    }
}
