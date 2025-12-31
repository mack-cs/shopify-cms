<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\SearchTrendResource\Pages;
use App\Models\SearchTrend;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class SearchTrendResource extends Resource
{
    protected static ?string $model = SearchTrend::class;
    protected static ?string $navigationGroup = 'SEO';
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Search Trends';
    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('period_label')
                    ->label('Period')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('type')
                    ->options([
                        'query' => 'Query',
                        'page' => 'Page',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('label')
                    ->label('Query/Page')
                    ->required()
                    ->maxLength(1024),
                Forms\Components\TextInput::make('clicks')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Forms\Components\TextInput::make('impressions')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Forms\Components\TextInput::make('ctr')
                    ->label('CTR %')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Forms\Components\TextInput::make('position')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('clicks', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period_label')
                    ->label('Period')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('label')
                    ->label('Query/Page')
                    ->searchable()
                    ->wrap()
                    ->limit(60),
                Tables\Columns\TextColumn::make('clicks')
                    ->sortable(),
                Tables\Columns\TextColumn::make('impressions')
                    ->sortable(),
                Tables\Columns\TextColumn::make('ctr')
                    ->label('CTR %')
                    ->sortable(),
                Tables\Columns\TextColumn::make('position')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period_label')
                    ->label('Period')
                    ->options(fn () => SearchTrend::query()
                        ->select('period_label')
                        ->distinct()
                        ->orderBy('period_label')
                        ->pluck('period_label', 'period_label')
                        ->all())
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'query' => 'Query',
                        'page' => 'Page',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSearchTrends::route('/'),
            'create' => Pages\CreateSearchTrend::route('/create'),
            'edit' => Pages\EditSearchTrend::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasAnyRole([
            RolesEnum::SuperAdmin->value,
            RolesEnum::Admin->value,
            RolesEnum::SeoReviewer->value,
        ]) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }
}
