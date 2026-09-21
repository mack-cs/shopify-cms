<?php

namespace App\Filament\Resources\ProductMovementReportRowResource\Pages;

use App\Filament\Resources\ProductMovementReportRowResource;
use App\Jobs\GenerateProductMovementReportJob;
use App\Models\ProductMovementReportRun;
use App\Services\ProductMovementReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class ListProductMovementReportRows extends ListRecords
{
    protected static string $resource = ProductMovementReportRowResource::class;

    public function getSubheading(): ?string
    {
        $runId = data_get($this->tableFilters, 'product_movement_report_run_id.value');
        $run = ProductMovementReportRun::query()
            ->where('status', ProductMovementReportRun::STATUS_COMPLETED)
            ->when(filled($runId), fn (Builder $query): Builder => $query->whereKey((int) $runId))
            ->latest('id')
            ->first();

        if (!$run instanceof ProductMovementReportRun || $run->completed_at === null) {
            return 'No completed product movement report is available yet.';
        }

        $timezone = (string) config('product_movement.timezone', 'Africa/Johannesburg');

        return 'Report generated ' . $run->completed_at->copy()->timezone($timezone)->format('d M Y \a\t H:i T')
            . ' · Reporting period ' . $run->analysis_start_date->format('d M Y')
            . ' to ' . $run->analysis_end_date->format('d M Y');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateReport')
                ->label('Generate report')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->authorize(fn (): bool => ProductMovementReportRowResource::canViewAny())
                ->form([
                    Select::make('period')
                        ->options([
                            '6_months' => 'Last 6 months',
                            '12_months' => 'Last 12 months',
                            'custom' => 'Custom date range',
                        ])
                        ->default('6_months')
                        ->required()
                        ->live(),
                    DatePicker::make('from')
                        ->label('Analysis start date')
                        ->visible(fn (Get $get): bool => $get('period') === 'custom')
                        ->required(fn (Get $get): bool => $get('period') === 'custom')
                        ->maxDate(fn (Get $get) => $get('to') ?: today()),
                    DatePicker::make('to')
                        ->label('Analysis end date')
                        ->default(today())
                        ->visible(fn (Get $get): bool => $get('period') === 'custom')
                        ->required(fn (Get $get): bool => $get('period') === 'custom')
                        ->maxDate(today())
                        ->afterOrEqual('from'),
                ])
                ->modalDescription('The queued job first reads current Shopify product status, prices and inventory, then calculates movement from the selected order period. You will be notified when it is ready.')
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
                        ->title('Product movement report queued')
                        ->body("Shopify refresh and report calculation queued for {$from} to {$end}.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
