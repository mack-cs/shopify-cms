<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\SkuDailyDemandResource\Pages;
use App\Models\SkuDailyDemand;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SkuDailyDemandResource extends Resource
{
    protected static ?string $model = SkuDailyDemand::class;
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Shopify Sync';
    protected static ?string $navigationLabel = 'SKU Demand';
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('demand_date', 'desc')
            ->columns([
                TextColumn::make('demand_date')->date()->sortable(),
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('gross_units')->sortable(),
                TextColumn::make('cancelled_units')->sortable(),
                TextColumn::make('refunded_units')->sortable(),
                TextColumn::make('net_units')->sortable(),
                TextColumn::make('order_count')->sortable(),
                TextColumn::make('gross_revenue')->money('ZAR')->sortable(),
                TextColumn::make('discount_amount')->money('ZAR')->sortable(),
                TextColumn::make('net_revenue')->money('ZAR')->sortable(),
                TextColumn::make('calculated_at')->dateTime()->sortable(),
            ])
            ->filters([
                Filter::make('recent')
                    ->label('Last 30 days')
                    ->query(fn (Builder $query): Builder => $query->where('demand_date', '>=', now()->subDays(30)->toDateString())),
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
        return [
            'index' => Pages\ListSkuDailyDemand::route('/'),
        ];
    }
}
