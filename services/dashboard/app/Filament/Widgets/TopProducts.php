<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\ReportingClient;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class TopProducts extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.top-products';

    protected int|string|array $columnSpan = 'full';

    public function products(): array
    {
        $result = app(ReportingClient::class)->topProducts(
            (string) ($this->pageFilters['from'] ?? now()->subDays(29)->toDateString()),
            (string) ($this->pageFilters['to'] ?? now()->toDateString()),
            10,
        );

        return $result['ok'] ? ($result['data']['products'] ?? []) : [];
    }
}
