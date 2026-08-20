<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Exports\ShopifyCollectionProductReportRowExporter;
use App\Filament\Resources\ShopifyCollectionProductReportRowResource\Pages;
use App\Models\ShopifyCollectionProductReportRow;
use App\Models\ShopifyCollectionProductReportRun;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class ShopifyCollectionProductReportRowResource extends Resource
{
    protected static ?string $model = ShopifyCollectionProductReportRow::class;

    protected static ?string $navigationGroup = 'Shopify Sync';

    protected static ?string $navigationLabel = 'Collection Product Mapping';

    protected static ?string $modelLabel = 'collection product mapping row';

    protected static ?string $pluralModelLabel = 'Shopify Collection Product Mapping';

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 7;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('product_created_at', 'desc')
            ->columns([
                TextColumn::make('collection_title')->label('Collection Title')->searchable()->sortable()->wrap(),
                TextColumn::make('collection_handle')->label('Collection Handle')->searchable()->toggleable(),
                TextColumn::make('collection_url')->label('Collection URL')->url(fn ($state) => $state)->openUrlInNewTab()->searchable()->copyable()->toggleable(),
                TextColumn::make('collection_sort_order')->label('Collection Sort Order')->sortable(),
                TextColumn::make('product_title')->label('Product Title')->searchable()->sortable()->placeholder('Empty collection')->wrap(),
                TextColumn::make('product_url')->label('Product URL')->url(fn ($state) => $state)->openUrlInNewTab()->searchable()->copyable()->toggleable(),
                TextColumn::make('product_status')->label('Product Status')->badge()->sortable(),
                TextColumn::make('main_collection')->label('Main Collection')->wrap(),
                TextColumn::make('product_type')->label('Product Type')->searchable()->sortable(),
                TextColumn::make('total_inventory')->label('Total Inventory')->numeric()->sortable(),
                TextColumn::make('product_created_at')->label('Product Created At')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('shopify_collection_product_report_run_id')
                    ->label('Report refresh')
                    ->options(fn (): array => ShopifyCollectionProductReportRun::query()
                        ->where('status', ShopifyCollectionProductReportRun::STATUS_COMPLETED)
                        ->latest('id')->limit(25)->get()
                        ->mapWithKeys(fn (ShopifyCollectionProductReportRun $run): array => [
                            $run->id => ($run->completed_at?->format('d M Y H:i') ?? "Run #{$run->id}")
                                ." ({$run->relationship_count} rows)",
                        ])->all())
                    ->default(fn (): ?int => ShopifyCollectionProductReportRun::query()
                        ->where('status', ShopifyCollectionProductReportRun::STATUS_COMPLETED)
                        ->latest('id')->value('id'))
                    ->preload(),
                SelectFilter::make('collection_visibility')
                    ->label('Collection Visibility')
                    ->options([
                        'published' => 'Published Online',
                        'unpublished' => 'Unpublished',
                        'all' => 'All',
                    ])
                    ->default('published')
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? 'published') {
                            'unpublished' => $query->where('collection_published_online', false),
                            'all' => $query,
                            default => $query->where('collection_published_online', true),
                        };
                    }),
                SelectFilter::make('collection_title')->label('Collection')
                    ->options(fn (): array => self::distinctOptions('collection_title'))->searchable()->preload(),
                SelectFilter::make('vendor')->options(fn (): array => self::distinctOptions('vendor'))->searchable()->preload(),
                SelectFilter::make('product_type')->label('Product Type')
                    ->options(fn (): array => self::distinctOptions('product_type'))->searchable()->preload(),
                SelectFilter::make('product_status')->label('Product Status')
                    ->options(fn (): array => self::distinctOptions('product_status'))
                    ->default('ACTIVE'),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Export filtered mapping')
                    ->icon('heroicon-o-document-arrow-down')
                    ->authorize(fn (): bool => self::canViewAny())
                    ->columnMapping(false)
                    ->formats([ExportFormat::Csv, ExportFormat::Xlsx])
                    ->exporter(ShopifyCollectionProductReportRowExporter::class),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListShopifyCollectionProductReportRows::route('/')];
    }

    private static function distinctOptions(string $column): array
    {
        return ShopifyCollectionProductReportRow::query()
            ->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column, $column)->all();
    }
}
