<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Models\ProcessedEvent;
use App\Models\SalesFact;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Membentuk read-model Reporting dari event order.paid secara idempoten. */
class OrderPaidConsumer
{
    public function handle(array $envelope): ConsumeOutcome
    {
        if (! $this->validEnvelope($envelope)) {
            Log::warning('reporting.consume: amplop order.paid malformed → DLQ.');

            return ConsumeOutcome::Dead;
        }

        $eventId = $envelope['event_id'];
        if (ProcessedEvent::query()->whereKey($eventId)->exists()) {
            return ConsumeOutcome::Ack;
        }

        try {
            $this->record($envelope);
        } catch (UniqueConstraintViolationException) {
            return ConsumeOutcome::Ack;
        } catch (QueryException) {
            Log::warning("reporting.consume: galat DB, requeue event {$eventId}.");

            return ConsumeOutcome::Requeue;
        }

        return ConsumeOutcome::Ack;
    }

    private function record(array $envelope): void
    {
        DB::transaction(function () use ($envelope): void {
            $payload = $envelope['payload'];
            $totals = $payload['totals'];
            $promotion = $payload['promotion'] ?? null;
            $sale = SalesFact::create([
                'event_id' => $envelope['event_id'],
                'order_id' => $payload['order_id'],
                'tenant_id' => $envelope['tenant_id'],
                'outlet_id' => $envelope['outlet_id'],
                'gross_subtotal' => $totals['gross_subtotal'] ?? $totals['subtotal'],
                'discount_total' => $totals['discount_total'] ?? 0,
                'subtotal' => $totals['subtotal'],
                'service_charge' => $totals['service_charge'],
                'tax' => $totals['tax'],
                'grand_total' => $totals['grand_total'],
                'promotion_id' => $promotion['id'] ?? null,
                'promotion_name' => $promotion['name'] ?? null,
                'promotion_template' => $promotion['template'] ?? null,
                'payment_method' => $payload['payment_method'] ?? null,
                'paid_at' => $envelope['occurred_at'],
            ]);

            foreach ($payload['items'] as $item) {
                $sale->products()->create([
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'] ?? null,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['qty'] * $item['unit_price'],
                ]);
            }

            ProcessedEvent::create([
                'event_id' => $envelope['event_id'],
                'event_type' => 'order.paid',
                'processed_at' => now(),
            ]);
        });
    }

    private function validEnvelope(array $envelope): bool
    {
        if (($envelope['event_type'] ?? null) !== 'order.paid'
            || ! $this->uuid($envelope['event_id'] ?? null)
            || ! $this->uuid($envelope['tenant_id'] ?? null)
            || ! $this->uuid($envelope['outlet_id'] ?? null)
            || ! $this->date($envelope['occurred_at'] ?? null)) {
            return false;
        }

        $payload = $envelope['payload'] ?? null;
        if (! is_array($payload) || ! $this->nonEmptyString($payload['order_id'] ?? null)
            || ! is_array($payload['items'] ?? null) || $payload['items'] === []) {
            return false;
        }

        $totals = $payload['totals'] ?? null;
        if (! is_array($totals)) {
            return false;
        }
        foreach (['subtotal', 'service_charge', 'tax', 'grand_total'] as $field) {
            if (! $this->nonNegativeInt($totals[$field] ?? null)) {
                return false;
            }
        }
        if ($totals['grand_total'] !== $totals['subtotal'] + $totals['service_charge'] + $totals['tax']) {
            return false;
        }

        $hasGrossSubtotal = array_key_exists('gross_subtotal', $totals);
        $hasDiscountTotal = array_key_exists('discount_total', $totals);
        if ($hasGrossSubtotal !== $hasDiscountTotal) {
            return false;
        }
        if ($hasGrossSubtotal
            && (! $this->nonNegativeInt($totals['gross_subtotal'])
                || ! $this->nonNegativeInt($totals['discount_total'])
                || $totals['discount_total'] > $totals['gross_subtotal']
                || $totals['subtotal'] !== $totals['gross_subtotal'] - $totals['discount_total'])) {
            return false;
        }

        $promotion = $payload['promotion'] ?? null;
        if ($promotion !== null
            && (! is_array($promotion)
                || ! $this->uuid($promotion['id'] ?? null)
                || ! is_string($promotion['name'] ?? null)
                || $promotion['name'] === ''
                || mb_strlen($promotion['name']) > 255
                || ! in_array($promotion['template'] ?? null, [
                    'order_percentage',
                    'order_fixed',
                    'product_percentage',
                    'bundle_fixed_price',
                ], true)
                || ! $hasDiscountTotal
                || $totals['discount_total'] === 0)) {
            return false;
        }

        if (isset($payload['payment_method']) && ! in_array($payload['payment_method'], ['cash', 'qris_static'], true)) {
            return false;
        }

        foreach ($payload['items'] as $item) {
            if (! is_array($item) || ! $this->uuid($item['product_id'] ?? null)
                || ! $this->positiveInt($item['qty'] ?? null)
                || ! $this->nonNegativeInt($item['unit_price'] ?? null)
                || $item['unit_price'] > intdiv(PHP_INT_MAX, $item['qty'])
                || (isset($item['product_name']) && (! is_string($item['product_name']) || $item['product_name'] === '' || mb_strlen($item['product_name']) > 255))) {
                return false;
            }
        }

        return true;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }

    private function nonNegativeInt(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private function positiveInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function date(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        try {
            Carbon::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
