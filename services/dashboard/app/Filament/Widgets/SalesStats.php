<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\ReportingClient;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Kinerja penjualan';

    protected function getStats(): array
    {
        $result = app(ReportingClient::class)->summary(...$this->period());

        if (! $result['ok']) {
            return [
                Stat::make('Reporting', 'Tidak tersedia')
                    ->description($result['message'])
                    ->color('danger'),
            ];
        }

        $data = $result['data'];
        $payment = collect($data['by_payment_method'] ?? []);
        $cash = (int) ($payment->firstWhere('method', 'cash')['revenue'] ?? 0);
        $qris = (int) ($payment->firstWhere('method', 'qris_static')['revenue'] ?? 0);

        return [
            Stat::make('Omzet', $this->rupiah((int) ($data['revenue'] ?? 0)))
                ->description('Order PAID pada periode terpilih')
                ->color('success'),
            Stat::make('Transaksi', number_format((int) ($data['transactions'] ?? 0), 0, ',', '.'))
                ->description('Jumlah order yang sudah dibayar'),
            Stat::make('Rata-rata transaksi', $this->rupiah((int) ($data['average_order_value'] ?? 0)))
                ->description('Average order value'),
            Stat::make('Cash / QRIS', $this->rupiah($cash).' / '.$this->rupiah($qris))
                ->description('Komposisi omzet berdasarkan metode bayar'),
        ];
    }

    private function period(): array
    {
        return [
            (string) ($this->pageFilters['from'] ?? now()->subDays(29)->toDateString()),
            (string) ($this->pageFilters['to'] ?? now()->toDateString()),
        ];
    }

    private function rupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }
}
