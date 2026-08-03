<?php

namespace App\Filament\Resources\ManagerProductMovementResource\Pages;

use App\Filament\Resources\ManagerProductMovementResource;
use App\Jobs\GenerateProductMovementReportJob;
use App\Services\ProductMovementReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class ListManagerProductMovements extends ListRecords
{
    protected static string $resource = ManagerProductMovementResource::class;
    protected static string $view = 'filament.resources.manager-product-movements.list';

    /**
     * Keep these statistics inside this page component. Using
     * InteractsWithPageTable from a header widget creates a second instance of
     * this same page and can invalidate Livewire's signed component snapshot.
     *
     * @return array<int,array{label:string,value:string,color:string}>
     */
    public function getManagerStats(): array
    {
        $query = $this->getFilteredTableQuery();

        return [
            $this->managerStat('Products analysed', $this->uniqueProductCount(clone $query), 'gray'),
            $this->managerStat(
                'Fast moving',
                $this->uniqueProductCount((clone $query)->where('movement_classification', 'fast_moving')),
                'success',
            ),
            $this->managerStat(
                'Slow moving',
                $this->uniqueProductCount((clone $query)->where('movement_classification', 'slow_moving')),
                'warning',
            ),
            $this->managerStat(
                'No sales',
                $this->uniqueProductCount((clone $query)->where('movement_classification', 'no_sales')),
                'danger',
            ),
            $this->managerStat(
                'Need restocking',
                $this->uniqueProductCount((clone $query)->where('recommended_action', 'restock')),
                'success',
            ),
            $this->managerStat(
                'Need review',
                $this->uniqueProductCount(
                    (clone $query)->whereIn('recommended_action', ['review', 'insufficient_data'])
                ),
                'danger',
            ),
        ];
    }

    private function uniqueProductCount(Builder $query): int
    {
        return (int) $query->reorder()->distinct()->count('product_id');
    }

    /**
     * @return array{label:string,value:string,color:string}
     */
    private function managerStat(string $label, int $value, string $color): array
    {
        return [
            'label' => $label,
            'value' => number_format($value),
            'color' => $color,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateReport')
                ->label('Generate / Refresh Report')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->authorize(fn (): bool => ManagerProductMovementResource::canViewAny())
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
