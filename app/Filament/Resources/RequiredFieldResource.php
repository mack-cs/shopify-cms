<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RequiredFieldResource\Pages;
use App\Models\RequiredField;
use App\Services\HeaderStore;
use App\Models\Import;
use App\Services\Normalizer;
use Filament\Forms;
use Filament\Tables;
use Filament\Resources\Resource;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class RequiredFieldResource extends Resource
{
    protected static ?string $model = RequiredField::class;
    protected static ?string $navigationIcon = 'heroicon-o-check-badge';
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationLabel = 'Required Fields';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label')->disabled(),
            Forms\Components\TextInput::make('scope')->disabled(),
            Forms\Components\TextInput::make('source')->disabled(),
            Forms\Components\TextInput::make('attribute')->disabled(),
            Forms\Components\Toggle::make('required'),
            Forms\Components\Toggle::make('bulk_editable')
                ->label('Bulk editable')
                ->disabled(fn (?RequiredField $record): bool => $record ? self::isBulkEditLocked($record) : false),
            Forms\Components\Toggle::make('quick_edit')
                ->label('Quick edit')
                ->disabled(fn (?RequiredField $record): bool => $record ? self::isQuickEditLocked($record) : false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query->orderByRaw(
                    "case scope when 'product' then 1 when 'variant' then 2 when 'image' then 3 else 4 end"
                )->orderBy('label');
            })
            ->columns([
                TextColumn::make('scope')->badge(),
                TextColumn::make('label')->searchable()->wrap(),
                TextColumn::make('source')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attribute')->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('required')->label('Required'),
                ToggleColumn::make('bulk_editable')
                    ->label('Bulk editable')
                    ->disabled(fn (RequiredField $record): bool => self::isBulkEditLocked($record)),
                ToggleColumn::make('quick_edit')
                    ->label('Quick edit')
                    ->disabled(fn (RequiredField $record): bool => self::isQuickEditLocked($record)),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->options([
                        'product' => 'product',
                        'variant' => 'variant',
                        'image' => 'image',
                        'extra' => 'extra',
                    ])
                    ->label('Scope'),
            ])
            ->headerActions([
                Action::make('recalculateErrors')
                    ->label('Recalculate Errors')
                    ->requiresConfirmation()
                    ->action(function (Normalizer $normalizer): void {
                        $imports = Import::where('is_current', true)->get();
                        foreach ($imports as $import) {
                            $normalizer->recalculateErrors($import);
                        }
                    })
                    ->visible(fn (): bool => (bool) Auth::user()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRequiredFields::route('/'),
            'edit' => Pages\EditRequiredField::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function isBulkEditLocked(RequiredField $record): bool
    {
        if ($record->source === 'product' && in_array($record->attribute, [
            'handle',
            'title',
            'body_html',
            'seo_title',
            'seo_description',
        ], true)) {
            return true;
        }

        if ($record->source === 'variant' && in_array($record->attribute, [
            'sku',
            'barcode',
            'url',
            'image',
            'image_src',
        ], true)) {
            return true;
        }

        if ($record->source === 'image') {
            return true;
        }

        if ($record->source === 'row' && in_array($record->attribute, [
            HeaderStore::IMAGE_SRC,
            HeaderStore::IMAGE_ALT_TEXT,
            HeaderStore::IMAGE_POSITION,
        ], true)) {
            return true;
        }

        return false;
    }

    private static function isQuickEditLocked(RequiredField $record): bool
    {
        return $record->source === 'product' && $record->attribute === 'handle';
    }
}
