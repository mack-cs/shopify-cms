<?php

namespace App\Filament\Resources\ShopifyCollectionProductReportRowResource\Pages;

use App\Filament\Resources\ShopifyCollectionProductReportRowResource;
use App\Jobs\GenerateShopifyCollectionProductReportJob;
use App\Models\ShopifyCollectionProductReportRun;
use App\Services\ShopifyCollectionProductReportService;
use Filament\Actions\Action;
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

        return 'Last refreshed '.$run->completed_at?->timezone(config('app.timezone'))->format('d M Y \a\t H:i T')
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
                ->modalDescription('This queues a fresh Admin GraphQL snapshot. The normal view and export remain limited to collections currently published on the Online Store.')
                ->action(function (ShopifyCollectionProductReportService $reports): void {
                    $run = $reports->createRun(Auth::id());
                    GenerateShopifyCollectionProductReportJob::dispatch($run->id);

                    Notification::make()
                        ->title('Collection product mapping queued')
                        ->body("Report run #{$run->id} will appear when Shopify refresh completes.")
                        ->success()->send();
                }),
        ];
    }
}
