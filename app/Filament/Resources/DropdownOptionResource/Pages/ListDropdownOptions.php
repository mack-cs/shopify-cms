<?php

namespace App\Filament\Resources\DropdownOptionResource\Pages;

use App\Filament\Resources\DropdownOptionResource;
use App\Jobs\RecalculateDropdownOptionProductsJob;
use App\Services\DropdownReviewWorkbookExporter;
use App\Services\DropdownReviewWorkbookImporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Storage;

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
            Actions\Action::make('importReviewWorkbook')
                ->label('Import Review Workbook')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning')
                ->modalWidth(MaxWidth::Medium)
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('Edited review workbook')
                        ->disk('local')
                        ->directory('imports/dropdown-review')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/octet-stream',
                        ])
                        ->required(),
                    Forms\Components\Toggle::make('deactivate_missing')
                        ->label('Deactivate values missing from the workbook')
                        ->helperText('Leave off to only add/reactivate/update listed values. Turn on only when this workbook is the approved final list.')
                        ->default(false),
                ])
                ->action(function (array $data, DropdownReviewWorkbookImporter $importer): void {
                    $file = is_string($data['file'] ?? null) ? $data['file'] : null;
                    if ($file === null) {
                        Notification::make()->title('No workbook selected')->danger()->send();

                        return;
                    }

                    $summary = $importer->import(
                        Storage::disk('local')->path($file),
                        DropdownOptionResource::reviewHeaders(),
                        DropdownOptionResource::reviewCollections(),
                        (bool) ($data['deactivate_missing'] ?? false),
                    );

                    Notification::make()
                        ->title('Review workbook imported')
                        ->body("Created: {$summary['created']}. Reactivated: {$summary['reactivated']}. Updated: {$summary['updated']}. Deactivated: {$summary['deactivated']}.")
                        ->success()
                        ->send();
                }),
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
