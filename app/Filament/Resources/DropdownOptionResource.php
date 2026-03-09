<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\DropdownOptionResource\Pages;
use App\Models\DropdownOption;
use App\Services\HeaderStore;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class DropdownOptionResource extends Resource
{
    protected static ?string $model = DropdownOption::class;
    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';
    protected static ?string $navigationGroup = 'Configurations';
    protected static ?string $navigationLabel = 'Dropdown Options';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('header')
                ->required()
                ->datalist(fn (): array => HeaderStore::knownHeaders()),
            Forms\Components\TextInput::make('value')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('vendor')
                ->label('Vendor')
                ->maxLength(255),
            Forms\Components\TextInput::make('product_type')
                ->label('Product type')
                ->maxLength(255),
            Forms\Components\TextInput::make('collection_style')
                ->label('Collection')
                ->maxLength(255),
            Forms\Components\TextInput::make('collection_tag_primary')
                ->label('Collection tag 1')
                ->maxLength(255),
            Forms\Components\TextInput::make('collection_tag_secondary')
                ->label('Collection tag 2')
                ->maxLength(255),
            Forms\Components\Toggle::make('active')
                ->default(true),
            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderBy('header')->orderBy('sort_order'))
            ->columns([
                TextColumn::make('header')->searchable()->wrap(),
                TextColumn::make('value')->searchable()->wrap(),
                TextColumn::make('vendor')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_type')->label('Product type')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('collection_style')->label('Collection')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('collection_tag_primary')->label('Collection tag 1')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('collection_tag_secondary')->label('Collection tag 2')->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('active'),
                TextColumn::make('sort_order')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('header')
                    ->label('Header')
                    ->options(fn () => DropdownOption::query()
                        ->select('header')
                        ->distinct()
                        ->orderBy('header')
                        ->pluck('header', 'header')
                        ->all()),
                SelectFilter::make('vendor')
                    ->label('Vendor')
                    ->options(fn () => DropdownOption::query()
                        ->whereNotNull('vendor')
                        ->where('vendor', '!=', '')
                        ->distinct()
                        ->orderBy('vendor')
                        ->pluck('vendor', 'vendor')
                        ->all()),
                SelectFilter::make('product_type')
                    ->label('Product type')
                    ->options(fn () => DropdownOption::query()
                        ->whereNotNull('product_type')
                        ->where('product_type', '!=', '')
                        ->distinct()
                        ->orderBy('product_type')
                        ->pluck('product_type', 'product_type')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDropdownOptions::route('/'),
            'create' => Pages\CreateDropdownOption::route('/create'),
            'edit' => Pages\EditDropdownOption::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }
}
