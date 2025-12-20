<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Product;
use App\Models\Approval;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use App\Filament\Resources\ProductResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\ProductResource\RelationManagers;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            TextInput::make('handle')->disabled(),
            TextInput::make('title'),
            Textarea::make('body_html'),
            TextInput::make('vendor'),
            TextInput::make('tags'),
            TextInput::make('batch')
                ->label('Batch')
                ->datalist(fn () => Product::query()
                    ->whereNotNull('batch')
                    ->distinct()
                    ->orderBy('batch')
                    ->pluck('batch')
                    ->all())
                ->placeholder('import_YYYYMMDDH')
                ->helperText('Internal only. Not exported.'),

            TextInput::make('you_save')
                ->label('You Save')
                ->numeric()
                ->inputMode('decimal')
                ->helperText('Internal only. Not exported.'),

            Select::make('product_category')
                ->label('Category')
                ->options(fn () => \App\Models\Category::where('active', true)->pluck('name', 'name'))
                ->searchable()
                ->reactive()
                ->afterStateUpdated(function ($state, callable $set) {
                    $cat = \App\Models\Category::where('name', $state)->first();
                    if ($cat) {
                        $set('google_product_category', $cat->google_product_category);
                    }
                }),

            TextInput::make('google_product_category')
                ->label('Google Product Category'),

            Select::make('color_string')
                ->label('Colors')
                ->multiple()
                ->options(fn () => \App\Models\Color::where('active', true)->pluck('name', 'name'))
                ->saveRelationshipsUsing(null)
                ->dehydrateStateUsing(fn ($state) => is_array($state) ? implode(';', $state) : $state)
                ->afterStateHydrated(function ($component, $state) {
                    if (is_string($state)) {
                        $component->state(array_filter(explode(';', $state)));
                    }
                }),

            TextInput::make('seo_title'),
            Textarea::make('seo_description'),
            Toggle::make('is_bundle')
                ->label('Bundle')
                ->helperText('Internal only. Not exported.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('thumbnail')
                ->label('')
                ->state(fn (Product $record) => $record->images()->orderBy('position')->value('src'))
                ->square()
                ->size(40),
            TextColumn::make('handle')->searchable(),
            TextColumn::make('title')->searchable(),
            TextColumn::make('vendor'),
            TextColumn::make('batch')
                ->label('Batch')
                ->toggleable(),
            IconColumn::make('is_bundle')
                ->label('Bundle')
                ->boolean()
                ->trueColor('warning')
                ->falseColor('gray'),
            TextColumn::make('you_save')
                ->label('You Save'),
            TextColumn::make('approvals_current')
                ->label('Approvals')
                ->state(fn (Product $record) => $record->approvalsForCurrentVersionCount())
                ->formatStateUsing(fn (int $state) => "{$state}/2")
                ->badge()
                ->color(fn (int $state) => $state >= 2 ? 'success' : ($state === 1 ? 'warning' : 'gray')),
            IconColumn::make('approved')
                ->label('Approved')
                ->state(fn (Product $record) => $record->isApprovedByTwo())
                ->boolean()
                ->trueColor('success')
                ->falseColor('gray'),
        ])->filters([
            TernaryFilter::make('is_bundle')
                ->label('Bundles'),
        ])->actions([
            EditAction::make(),
            Action::make('approve')
            ->label('Approve')
            ->action(function (Product $record) {
                Approval::firstOrCreate([
                    'product_id' => $record->id,
                    'user_id' => Auth::id(),
                    'approval_version' => $record->approval_version,
                ]);

                $count = $record->approvals()
                    ->where('approval_version', $record->approval_version)
                    ->distinct('user_id')
                    ->count('user_id');

                Notification::make()
                    ->title('Approved')
                    ->body("Approvals for current version: {$count}/2")
                    ->success()
                    ->send();
    })
        ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
