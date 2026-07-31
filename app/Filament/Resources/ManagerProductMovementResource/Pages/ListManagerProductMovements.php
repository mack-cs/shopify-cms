<?php

namespace App\Filament\Resources\ManagerProductMovementResource\Pages;

use App\Filament\Resources\ManagerProductMovementResource;
use App\Filament\Resources\ManagerProductMovementResource\Widgets\ManagerMovementStats;
use App\Jobs\GenerateProductMovementReportJob;
use App\Services\ProductMovementReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

final class ListManagerProductMovements extends ListRecords
{
    protected static string $resource = ManagerProductMovementResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ManagerMovementStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateReport')
                ->label('Generate / Refresh Report')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->form([
                    Select::make('period')
                        ->options([
                            '6_months' => 'Past 6 months',
                            '12_months' => 'Past 12 months',
                            'custom' => 'Custom period',
                        ])
                        ->default('6_months')
                        ->required()
                        ->live(),
                    DatePicker::make('from')
                        ->label('Start date')
                        ->visible(fn (Get $get): bool => $get('period') === 'custom')
                        ->required(fn (Get $get): bool => $get('period') === 'custom')
                        ->maxDate(fn (Get $get) => $get('to') ?: today()),
                    DatePicker::make('to')
                        ->label('End date')
                        ->default(today())
                        ->visible(fn (Get $get): bool => $get('period') === 'custom')
                        ->required(fn (Get $get): bool => $get('period') === 'custom')
                        ->maxDate(today())
                        ->afterOrEqual('from'),
                ])
                ->modalDescription('The queued report checks Shopify for current sale prices, status and inventory before calculating movement.')
                ->action(function (array $data, ProductMovementReportService $reports): void {
                    $end = ($data['period'] ?? '6_months') === 'custom'
                        ? (string) $data['to']
                        : today()->toDateString();
                    $from = match ($data['period'] ?? '6_months') {
                        '12_months' => today()->subMonths(12)->addDay()->toDateString(),
                        'custom' => (string) $data['from'],
                        default => today()->subMonths(6)->addDay()->toDateString(),
                    };

                    $run = $reports->createRun($from, $end, Auth::id());
                    GenerateProductMovementReportJob::dispatch($run->id);

                    Notification::make()
                        ->title('Manager movement report queued')
                        ->body("Shopify refresh and movement calculation queued for {$from} to {$end}.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
