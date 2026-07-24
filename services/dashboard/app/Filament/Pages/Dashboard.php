<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Ringkasan Bisnis';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Periode laporan')
                    ->description('Semua angka di bawah hanya berasal dari order berstatus PAID.')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Dari')
                            ->default(now()->subDays(29)->toDateString())
                            ->maxDate(now())
                            ->required(),
                        DatePicker::make('to')
                            ->label('Sampai')
                            ->default(now()->toDateString())
                            ->maxDate(now())
                            ->afterOrEqual('from')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public function getColumns(): int|array
    {
        return [
            'md' => 2,
            'xl' => 2,
        ];
    }
}
