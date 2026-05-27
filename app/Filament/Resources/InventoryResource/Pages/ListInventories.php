<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use App\Filament\Resources\InventoryResource\Widgets\InventoryRunBanner;
use App\Jobs\DailyShopifyInventoryRefreshJob;
use App\Models\Variant;
use App\Services\AsyncJobStateService;
use App\Services\InventoryAccessService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('checkShopifyInventory')
                ->label('Check Shopify Inventory')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => app(InventoryAccessService::class)->canAccess(Auth::user()))
                ->requiresConfirmation()
                ->modalHeading('Check Shopify Inventory')
                ->modalDescription('This will read the latest product status and variant inventory from Shopify into the local inventory records without pushing local changes to Shopify.')
                ->modalSubmitActionLabel('Queue Check')
                ->action(function (): void {
                    app(AsyncJobStateService::class)->markQueued(AsyncJobStateService::INVENTORY_CHECK);
                    DailyShopifyInventoryRefreshJob::dispatch(Auth::id());

                    Notification::make()
                        ->title('Shopify inventory check queued')
                        ->body('The read-only Shopify inventory refresh is running in the background.')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            InventoryRunBanner::class,
        ];
    }

    public function getTabs(): array
    {
        $counts = $this->tabCounts();

        return [
            'all' => Tab::make('All')
                ->badge((string) $counts['all']),
            'in_stock' => Tab::make('In Stock')
                ->badge((string) $counts['in_stock'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->whereRaw('LOWER(status) = ?', ['active']))
                    ->where('inventory_tracked', true)
                    ->where('inventory_qty', '>', 0)),
            'out_of_stock' => Tab::make('Out Of Stock')
                ->badge((string) $counts['out_of_stock'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('inventory_tracked', true)
                    ->where('inventory_qty', '<=', 0)),
            'not_tracked' => Tab::make('Not Tracked')
                ->badge((string) $counts['not_tracked'])
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(function (Builder $builder): void {
                    $builder->where('inventory_tracked', false)
                        ->orWhereNull('inventory_tracked');
                })),
        ];
    }

    /**
     * @return array{all:int,in_stock:int,out_of_stock:int,not_tracked:int}
     */
    private function tabCounts(): array
    {
        $baseQuery = Variant::query()->whereHas('product');

        return [
            'all' => (clone $baseQuery)->count(),
            'in_stock' => (clone $baseQuery)
                ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->whereRaw('LOWER(status) = ?', ['active']))
                ->where('inventory_tracked', true)
                ->where('inventory_qty', '>', 0)
                ->count(),
            'out_of_stock' => (clone $baseQuery)
                ->where('inventory_tracked', true)
                ->where('inventory_qty', '<=', 0)
                ->count(),
            'not_tracked' => (clone $baseQuery)
                ->where(function (Builder $builder): void {
                    $builder->where('inventory_tracked', false)
                        ->orWhereNull('inventory_tracked');
                })
                ->count(),
        ];
    }
}
