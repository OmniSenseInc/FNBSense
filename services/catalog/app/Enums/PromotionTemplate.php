<?php

declare(strict_types=1);

namespace App\Enums;

enum PromotionTemplate: string
{
    case OrderPercentage = 'order_percentage';
    case OrderFixed = 'order_fixed';
    case ProductPercentage = 'product_percentage';
    case BundleFixedPrice = 'bundle_fixed_price';

    /**
     * @return array<int, array{
     *   key:string,
     *   label:string,
     *   description:string,
     *   required_fields:array<int,string>,
     *   supports:array<string,bool>
     * }>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => self::OrderPercentage->value,
                'label' => 'Diskon Persentase Order',
                'description' => 'Potongan persen dari subtotal order.',
                'required_fields' => ['name', 'percentage'],
                'supports' => [
                    'products' => false,
                    'min_subtotal' => true,
                    'max_discount' => true,
                    'schedule' => true,
                ],
            ],
            [
                'key' => self::OrderFixed->value,
                'label' => 'Potongan Nominal Order',
                'description' => 'Potongan rupiah setelah minimum transaksi terpenuhi.',
                'required_fields' => ['name', 'amount'],
                'supports' => [
                    'products' => false,
                    'min_subtotal' => true,
                    'max_discount' => false,
                    'schedule' => true,
                ],
            ],
            [
                'key' => self::ProductPercentage->value,
                'label' => 'Diskon Produk',
                'description' => 'Potongan persen untuk produk yang dipilih.',
                'required_fields' => ['name', 'percentage', 'products'],
                'supports' => [
                    'products' => true,
                    'min_subtotal' => true,
                    'max_discount' => true,
                    'schedule' => true,
                ],
            ],
            [
                'key' => self::BundleFixedPrice->value,
                'label' => 'Harga Paket / Bundle',
                'description' => 'Kombinasi produk dijual dengan satu harga paket.',
                'required_fields' => ['name', 'amount', 'products'],
                'supports' => [
                    'products' => true,
                    'min_subtotal' => true,
                    'max_discount' => false,
                    'schedule' => true,
                ],
            ],
        ];
    }
}
