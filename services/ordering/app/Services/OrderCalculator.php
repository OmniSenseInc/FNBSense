<?php

namespace App\Services;

use App\Exceptions\ProductNotOrderableException;
use App\Models\OrderSetting;

/**
 * Menghitung total order dari sisi SERVER. Satu-satunya sumber kebenaran uang.
 *
 * Harga TIDAK PERNAH dari klien: unit_price diambil dari peta harga Catalog
 * (hasil CatalogClient) dan dibulatkan SEKALI ke rupiah bulat di batas ini,
 * lalu tak pernah jadi float lagi. Urutan hitung ditetapkan dan tak boleh
 * dibalik (hasilnya beda) — lihat docs/ORDERING.md.
 */
class OrderCalculator
{
    /**
     * @param  array<int, array{product_id: string, qty: int, note?: string|null}>  $items
     * @param  array<string, array{name: string, price: mixed}>  $catalogProducts  peta dari CatalogClient
     * @return array{
     *     gross_subtotal:int, discount_total:int, subtotal:int,
     *     service_charge:int, tax:int, grand_total:int, promotion:array|null,
     *     items: array<int, array{product_id: string, product_name: string, unit_price: int, qty: int, line_total: int, note: string|null}>
     * }
     *
     * @throws ProductNotOrderableException Salah satu product_id tak ada di menu tenant.
     */
    public function calculate(array $items, array $catalogProducts, OrderSetting $setting): array
    {
        $lines = [];
        $subtotal = 0;

        foreach ($items as $item) {
            $productId = $item['product_id'];

            if (! isset($catalogProducts[$productId])) {
                // Tak ada di menu tenant = tak dikenal / tak tersedia / milik
                // tenant lain. Jangan tebak harga, tolak.
                throw new ProductNotOrderableException("Produk {$productId} tidak tersedia.");
            }

            $product = $catalogProducts[$productId];

            // Dibulatkan SEKALI di batas sistem, lalu integer selamanya.
            $unitPrice = (int) round((float) $product['price']);
            $qty = (int) $item['qty'];
            $lineTotal = $unitPrice * $qty;
            $subtotal += $lineTotal;

            $lines[] = [
                'product_id' => $productId,
                'product_name' => $product['name'],
                'unit_price' => $unitPrice,
                'qty' => $qty,
                'line_total' => $lineTotal,
                'note' => $item['note'] ?? null,
            ];
        }

        // Urutan tetap: service charge dulu, PPN dikenakan SETELAH service charge
        // (praktik umum F&B Indonesia). Tarif dari setting, di-snapshot terpisah.
        $serviceChargePercent = (float) $setting->service_charge_percent;
        $taxPercent = (float) $setting->tax_percent;

        $serviceCharge = (int) round($subtotal * $serviceChargePercent / 100);
        $tax = (int) round(($subtotal + $serviceCharge) * $taxPercent / 100);
        $grandTotal = $subtotal + $serviceCharge + $tax;

        return [
            'gross_subtotal' => $subtotal,
            'discount_total' => 0,
            'subtotal' => $subtotal,
            'service_charge' => $serviceCharge,
            'tax' => $tax,
            'grand_total' => $grandTotal,
            'promotion' => null,
            'items' => $lines,
        ];
    }

    /**
     * Terapkan hasil evaluator tepercaya, lalu hitung ulang service charge dan
     * pajak dari subtotal net. Discount tidak pernah diterima dari browser.
     *
     * @param  array<string, mixed>  $calculation
     * @param  array{discount_total:int,promotion:array<string,mixed>|null}  $result
     * @return array<string, mixed>
     */
    public function applyPromotion(
        array $calculation,
        array $result,
        OrderSetting $setting,
    ): array {
        $grossSubtotal = (int) $calculation['gross_subtotal'];
        $discount = max(0, min((int) $result['discount_total'], $grossSubtotal));
        $subtotal = $grossSubtotal - $discount;
        $serviceCharge = (int) round(
            $subtotal * (float) $setting->service_charge_percent / 100,
        );
        $tax = (int) round(
            ($subtotal + $serviceCharge) * (float) $setting->tax_percent / 100,
        );

        return [
            ...$calculation,
            'discount_total' => $discount,
            'subtotal' => $subtotal,
            'service_charge' => $serviceCharge,
            'tax' => $tax,
            'grand_total' => $subtotal + $serviceCharge + $tax,
            'promotion' => $discount > 0 ? $result['promotion'] : null,
        ];
    }
}
