<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StackInventoryResource\Pages;
use App\Jobs\CheckShopifyStackInventory;
use App\Models\NewProductDraft;
use App\Services\StackInventoryAuditService;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

final class StackInventoryResource extends Resource
{
    private const STOCK_MISMATCHES = [
        'Ready · Shopify unavailable',
        'Ready · CMS shows zero',
        'Ready · Shopify shows zero',
        'Ready · CMS shows out of stock',
    ];

    protected static ?string $model = NewProductDraft::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stack Inventory';

    protected static ?string $modelLabel = 'stack inventory';

    protected static ?string $pluralModelLabel = 'Stack Inventory';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereNotNull('bundle_product_ids'))
            ->defaultSort('title')
            ->poll(fn (): ?string => Cache::has(CheckShopifyStackInventory::PENDING_KEY) ? '10s' : null)
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('title')
                    ->label('Stack Name')
                    ->searchable(['title', 'handle'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('sku')
                    ->label('Stack SKU')
                    ->searchable()
                    ->sortable()
                    ->placeholder('No SKU'),
                TextColumn::make('stack_health')
                    ->label('Stack Status')
                    ->state(fn (NewProductDraft $record): string => self::audit()->health($record)['status'])
                    ->description(fn (NewProductDraft $record): ?string => self::audit()->health($record)['reason'])
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Ready' => 'success',
                        'Out of stock' => 'danger',
                        default => 'warning',
                    })
                    ->wrap(),
                TextColumn::make('shopify_stock_check')
                    ->label('Shopify Check')
                    ->state(fn (NewProductDraft $record): ?string => self::shopifyRow($record)['status'] ?? null)
                    ->description(function (NewProductDraft $record): ?string {
                        $row = self::shopifyRow($record);
                        if ($row === []) {
                            return null;
                        }

                        $parent = $row['parent'];
                        $quantity = match ($parent['tracked'] ?? null) {
                            true => ($parent['available_quantity'] ?? 'Unknown').' available',
                            false => 'Tracking off',
                            default => 'Parent unverified',
                        };

                        return $row['reason'].' '.$quantity.'.';
                    })
                    ->placeholder('Run Shopify check')
                    ->badge()
                    ->color(fn (?string $state): string => match (true) {
                        $state === 'Ready · Shopify available' => 'success',
                        in_array($state, self::STOCK_MISMATCHES, true) => 'danger',
                        default => 'warning',
                    })
                    ->wrap(),
                self::componentGroup(1),
                self::componentGroup(2),
                self::componentGroup(3),
            ])
            ->filters([
                Filter::make('ready_stock_mismatch')
                    ->label('Ready stacks with stock mismatches')
                    ->query(fn (Builder $query): Builder => $query->whereKey(collect(self::shopifyReport()['rows'] ?? [])
                        ->filter(fn (array $row): bool => in_array($row['status'], self::STOCK_MISMATCHES, true))
                        ->keys()->all())),
            ])
            ->striped()
            ->emptyStateHeading('No configured stacks found')
            ->emptyStateDescription('Stacks appear here after component products have been associated with them.')
            ->emptyStateIcon('heroicon-o-squares-plus');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStackInventories::route('/'),
        ];
    }

    private static function componentGroup(int $position): ColumnGroup
    {
        return ColumnGroup::make("Component {$position}")
            ->columns([
                TextColumn::make("component_{$position}_name")
                    ->label('Name / SKU')
                    ->state(fn (NewProductDraft $record): ?string => self::component($record, $position)['title'] ?? null)
                    ->description(function (NewProductDraft $record) use ($position): ?string {
                        $component = self::component($record, $position);
                        if ($component === null) {
                            return null;
                        }

                        $sku = $component['sku'] ?? 'No SKU';
                        $quantity = (int) $component['quantity_per_stack'];

                        return $quantity > 1 ? "{$sku} - Uses {$quantity}" : $sku;
                    })
                    ->placeholder('Not configured')
                    ->wrap(),
                TextColumn::make("component_{$position}_available")
                    ->label('Available')
                    ->state(fn (NewProductDraft $record): string => self::quantity($record, $position, 'available'))
                    ->badge()
                    ->color(fn (NewProductDraft $record): string => self::componentColor($record, $position)),
                TextColumn::make("component_{$position}_on_hand")
                    ->label('On Hand')
                    ->state(fn (NewProductDraft $record): string => self::quantity($record, $position, 'on_hand'))
                    ->description(fn (NewProductDraft $record): ?string => self::componentDetail($record, $position))
                    ->wrap(),
            ]);
    }

    /** @return array<string, mixed>|null */
    private static function component(NewProductDraft $record, int $position): ?array
    {
        return self::audit()->component($record, $position);
    }

    private static function quantity(NewProductDraft $record, int $position, string $field): string
    {
        $live = self::shopifyRow($record)['components'][$position] ?? null;
        if ($live !== null) {
            return match ($live['tracked'] ?? null) {
                false => 'Not tracked',
                true => (string) ($live[$field === 'available' ? 'available_quantity' : 'on_hand_quantity'] ?? 'Unknown'),
                default => 'Unverified',
            };
        }

        $component = self::component($record, $position);
        if ($component === null) {
            return '-';
        }
        if ($component['tracked'] === false) {
            return 'Not tracked';
        }

        $quantity = $component[$field] ?? null;

        return $quantity === null ? 'Unknown' : (string) $quantity;
    }

    private static function componentColor(NewProductDraft $record, int $position): string
    {
        $live = self::shopifyRow($record)['components'][$position] ?? null;
        if ($live !== null) {
            return match ($live['ready']) {
                true => 'success',
                false => 'danger',
                null => 'warning',
            };
        }

        return match (self::component($record, $position)['status'] ?? null) {
            'In stock', 'Not tracked' => 'success',
            'Out of stock', 'Insufficient', 'Inactive', 'Missing', 'No variant' => 'danger',
            null => 'gray',
            default => 'warning',
        };
    }

    private static function componentDetail(NewProductDraft $record, int $position): ?string
    {
        $live = self::shopifyRow($record)['components'][$position] ?? null;
        if ($live !== null) {
            return $live['error'] ?? 'Shopify checked '.\Illuminate\Support\Carbon::parse($live['observed_at'])
                ->timezone(config('app.timezone'))->format('d/m/Y H:i');
        }

        $component = self::component($record, $position);
        if ($component === null) {
            return null;
        }

        $parts = array_filter([
            $component['reason'] ?? null,
            $component['synced_at'] !== null
                ? 'Synced '.$component['synced_at']->format('d/m/Y H:i')
                : 'Not synced yet',
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    private static function audit(): StackInventoryAuditService
    {
        return app(StackInventoryAuditService::class);
    }

    public static function shopifyReport(): array
    {
        return once(fn (): array => Cache::get(CheckShopifyStackInventory::RESULT_KEY, []));
    }

    private static function shopifyRow(NewProductDraft $record): array
    {
        return self::shopifyReport()['rows'][$record->id] ?? [];
    }
}
