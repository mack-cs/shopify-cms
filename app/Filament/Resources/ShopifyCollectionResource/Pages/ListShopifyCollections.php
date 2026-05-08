<?php

namespace App\Filament\Resources\ShopifyCollectionResource\Pages;

use App\Filament\Resources\ShopifyCollectionResource;
use Filament\Resources\Pages\ListRecords\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListShopifyCollections extends ListRecords
{
    protected static string $resource = ShopifyCollectionResource::class;

    public function getTabs(): array
    {
        $counts = $this->reportTabCounts();

        return [
            'all' => Tab::make('All'),
            'approved' => Tab::make('Approved')
                ->badge((string) $counts['approved'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => ShopifyCollectionResource::applyApprovedFilter($query)),
            'has_seo' => Tab::make('Has SEO')
                ->badge((string) $counts['has_seo'])
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query): Builder => ShopifyCollectionResource::applyHasSeoFilter($query)),
            'needs_seo' => Tab::make('Needs SEO')
                ->badge((string) $counts['needs_seo'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => ShopifyCollectionResource::applyNeedsSeoFilter($query)),
            'has_description' => Tab::make('Has Description')
                ->badge((string) $counts['has_description'])
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query): Builder => ShopifyCollectionResource::applyHasDescriptionFilter($query)),
            'no_description' => Tab::make('No Description')
                ->badge((string) $counts['no_description'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => ShopifyCollectionResource::applyNoDescriptionFilter($query)),
            'good' => Tab::make('Good')
                ->badge((string) $counts['good'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => ShopifyCollectionResource::applyGoodFilter($query)),
            'indexed' => Tab::make('Indexed')
                ->badge((string) $counts['indexed'])
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => ShopifyCollectionResource::applyIndexedFilter($query)),
            'deindexed' => Tab::make('Deindexed')
                ->badge((string) $counts['deindexed'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => ShopifyCollectionResource::applyDeindexedFilter($query)),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function reportTabCounts(): array
    {
        return [
            'approved' => ShopifyCollectionResource::applyApprovedFilter(ShopifyCollectionResource::getEloquentQuery())->count(),
            'has_seo' => ShopifyCollectionResource::applyHasSeoFilter(ShopifyCollectionResource::getEloquentQuery())->count(),
            'needs_seo' => ShopifyCollectionResource::applyNeedsSeoFilter(ShopifyCollectionResource::getEloquentQuery())->count(),
            'has_description' => ShopifyCollectionResource::applyHasDescriptionFilter(ShopifyCollectionResource::getEloquentQuery())->count(),
            'no_description' => ShopifyCollectionResource::applyNoDescriptionFilter(ShopifyCollectionResource::getEloquentQuery())->count(),
            'good' => ShopifyCollectionResource::applyGoodFilter(ShopifyCollectionResource::getEloquentQuery())->count(),
            'indexed' => ShopifyCollectionResource::applyIndexedFilter(ShopifyCollectionResource::getEloquentQuery())->count(),
            'deindexed' => ShopifyCollectionResource::applyDeindexedFilter(ShopifyCollectionResource::getEloquentQuery())->count(),
        ];
    }
}
