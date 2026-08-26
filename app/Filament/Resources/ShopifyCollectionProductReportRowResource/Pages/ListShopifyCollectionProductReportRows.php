<?php

namespace App\Filament\Resources\ShopifyCollectionProductReportRowResource\Pages;

use App\Filament\Resources\ShopifyCollectionProductReportRowResource;
use App\Jobs\GenerateShopifyCollectionProductReportJob;
use App\Jobs\PublishCollectionMappingToGoogleSheetsJob;
use App\Models\ShopifyCollectionProductReportRun;
use App\Services\ShopifyCollectionProductReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

final class ListShopifyCollectionProductReportRows extends ListRecords
{
    protected static string $resource = ShopifyCollectionProductReportRowResource::class;

    public function getSubheading(): ?string
    {
        $run = ShopifyCollectionProductReportRun::query()
            ->where('status', ShopifyCollectionProductReportRun::STATUS_COMPLETED)
            ->latest('id')->first();

        if (! $run instanceof ShopifyCollectionProductReportRun) {
            return 'No completed mapping exists. Use Refresh from Shopify to generate one.';
        }

        return 'Last fetched from Shopify '.$run->completed_at?->timezone(config('app.timezone'))->format('d M Y \a\t H:i T')
            ." · {$run->collection_count} collections · {$run->relationship_count} mapping rows";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshFromShopify')
                ->label('Refresh from Shopify')
                ->icon('heroicon-o-arrow-path')
                ->authorize(fn (): bool => ShopifyCollectionProductReportRowResource::canViewAny())
                ->requiresConfirmation()
                ->modalDescription('This queues a complete Shopify snapshot for every collection. Existing report data remains available until the refresh finishes.')
                ->action(function (ShopifyCollectionProductReportService $reports): void {
                    $run = $reports->createRun(Auth::id());
                    GenerateShopifyCollectionProductReportJob::dispatch($run->id);

                    Notification::make()
                        ->title('Collection product mapping queued')
                        ->body('Report run #'.$run->id.' will refresh all Shopify collections.')
                        ->success()->send();
                }),
            Action::make('exportToGoogleSheets')
                ->label('Export to Google Sheets')
                ->icon('heroicon-o-table-cells')
                ->color('success')
                ->authorize(fn (): bool => ShopifyCollectionProductReportRowResource::canViewAny())
                ->disabled(fn (): bool => ! config('google_sheets.collection_mapping_enabled') || self::latestCompletedRun() === null)
                ->form([
                    Select::make('collection_handles')
                        ->label('Collections to export')
                        ->options(fn (): array => self::googleSheetCollectionOptions())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Each selected handle updates its own tab using the latest completed full refresh.'),
                ])
                ->requiresConfirmation()
                ->modalDescription('Selected collection tabs will be replaced with the latest Shopify mapping, newest products first.')
                ->action(function (array $data): void {
                    $run = self::latestCompletedRun();
                    if (! $run instanceof ShopifyCollectionProductReportRun) {
                        Notification::make()->title('Run a Shopify refresh first')->warning()->send();

                        return;
                    }

                    $handles = array_values((array) ($data['collection_handles'] ?? []));
                    PublishCollectionMappingToGoogleSheetsJob::dispatch($run->id, $handles, Auth::id());

                    Notification::make()
                        ->title('Google Sheets export queued')
                        ->body('Exporting '.count($handles).' selected collection(s) from report run #'.$run->id.'.')
                        ->success()->send();
                }),
        ];
    }

    private static function latestCompletedRun(): ?ShopifyCollectionProductReportRun
    {
        return ShopifyCollectionProductReportRun::query()
            ->where('status', ShopifyCollectionProductReportRun::STATUS_COMPLETED)
            ->latest('id')
            ->first();
    }

    /** @return array<string, string> */
    private static function googleSheetCollectionOptions(): array
    {
        $run = self::latestCompletedRun();
        if (! $run instanceof ShopifyCollectionProductReportRun) {
            return [];
        }

        return $run->rows()
            ->whereNotNull('collection_handle')
            ->where('collection_handle', '!=', '')
            ->select(['collection_handle', 'collection_title'])
            ->distinct()
            ->orderBy('collection_title')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $row->collection_handle => ($row->collection_title ?: $row->collection_handle).' ('.$row->collection_handle.')',
            ])
            ->all();
    }
}
