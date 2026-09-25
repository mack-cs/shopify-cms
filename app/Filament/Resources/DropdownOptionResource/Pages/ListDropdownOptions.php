<?php

namespace App\Filament\Resources\DropdownOptionResource\Pages;

use App\Filament\Resources\DropdownOptionResource;
use App\Jobs\RecalculateDropdownOptionProductsJob;
use App\Services\DropdownReviewWorkbookExporter;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDropdownOptions extends ListRecords
{
    protected static string $resource = DropdownOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add dropdown value'),
            Actions\Action::make('exportReviewWorkbook')
                ->label('Export Review Workbook')
                ->icon('heroicon-o-table-cells')
                ->color('success')
                ->action(fn (DropdownReviewWorkbookExporter $exporter) => response()->streamDownload(
                    fn () => print $exporter->export(
                        DropdownOptionResource::reviewHeaders(),
                        DropdownOptionResource::reviewCollections(),
                    ),
                    'dropdown-review-by-collection-'.now()->format('Ymd_His').'.xlsx',
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                )),
            Actions\Action::make('revalidateProducts')
                ->label('Revalidate product dropdowns')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription('Queues a fresh dropdown validation for existing products, including products with stale Materials and Dimensions errors.')
                ->action(function (): void {
                    RecalculateDropdownOptionProductsJob::dispatchSync(null, null);

                    Notification::make()
                        ->title('Product dropdown revalidation complete')
                        ->success()
                        ->send();
                }),
        ];
    }
}
