<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Status;
use App\Models\Product;
use App\Models\Approval;
use App\Models\Type;
use App\Models\ShopifyRow;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Get;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use App\Filament\Resources\ProductResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Services\HeaderStore;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalog';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Tabs::make('ProductTabs')
                ->columnSpanFull()
                ->schema([
                Tabs\Tab::make('Details')->schema([
                    Grid::make(3)->schema([
                        Section::make()->schema([
                            TextInput::make('handle')->disabled(),
                            TextInput::make('title'),
                            Textarea::make('body_html')->rows(5)->columnSpanFull(),
                            Select::make('type')
                                ->label('Type')
                                ->options(fn () => Type::query()->orderBy('name')->pluck('name', 'name')->all())
                                ->searchable()
                                ->preload()
                                ->reactive()
                                ->createOptionForm([
                                    TextInput::make('name')->required()->maxLength(255),
                                    TextInput::make('google_product_category')
                                        ->label('Google Product Category')
                                        ->maxLength(255),
                                ])
                                ->createOptionUsing(function (array $data) {
                                    $name = trim($data['name'] ?? '');
                                    if ($name === '') {
                                        return null;
                                    }

                                    $type = Type::firstOrCreate(
                                        ['name' => $name],
                                        ['google_product_category' => trim((string) ($data['google_product_category'] ?? '')) ?: null]
                                    );

                                    return $type->name;
                                })
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    if (!$state) {
                                        $set('google_product_category', null);
                                        return;
                                    }

                                    $gpc = Type::where('name', $state)->value('google_product_category');
                                    if ($gpc !== null && $gpc !== '') {
                                        $set('google_product_category', $gpc);
                                    }
                                }),
                            Select::make('product_category')
                                ->label('Category')
                                ->options(fn () => \App\Models\Category::where('active', true)->pluck('name', 'name'))
                                ->searchable(),
                            TextInput::make('google_product_category')
                                ->label('Google Product Category'),
                            Select::make('google_shopping_age_group')
                                ->label('Google Shopping / Age Group')
                                ->options([
                                    'adult' => 'Adult',
                                    'teen' => 'Teen',
                                    'kids' => 'Kids',
                                    'toddler' => 'Toddler',
                                    'infant' => 'Infant',
                                    'newborn' => 'Newborn',
                                ])
                                ->multiple()
                                ->searchable()
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $raw = self::shopifyRowValue($record, HeaderStore::GOOGLE_SHOPPING_AGE_GROUP);
                                    if (trim($raw) === '') {
                                        $component->state([]);
                                        return;
                                    }
                                    $component->state(
                                        array_values(array_filter(array_map('trim', explode(';', $raw))))
                                    );
                                })
                                ->dehydrateStateUsing(function ($state) {
                                    $values = is_array($state) ? $state : [];
                                    $clean = array_values(array_unique(array_filter(array_map(
                                        fn ($v) => trim((string) $v),
                                        $values
                                    ))));
                                    return $clean ? implode(';', $clean) : null;
                                }),
                            Select::make('target_gender')
                                ->label('Target gender')
                                ->options([
                                    'male' => 'Male',
                                    'female' => 'Female',
                                    'unisex' => 'Unisex',
                                ])
                                ->placeholder('Select target gender')
                                ->searchable()
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue($record, 'Target gender (product.metafields.shopify.target-gender)'));
                                }),
                            TextInput::make('tags'),
                            TextInput::make('seo_title')
                                ->columnSpanFull()
                                ->disabled(fn (?Product $record): bool => $record?->styleProfiles()->exists() ?? false)
                                ->helperText('Edit SEO in Styles when a style is linked.'),
                            Textarea::make('seo_description')
                                ->columnSpanFull()
                                ->disabled(fn (?Product $record): bool => $record?->styleProfiles()->exists() ?? false)
                                ->helperText('Edit SEO in Styles when a style is linked.'),

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
                            TextInput::make('cost_per_item')
                                ->label('Cost per item')
                                ->numeric()
                                ->inputMode('decimal')
                                ->afterStateHydrated(function (TextInput $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue($record, 'Cost per item'));
                                }),
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

                        // ?. DB -> UI state (ALWAYS return array for multiple select)
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

                        // ?. UI -> DB string
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
                        Toggle::make('published')
                            ->label('Published')
                            ->helperText('Exported as true/false.')
                            ->afterStateHydrated(function (Toggle $component, $state): void {
                                $component->state(filter_var($state, FILTER_VALIDATE_BOOLEAN));
                            })
                            ->dehydrateStateUsing(fn (bool $state): string => $state ? 'true' : 'false'),
                        Select::make('status')
                            ->label('Status')
                            ->searchable()
                            ->preload()
                            ->options(fn () => Status::query()
                                ->orderBy('name')
                                ->pluck('name', 'name')
                                ->all())
                            ->createOptionForm([
                                TextInput::make('name')->required()->maxLength(255),
                                Toggle::make('active')->default(true),
                            ])
                            ->createOptionUsing(function (array $data) {
                                $name = trim($data['name'] ?? '');
                                if ($name === '') {
                                    return null;
                                }

                            $status = Status::firstOrCreate(
                                ['name' => $name],
                                ['active' => (bool) ($data['active'] ?? true)]
                            );

                            return $status->name;
                        }),
                        Grid::make(2)->schema([
                            Placeholder::make('approvals_current')
                                ->label('Approvals')
                                ->content(fn (?Product $record): string => $record
                                    ? ($record->approvalsForCurrentVersionCount() . '/2')
                                    : '0/2'),
                            Actions::make([
                                FormAction::make('approve')
                                    ->label('Approve')
                                    ->action(function (?Product $record): void {
                                        if (!$record) {
                                            return;
                                        }

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
                                    ->visible(fn (?Product $record): bool => (bool) $record),
                            ]),
                        ]),


                        Toggle::make('is_bundle')
                            ->label('Bundle')
                            ->helperText('Internal only. Not exported.'),
                        ])->columnSpan(1),
                    ]),
                ]),
                Tabs\Tab::make('Extra Fields')->schema([
                    Section::make('Extra Shopify Fields')
                        ->schema([
                            Repeater::make('extra_shopify_fields')
                                ->label('Fields')
                                ->schema([
                                    TextInput::make('key')
                                        ->label('Field')
                                        ->disabled()
                                        ->dehydrated(),
                                    TextInput::make('value')
                                        ->label('Value'),
                                ])
                                ->columns(2)
                                ->grid(2)
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->rules(function (Get $get): array {
                                    $key = $get('key');
                                    if (!$key) {
                                        return [];
                                    }
                                    if (!in_array($key, HeaderStore::semicolonSeparatedHeaders(), true)) {
                                        return [];
                                    }
                                    return [
                                        function (string $attribute, $value, $fail) use ($key): void {
                                            if ($value !== null && str_contains((string) $value, ',')) {
                                                $fail("{$key} must use ';' separators (no commas).");
                                            }
                                        },
                                    ];
                                })
                                ->afterStateHydrated(function (Repeater $component, ?Product $record): void {
                                    $component->state(self::extraShopifyFields($record));
                                }),
                        ]),
                ]),
            ]),
        ])->columns(1);
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
            TextColumn::make('type')->label('Type')->toggleable(),
            TextColumn::make('vendor'),
            IconColumn::make('published')
                ->label('Published')
                ->boolean()
                ->state(fn (Product $record): bool => filter_var($record->published, FILTER_VALIDATE_BOOLEAN))
                ->toggleable(),
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
            SelectFilter::make('vendor')
                ->label('Vendor')
                ->options(fn () => Product::query()
                    ->whereNotNull('vendor')
                    ->where('vendor', '!=', '')
                    ->distinct()
                    ->orderBy('vendor')
                    ->pluck('vendor', 'vendor')
                    ->all())
                ->searchable()
                ->preload(),
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
            RelationManagers\StyleProfileRelationManager::class,
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

    private static function extraShopifyFields(?Product $record): array
    {
        if (!$record) {
            return [];
        }

        $headers = $record->import?->headers ?? [];
        $extraHeaders = HeaderStore::extraProductHeaders($headers);
        $extraHeaders = array_values(array_filter(
            $extraHeaders,
            fn (string $header) => $header !== HeaderStore::GOOGLE_SHOPPING_AGE_GROUP
                && $header !== 'Target gender (product.metafields.shopify.target-gender)'
                && $header !== 'Cost per item'
        ));
        if (empty($extraHeaders)) {
            return [];
        }

        $row = ShopifyRow::where('import_id', $record->import_id)
            ->where('handle', $record->handle)
            ->where('row_type', 'product_primary')
            ->first();

        return array_map(function (string $header) use ($row): array {
            return [
                'key' => $header,
                'value' => $row?->get($header, '') ?? '',
            ];
        }, $extraHeaders);
    }

    private static function shopifyRowValue(Product $record, string $header): string
    {
        $row = ShopifyRow::where('import_id', $record->import_id)
            ->where('handle', $record->handle)
            ->where('row_type', 'product_primary')
            ->first();

        return (string) ($row?->get($header, '') ?? '');
    }
}
