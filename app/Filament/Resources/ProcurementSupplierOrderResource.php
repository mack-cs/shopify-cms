<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProcurementSupplierOrderResource\Pages;
use App\Filament\Resources\ProcurementSupplierOrderResource\RelationManagers\LinesRelationManager;
use App\Models\ProcurementSupplierOrder;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms;

final class ProcurementSupplierOrderResource extends Resource
{
    protected static ?string $model = ProcurementSupplierOrder::class;
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationLabel = 'Purchase Orders';
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lines.receipts', 'createdBy'])->withCount('lines'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')->label('Order ID')->searchable()->sortable()->placeholder('Legacy order'),
                TextColumn::make('lines_count')->label('Items')->numeric()->sortable(),
                TextColumn::make('outstanding')->label('Qty Outstanding')
                    ->state(fn (ProcurementSupplierOrder $record): int => $record->lines->sum->quantity_outstanding),
                TextColumn::make('status')->badge()->state(fn (ProcurementSupplierOrder $record): string =>
                    $record->lines->contains(fn ($line): bool => $line->quantity_outstanding > 0 && $line->status === 'open')
                        ? ($record->lines->contains(fn ($line): bool => $line->quantity_received > 0) ? 'Partially received' : 'Open')
                        : ($record->lines->every(fn ($line): bool => $line->status === 'cancelled') ? 'Cancelled' : 'Received')),
                TextColumn::make('source')->badge(),
                TextColumn::make('createdBy.name')->label('Created By')->placeholder('System'),
                TextColumn::make('created_at')->label('Created')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Order Status')->options([
                    'open' => 'Pending/Open',
                    'partial' => 'Partially Received',
                    'completed' => 'Completed',
                ])->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'open' => $query->whereHas('lines', fn (Builder $lineQuery): Builder => $lineQuery->where('status', 'open')->whereDoesntHave('receipts', fn (Builder $receiptQuery): Builder => $receiptQuery->where('status', 'succeeded'))),
                        'partial' => $query->whereHas('lines.receipts', fn (Builder $receiptQuery): Builder => $receiptQuery->where('status', 'succeeded'))
                            ->whereHas('lines', fn (Builder $lineQuery): Builder => $lineQuery->where('status', 'open')),
                        'completed' => $query->whereDoesntHave('lines', fn (Builder $lineQuery): Builder => $lineQuery->where('status', 'open')),
                        default => $query,
                    };
                }),
                Filter::make('supplier')->form([
                    Forms\Components\TextInput::make('vendor')->label('Supplier/vendor'),
                ])->query(fn (Builder $query, array $data): Builder => filled($data['vendor'] ?? null)
                    ? $query->whereHas('lines.variant.product', fn (Builder $productQuery): Builder => $productQuery->where('vendor', 'like', '%'.$data['vendor'].'%'))
                    : $query),
                Filter::make('sku')->form([
                    Forms\Components\TextInput::make('sku')->label('SKU/product'),
                ])->query(fn (Builder $query, array $data): Builder => filled($data['sku'] ?? null)
                    ? $query->whereHas('lines', fn (Builder $lineQuery): Builder => $lineQuery->where('sku', 'like', '%'.$data['sku'].'%')
                        ->orWhereHas('variant.product', fn (Builder $productQuery): Builder => $productQuery->where('title', 'like', '%'.$data['sku'].'%')))
                    : $query),
                Filter::make('grv')->form([
                    Forms\Components\TextInput::make('grv_number')->label('GRV Number'),
                ])->query(fn (Builder $query, array $data): Builder => filled($data['grv_number'] ?? null)
                    ? $query->whereHas('lines.receipts', fn (Builder $receiptQuery): Builder => $receiptQuery->where('grv_number', trim((string) $data['grv_number'])))
                    : $query),
                Filter::make('created_between')->form([
                    Forms\Components\DatePicker::make('from')->label('From')->native(false),
                    Forms\Components\DatePicker::make('until')->label('Until')->native(false),
                ])->query(function (Builder $query, array $data): Builder {
                    return $query
                        ->when($data['from'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('created_at', '<=', $date));
                }),
            ]);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProcurementSupplierOrders::route('/'),
            'view' => Pages\ViewProcurementSupplierOrder::route('/{record}'),
        ];
    }
}
