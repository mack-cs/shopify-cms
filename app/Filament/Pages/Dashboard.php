<?php

namespace App\Filament\Pages;

use App\Models\SeoPeriod;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'SEO Dashboard';

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('period_id')
                    ->label('Reporting period')
                    ->options(fn (): array => SeoPeriod::query()
                        ->orderByDesc('sort_order')
                        ->orderByDesc('start_date')
                        ->orderByDesc('id')
                        ->pluck('label', 'id')
                        ->all())
                    ->placeholder('Latest available period')
                    ->searchable(),
                Select::make('entity_type')
                    ->label('Metric scope')
                    ->options([
                        'site' => 'Site totals',
                        'query' => 'Search queries',
                        'page' => 'Pages',
                    ])
                    ->default('site')
                    ->selectablePlaceholder(false),
            ])
            ->columns([
                'md' => 2,
            ]);
    }
}
