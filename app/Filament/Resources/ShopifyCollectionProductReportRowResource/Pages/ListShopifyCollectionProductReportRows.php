<?php

namespace App\Filament\Resources\ShopifyCollectionProductReportRowResource\Pages;

use App\Filament\Resources\ShopifyCollectionProductReportRowResource;
use App\Jobs\GenerateShopifyCollectionProductReportJob;
use App\Models\ShopifyCollection;
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
                ->form([
                    Select::make('collection_handles')
                        ->label('Collection handles')
                        ->options(fn (): array => ShopifyCollection::query()
                            ->whereNotNull('handle')
                            ->where('handle', '!=', '')
                            ->orderBy('title')
                            ->get(['handle', 'title'])
                            ->mapWithKeys(fn (ShopifyCollection $collection): array => [
                                $collection->handle => ($collection->title ?: $collection->handle).' ('.$collection->handle.')',
                            ])
                            ->all())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Only the selected collection handles will be fetched and pushed to their spreadsheet tabs.'),
                ])
                ->requiresConfirmation()
                ->modalDescription('This queues a fresh Shopify snapshot for the selected handles. Each collection replaces its own Google Sheets tab with the newest products first.')
                ->action(function (array $data, ShopifyCollectionProductReportService $reports): void {
                    $handles = array_values((array) ($data['collection_handles'] ?? []));
                    $run = $reports->createRun(Auth::id(), $handles);
                    GenerateShopifyCollectionProductReportJob::dispatch($run->id);

                    Notification::make()
                        ->title('Collection product mapping queued')
                        ->body('Report run #'.$run->id.' will fetch '.count($handles).' selected collection(s).')
                        ->success()->send();
                }),
        ];
    }
}
