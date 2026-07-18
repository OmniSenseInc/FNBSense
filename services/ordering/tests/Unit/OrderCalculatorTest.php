<?php

namespace Tests\Unit;

use App\Exceptions\ProductNotOrderableException;
use App\Models\OrderSetting;
use App\Services\OrderCalculator;
use Tests\TestCase;

class OrderCalculatorTest extends TestCase
{
    private function setting(float $tax, float $serviceCharge): OrderSetting
    {
        return new OrderSetting([
            'tax_percent' => $tax,
            'service_charge_percent' => $serviceCharge,
        ]);
    }

    private function catalog(array $overrides = []): array
    {
        return array_merge([
            'p1' => ['name' => 'Espresso', 'price' => '10000.00'],
            'p2' => ['name' => 'Latte', 'price' => '25000.00'],
        ], $overrides);
    }

    public function test_hitung_dasar_dengan_service_charge_dan_pajak(): void
    {
        $result = (new OrderCalculator)->calculate(
            [['product_id' => 'p1', 'qty' => 2]],
            $this->catalog(),
            $this->setting(tax: 11, serviceCharge: 5),
        );

        // subtotal 20000; sc = 20000*5% = 1000; tax = (20000+1000)*11% = 2310
        $this->assertSame(20000, $result['subtotal']);
        $this->assertSame(1000, $result['service_charge']);
        $this->assertSame(2310, $result['tax']);
        $this->assertSame(23310, $result['grand_total']);
    }

    /** Bukti bergigi: PPN dikenakan SETELAH service charge, bukan atas subtotal saja. */
    public function test_pajak_dihitung_setelah_service_charge(): void
    {
        $result = (new OrderCalculator)->calculate(
            [['product_id' => 'p1', 'qty' => 1]],
            $this->catalog(),
            $this->setting(tax: 10, serviceCharge: 10),
        );

        // subtotal 10000; sc 1000; tax = (10000+1000)*10% = 1100 (BUKAN 1000).
        $this->assertSame(1100, $result['tax'], 'PPN harus atas subtotal+service_charge.');
        $this->assertSame(12100, $result['grand_total']);
    }

    public function test_menjumlahkan_banyak_item(): void
    {
        $result = (new OrderCalculator)->calculate(
            [
                ['product_id' => 'p1', 'qty' => 2], // 20000
                ['product_id' => 'p2', 'qty' => 1], // 25000
            ],
            $this->catalog(),
            $this->setting(tax: 0, serviceCharge: 0),
        );

        $this->assertSame(45000, $result['subtotal']);
        $this->assertSame(45000, $result['grand_total']);
    }

    public function test_harga_desimal_dibulatkan_ke_rupiah_bulat(): void
    {
        $result = (new OrderCalculator)->calculate(
            [['product_id' => 'p1', 'qty' => 1]],
            $this->catalog(['p1' => ['name' => 'X', 'price' => '10000.50']]),
            $this->setting(tax: 0, serviceCharge: 0),
        );

        // round(10000.50) = 10001; sekali di batas, integer selamanya.
        $this->assertSame(10001, $result['items'][0]['unit_price']);
        $this->assertIsInt($result['grand_total']);
    }

    public function test_tarif_nol_tidak_menambah_apa_apa(): void
    {
        $result = (new OrderCalculator)->calculate(
            [['product_id' => 'p1', 'qty' => 3]],
            $this->catalog(),
            $this->setting(tax: 0, serviceCharge: 0),
        );

        $this->assertSame(30000, $result['subtotal']);
        $this->assertSame(0, $result['service_charge']);
        $this->assertSame(0, $result['tax']);
        $this->assertSame(30000, $result['grand_total']);
    }

    public function test_menyimpan_snapshot_nama_dan_note_per_item(): void
    {
        $result = (new OrderCalculator)->calculate(
            [['product_id' => 'p1', 'qty' => 2, 'note' => 'tanpa gula']],
            $this->catalog(),
            $this->setting(tax: 0, serviceCharge: 0),
        );

        $item = $result['items'][0];
        $this->assertSame('Espresso', $item['product_name']);
        $this->assertSame(10000, $item['unit_price']);
        $this->assertSame(20000, $item['line_total']);
        $this->assertSame('tanpa gula', $item['note']);
    }

    public function test_produk_di_luar_menu_ditolak(): void
    {
        $this->expectException(ProductNotOrderableException::class);

        (new OrderCalculator)->calculate(
            [['product_id' => 'produk-tenant-lain', 'qty' => 1]],
            $this->catalog(),
            $this->setting(tax: 11, serviceCharge: 5),
        );
    }
}
