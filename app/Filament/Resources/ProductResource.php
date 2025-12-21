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
use Filament\Forms\Components\Hidden;
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
use Filament\Forms\Components\Section;
use Filament\Resources\RelationManagers\RelationManager;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalog';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([

            Section::make()->schema([
                TextInput::make('handle')->disabled(),
                TextInput::make('title'),
                Textarea::make('body_html')->rows(5)->columnSpanFull(),
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
                TextInput::make('tags'),
                TextInput::make('seo_title')->columnSpanFull(),
                Textarea::make('seo_description')->columnSpanFull(),

            ])->columnSpan(2)->columns(2),

            Section::make()->schema([
                TextInput::make('vendor'),

                TextInput::make('you_save')
                ->label('You Save')
                ->numeric()
                ->inputMode('decimal')
                ->helperText('Internal only. Not exported.'),
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
                       Select::make('color_string')
                ->label('Colors')
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn () => \App\Models\Color::query()
                    ->orderBy('name')
                    ->pluck('name', 'name')
                    ->all()
                )

            // ✅ DB -> UI state (ALWAYS return array for multiple select)
            ->afterStateHydrated(function (Select $component, $state): void {
                if (! is_string($state) || trim($state) === '') {
                    $component->state([]);   // IMPORTANT
                    return;
                }

                $normalized = str_replace(',', ';', $state);

                $component->state(
                    array_values(array_filter(array_map('trim', explode(';', $normalized))))
                );
            })

            // ✅ UI -> DB string
            ->dehydrateStateUsing(function ($state) {
                $arr = is_array($state) ? $state : [];

                $clean = array_values(array_unique(array_filter(array_map(
                    fn ($v) => trim((string) $v),
                    $arr
                ))));

                return $clean ? implode('; ', $clean) : null;
            })

            // Optional: allow creating new colors
            ->createOptionForm([
                TextInput::make('name')->required()->maxLength(255),
                Toggle::make('active')->default(true),
            ])
            ->createOptionUsing(function (array $data) {
                $name = trim($data['name'] ?? '');
                if ($name === '') {
                    return null;
                }

                $color = \App\Models\Color::firstOrCreate(
                    ['name' => $name],
                    ['active' => (bool) ($data['active'] ?? true)]
                );

                return $color->name; // must match the option "value"
            }),


            Toggle::make('is_bundle')
                ->label('Bundle')
                ->helperText('Internal only. Not exported.'),
            ])->columnSpan(1),

            Hidden::make('google_product_category'),


        ])->columns(3);
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
            RelationManagers\ImagesRelationManager::class,
            RelationManagers\VariantsRelationManager::class,
            RelationManagers\ChangeLogsRelationManager::class,
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
