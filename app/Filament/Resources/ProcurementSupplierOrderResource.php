<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProcurementSupplierOrderResource\Pages;
use App\Filament\Resources\ProcurementSupplierOrderResource\RelationManagers\LinesRelationManager;
use App\Models\ProcurementSupplierOrder;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
