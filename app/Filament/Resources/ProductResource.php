<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Models\Status;
use App\Models\Product;
use App\Models\Approval;
use App\Models\Import;
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
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Actions\DeleteBulkAction;
use App\Filament\Exports\ProductExporter;
use App\Filament\Resources\ProductResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Services\HeaderStore;
use App\Services\CategoryTypeMap;
use App\Services\TagNormalizer;
use App\Models\Tag;
use App\Models\Color;
use League\Csv\Reader;
use Illuminate\Validation\Rule;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalog';
    protected static ?int $navigationSort = 1;

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Tabs::make('ProductTabs')
                ->columnSpanFull()
                ->schema([
                Tabs\Tab::make('Details')->schema([
                    Grid::make(3)->schema([
                        Section::make()->schema([
                            TextInput::make('handle')
                                ->required()
                                ->maxLength(255)
                                ->disabled(fn (?Product $record): bool => (bool) $record)
                                ->rules(function (?Product $record): array {
                                    if ($record) {
                                        return [];
                                    }

                                    $importId = Import::where('is_current', true)->value('id');
                                    if (!$importId) {
                                        return [];
                                    }

                                    return [
                                        Rule::unique('products', 'handle')->where('import_id', $importId),
                                    ];
                                }),
                            TextInput::make('title'),
                            Textarea::make('body_html')->rows(5)->columnSpanFull(),
                            Select::make('type')
                                ->label('Type')
                                ->options(function (): array {
                                    $types = CategoryTypeMap::types();
                                    return array_combine($types, $types);
                                })
                                ->searchable()
                                ->preload()
                                ->reactive()
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    if (!$state) {
                                        $set('product_category', null);
                                        $set('google_product_category', null);
                                        return;
                                    }

                                    $mapping = CategoryTypeMap::byType($state);
                                    if ($mapping) {
                                        $set('product_category', $mapping['category']);
                                        $set('google_product_category', $mapping['google_product_category']);
                                    }
                                }),
                            Select::make('product_category')
                                ->label('Category')
                                ->options(function (): array {
                                    $categories = CategoryTypeMap::categories();
                                    return array_combine($categories, $categories);
                                })
                                ->searchable()
                                ->reactive()
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    if (!$state) {
                                        $set('type', null);
                                        $set('google_product_category', null);
                                        return;
                                    }

                                    $mapping = CategoryTypeMap::byCategory($state);
                                    if ($mapping) {
                                        $set('type', $mapping['type']);
                                        $set('google_product_category', $mapping['google_product_category']);
                                    }
                                }),
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
                            Select::make('tags')
                                ->label('Tags')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options(fn () => \App\Models\Tag::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'name')
                                    ->all()
                                )
                                ->afterStateUpdated(function (Select $component, $state, callable $set): void {
                                    $values = is_array($state) ? $state : [];
                                    $normalized = TagNormalizer::parseTokens(implode(', ', $values));
                                    if ($normalized !== $values) {
                                        $set('tags', $normalized);
                                    }
                                })
                                ->afterStateHydrated(function (Select $component, $state): void {
                                    if (! is_string($state) || trim($state) === '') {
                                        $component->state([]);
                                        return;
                                    }

                                    $component->state(TagNormalizer::parseTokens($state));
                                })
                                ->dehydrateStateUsing(function ($state): ?string {
                                    $values = is_array($state) ? $state : [];
                                    return TagNormalizer::normalizeFromArray($values);
                                }),
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
                            Hidden::make('color_conflict_message')
                                ->dehydrated(false),
                            Hidden::make('color_selection_prev')
                                ->dehydrated(false),
                            Select::make('color_string')
                            ->label('Colors')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->reactive()
                            ->options(fn () => \App\Models\Color::query()
                                ->orderBy('name')
                                ->pluck('name', 'name')
                                ->all()
                            )
                            ->afterStateUpdated(function (Select $component, $state, callable $set, callable $get): void {
                                $values = is_array($state) ? $state : [];
                                $normalized = array_values(array_unique(array_filter(array_map(
                                    fn ($v) => trim((string) $v),
                                    $values
                                ))));

                                $prev = $get('color_selection_prev') ?? [];
                                $prev = is_array($prev) ? $prev : [];
                                $prevLower = array_map('strtolower', $prev);

                                $lower = array_map('strtolower', $normalized);
                                $hasSolidPlain = in_array('solid', $lower, true) || in_array('plain', $lower, true);
                                $hasMulti = in_array('multicolour', $lower, true);

                                $message = null;
                                if ($hasSolidPlain && $hasMulti) {
                                    $added = array_diff($lower, $prevLower);
                                    if (in_array('multicolour', $added, true)) {
                                        $message = 'Multicolour can’t be selected with Solid or Plain.';
                                    } elseif (in_array('solid', $added, true) || in_array('plain', $added, true)) {
                                        $message = 'You can’t select Solid or Plain with Multicolour.';
                                    } else {
                                        $message = 'Multicolour can’t be selected with Solid or Plain.';
                                    }
                                }

                                if ($normalized !== $values) {
                                    $set('color_string', $normalized);
                                }

                                $set('color_selection_prev', $normalized);
                                $set('color_conflict_message', $message);

                                $livewire = $component->getContainer()->getLivewire();
                                if ($message) {
                                    $livewire->validateOnly($component->getStatePath());
                                } else {
                                    $livewire->resetErrorBag($component->getStatePath());
                                }
                            })
                            ->rules([
                                function (Get $get): \Closure {
                                    return function (string $attribute, $value, $fail) use ($get): void {
                                        $values = is_array($value) ? $value : [];
                                        $lower = array_map(
                                            'strtolower',
                                            array_values(array_unique(array_filter(array_map(
                                                fn ($v) => trim((string) $v),
                                                $values
                                            ))))
                                        );

                                        $hasSolidPlain = in_array('solid', $lower, true) || in_array('plain', $lower, true);
                                        $hasMulti = in_array('multicolour', $lower, true);
                                        if (!$hasSolidPlain || !$hasMulti) {
                                            return;
                                        }

                                        $message = $get('color_conflict_message')
                                            ?: 'Multicolour can’t be selected with Solid or Plain.';
                                        $fail($message);
                                    };
                                },
                            ])

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
            IconColumn::make('has_errors')
                ->label('Errors')
                ->icon(fn (bool $state): string => $state ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                ->color(fn (bool $state): string => $state ? 'danger' : 'success')
                ->toggleable(),
            TextColumn::make('error_fields')
                ->label('Error fields')
                ->formatStateUsing(function ($state): string {
                    if (is_array($state)) {
                        return empty($state) ? 'All required fields are good.' : implode(', ', $state);
                    }

                    $value = trim((string) $state);
                    return $value === '' ? 'All required fields are good.' : $value;
                })
                ->toggleable(),
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
            SelectFilter::make('type')
                ->label('Type')
                ->options(fn () => Product::query()
                    ->whereNotNull('type')
                    ->where('type', '!=', '')
                    ->distinct()
                    ->orderBy('type')
                    ->pluck('type', 'type')
                    ->all())
                ->searchable()
                ->preload(),
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
            SelectFilter::make('tags')
                ->label('Tags')
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn () => Tag::query()
                    ->orderBy('name')
                    ->pluck('name', 'name')
                    ->all())
                ->query(function (Builder $query, array $data): Builder {
                    $values = $data['values'] ?? [];
                    if (!is_array($values) || empty($values)) {
                        return $query;
                    }

                    return $query->where(function (Builder $sub) use ($values): void {
                        foreach ($values as $tag) {
                            $sub->orWhereRaw(
                                "FIND_IN_SET(?, REPLACE(tags, ', ', ','))",
                                [$tag]
                            );
                        }
                    });
                }),
            SelectFilter::make('color_string')
                ->label('Colors')
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn () => Color::query()
                    ->orderBy('name')
                    ->pluck('name', 'name')
                    ->all())
                ->query(function (Builder $query, array $data): Builder {
                    $values = $data['values'] ?? [];
                    if (!is_array($values) || empty($values)) {
                        return $query;
                    }

                    return $query->where(function (Builder $sub) use ($values): void {
                        foreach ($values as $color) {
                            $sub->orWhereRaw(
                                "FIND_IN_SET(?, REPLACE(REPLACE(color_string, '; ', ','), ';', ','))",
                                [$color]
                            );
                        }
                    });
                }),
            TernaryFilter::make('approved')
                ->label('Approved')
                ->queries(
                    true: fn ($query) => $query->whereRaw(
                        '(select count(distinct user_id) from approvals where approvals.product_id = products.id and approvals.approval_version = products.approval_version) >= 2'
                    ),
                    false: fn ($query) => $query->whereRaw(
                        '(select count(distinct user_id) from approvals where approvals.product_id = products.id and approvals.approval_version = products.approval_version) < 2'
                    )
                ),
            TernaryFilter::make('is_bundle')
                ->label('Bundles'),
            TernaryFilter::make('has_errors')
                ->label('Errors'),
        ])->actions([
            EditAction::make()
                ->visible(fn (Product $record): bool => static::canEdit($record)),
            Tables\Actions\DeleteAction::make()
                ->visible(fn (Product $record): bool => static::canDelete($record)),
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
        ])->bulkActions([
            BulkActionGroup::make([
                ExportBulkAction::make()
                    ->exporter(ProductExporter::class)
                    ->visible(fn (): bool => Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false),
            ]),
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

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(PermissionEnum::ProductView->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can(PermissionEnum::ProductCreate->value) ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->can(PermissionEnum::ProductEdit->value) ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    private static function extraShopifyFields(?Product $record): array
    {
        $headers = [];
        $row = null;

        if ($record) {
            $headers = $record->import?->headers ?? [];
            $row = ShopifyRow::where('import_id', $record->import_id)
                ->where('handle', $record->handle)
                ->where('row_type', 'product_primary')
                ->first();
        } else {
            $currentImport = Import::where('is_current', true)->first();
            $headers = $currentImport?->headers ?? [];
        }

        if (empty($headers)) {
            $headers = self::templateHeaders();
        }

        $extraHeaders = HeaderStore::extraProductHeaders($headers);
        $extraHeaders = array_values(array_filter(
            $extraHeaders,
            fn (string $header) => $header !== HeaderStore::GOOGLE_SHOPPING_AGE_GROUP
                && $header !== 'Target gender (product.metafields.shopify.target-gender)'
                && $header !== 'Cost per item'
        ));

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

    private static function templateHeaders(): array
    {
        $templatePath = storage_path('app/private/imports/products.csv');
        if (!is_file($templatePath)) {
            return [];
        }

        $csv = Reader::createFromPath($templatePath);
        $csv->setHeaderOffset(0);
        return $csv->getHeader();
    }
}
