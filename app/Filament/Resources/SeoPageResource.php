<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\SeoPageResource\Pages;
use App\Models\SeoPage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class SeoPageResource extends Resource
{
    protected static ?string $model = SeoPage::class;
    protected static ?string $navigationGroup = 'SEO';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Site Pages SEO';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('url')
                    ->required()
                    ->maxLength(2048),
                Forms\Components\TextInput::make('keywords')
                    ->label('Keywords')
                    ->maxLength(255),
                Forms\Components\TextInput::make('seo_title')
                    ->label('SEO Title')
                    ->maxLength(255),
                Forms\Components\TextInput::make('meta_title')
                    ->label('Meta Title')
                    ->maxLength(255),
                Forms\Components\Textarea::make('meta_description')
                    ->label('Meta Description')
                    ->rows(4)
                    ->maxLength(1000),
                Forms\Components\Textarea::make('notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->limit(40)
                    ->wrap(),
                Tables\Columns\TextColumn::make('seo_title')
                    ->label('SEO Title')
                    ->limit(40)
                    ->wrap(),
                Tables\Columns\TextColumn::make('meta_title')
                    ->label('Meta Title')
                    ->limit(40)
                    ->wrap(),
                Tables\Columns\TextColumn::make('meta_description')
                    ->label('Meta Description')
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\TextColumn::make('keywords')
                    ->label('Keywords')
                    ->limit(40)
                    ->wrap(),
                Tables\Columns\TextColumn::make('seo_title_len')
                    ->label('SEO Len')
                    ->state(fn (SeoPage $record): int => strlen((string) $record->seo_title)),
                Tables\Columns\TextColumn::make('meta_title_len')
                    ->label('Meta Len')
                    ->state(fn (SeoPage $record): int => strlen((string) $record->meta_title)),
                Tables\Columns\TextColumn::make('meta_desc_len')
                    ->label('Desc Len')
                    ->state(fn (SeoPage $record): int => strlen((string) $record->meta_description)),
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
            'index' => Pages\ListSeoPages::route('/'),
            'create' => Pages\CreateSeoPage::route('/create'),
            'edit' => Pages\EditSeoPage::route('/{record}/edit'),
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
