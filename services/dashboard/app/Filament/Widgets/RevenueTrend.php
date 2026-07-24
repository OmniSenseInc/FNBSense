<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\ReportingClient;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class RevenueTrend extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Tren omzet harian';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $result = app(ReportingClient::class)->dailyTrend(...$this->period());
        $points = $result['ok'] ? ($result['data']['points'] ?? []) : [];

        return [
            'datasets' => [[
                'label' => 'Omzet',
                'data' => collect($points)->pluck('revenue')->map(fn ($value) => (int) $value)->all(),
                'borderColor' => '#059669',
                'backgroundColor' => 'rgba(5, 150, 105, 0.14)',
                'fill' => true,
                'tension' => 0.35,
            ]],
            'labels' => collect($points)->pluck('date')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    private function period(): array
    {
        return [
            (string) ($this->pageFilters['from'] ?? now()->subDays(29)->toDateString()),
            (string) ($this->pageFilters['to'] ?? now()->toDateString()),
        ];
    }
}
