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
use App\Models\RequiredField;
use App\Models\Setting;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Checkbox;
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
use Filament\Tables\Actions\BulkAction;
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
use Illuminate\Support\Collection;
use App\Filament\Exports\ProductExporter;
use App\Filament\Resources\ProductResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Services\HeaderStore;
use App\Services\CategoryTypeMap;
use App\Services\TagNormalizer;
use App\Services\Normalizer;
use App\Models\Tag;
use App\Models\Color;
use League\Csv\Reader;
use Illuminate\Validation\Rule;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Arr;

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
                            Grid::make(3)->schema([
                                Select::make('target_gender')
                                    ->label('Target gender')
                                    ->options([
                                        'male' => 'Male',
                                        'female' => 'Female',
                                        'unisex' => 'Unisex',
                                    ])
                                    ->default('unisex')
                                    ->placeholder('Select target gender')
                                    ->searchable()
                                    ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                        if (!$record) {
                                            return;
                                        }
                                        $component->state(self::shopifyRowValue($record, HeaderStore::TARGET_GENDER));
                                    }),
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
                                TextInput::make('google_product_category')
                                    ->label('Google Product Category')
                                    ->disabled(),
                            ])->columnSpanFull(),
                            Grid::make(2)->schema([
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
                            ])->columnSpanFull(),
                            Grid::make(2)->schema([
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
                                                $message = "Multicolour can't be selected with Solid or Plain.";
                                            } elseif (in_array('solid', $added, true) || in_array('plain', $added, true)) {
                                                $message = "You can't select Solid or Plain with Multicolour.";
                                            } else {
                                                $message = "Multicolour can't be selected with Solid or Plain.";
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
                                                    ?: "Multicolour can't be selected with Solid or Plain.";
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
                                Textarea::make('materials_and_dimensions')
                                    ->label('Materials and dimensions')
                                    ->rows(3)
                                    ->afterStateHydrated(function (Textarea $component, ?Product $record): void {
                                        if (!$record) {
                                            return;
                                        }
                                        $component->state(self::shopifyRowValue($record, HeaderStore::MATERIALS_AND_DIMENSIONS));
                                    }),
                            ])->columnSpanFull(),
                            TextInput::make('seo_title')
                                ->columnSpanFull()
                                ->disabled(fn (?Product $record): bool => Setting::getBool(
                                    'style_profiles.lock_product_seo',
                                    config('style_profiles.lock_product_seo', true)
                                ) && ($record?->styleProfiles()->exists() ?? false))
                                ->helperText(function (?Product $record): ?string {
                                    $locked = Setting::getBool(
                                        'style_profiles.lock_product_seo',
                                        config('style_profiles.lock_product_seo', true)
                                    ) && ($record?->styleProfiles()->exists() ?? false);
                                    return $locked ? 'Edit SEO in Styles when a style is linked.' : null;
                                }),
                            Textarea::make('seo_description')
                                ->columnSpanFull()
                                ->disabled(fn (?Product $record): bool => Setting::getBool(
                                    'style_profiles.lock_product_seo',
                                    config('style_profiles.lock_product_seo', true)
                                ) && ($record?->styleProfiles()->exists() ?? false))
                                ->helperText(function (?Product $record): ?string {
                                    $locked = Setting::getBool(
                                        'style_profiles.lock_product_seo',
                                        config('style_profiles.lock_product_seo', true)
                                    ) && ($record?->styleProfiles()->exists() ?? false);
                                    return $locked ? 'Edit SEO in Styles when a style is linked.' : null;
                                }),

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
                            ->searchable()
                            ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                if (!$record) {
                                    return;
                                }
                                $raw = trim(self::shopifyRowValue($record, HeaderStore::GOOGLE_SHOPPING_AGE_GROUP));
                                $component->state($raw !== '' ? $raw : null);
                            })
                            ->dehydrateStateUsing(function ($state): ?string {
                                $value = is_string($state) ? trim($state) : '';
                                return $value === '' ? null : $value;
                            }),
                        Hidden::make('color_conflict_message')
                            ->dehydrated(false),
                        Hidden::make('color_selection_prev')
                            ->dehydrated(false),
                        TextInput::make('jewelry_material')
                            ->label('Jewelry material')
                            ->afterStateHydrated(function (TextInput $component, ?Product $record): void {
                                if (!$record) {
                                    return;
                                }
                                $component->state(self::shopifyRowValue($record, HeaderStore::JEWELRY_MATERIAL));
                            }),
                        Grid::make(2)->schema([
                            TextInput::make('jewelry_type')
                                ->label('Jewelry type')
                                ->afterStateHydrated(function (TextInput $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue($record, HeaderStore::JEWELRY_TYPE));
                                }),
                            TextInput::make('bracelet_design')
                                ->label('Bracelet design')
                                ->afterStateHydrated(function (TextInput $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue($record, HeaderStore::BRACELET_DESIGN));
                                }),
                            TextInput::make('age_group')
                                ->label('Age group')
                                ->default('adults')
                                ->afterStateHydrated(function (TextInput $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue($record, HeaderStore::AGE_GROUP));
                                }),
                            Select::make('variant_weight_unit')
                                ->label('Variant weight unit')
                                ->options([
                                    'g' => 'g',
                                    'kg' => 'kg',
                                    'mg' => 'mg',
                                ])
                                ->default('g')
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $value = $record->variants()->orderBy('id')->value('weight_unit');
                                    $component->state($value ?: 'g');
                                }),
                        ]),
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
                ->size(40)
                ->toggleable(),
            TextColumn::make('handle')->searchable()->toggleable(),
            TextColumn::make('title')->searchable()->toggleable(),
            TextColumn::make('seo_title')
                ->label('SEO title')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('seo_description')
                ->label('SEO description')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('color_string')
                ->label('Colors')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('jewelry_material')
                ->label('Jewelry material')
                ->state(fn (Product $record): string => self::shopifyRowValue($record, HeaderStore::JEWELRY_MATERIAL))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('google_shopping_age_group')
                ->label('Age group')
                ->state(fn (Product $record): string => self::shopifyRowValue($record, HeaderStore::GOOGLE_SHOPPING_AGE_GROUP))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('cost_per_item')
                ->label('Cost per item')
                ->state(fn (Product $record): string => self::shopifyRowValue($record, HeaderStore::COST_PER_ITEM))
                ->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('has_errors')
                ->label('Errors')
                ->icon(fn (bool $state): string => $state ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                ->color(fn (bool $state): string => $state ? 'danger' : 'success')
                ->toggleable(),
            TextColumn::make('error_fields')
                ->label('Error fields')
                ->color(fn (Product $record): string => $record->has_errors ? 'danger' : 'gray')
                ->formatStateUsing(function ($state): string {
                    if (is_array($state)) {
                        return empty($state) ? 'All required fields are good.' : implode(', ', $state);
                    }

                    $value = trim((string) $state);
                    return $value === '' ? 'All required fields are good.' : $value;
                })
                ->toggleable(),
            TextColumn::make('type')->label('Type')->toggleable(),
            TextColumn::make('vendor')->toggleable(),
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
                ->falseColor('gray')
                ->toggleable(),
            TextColumn::make('you_save')
                ->label('You Save')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('approvals_current')
                ->label('Approvals')
                ->state(fn (Product $record) => $record->approvalsForCurrentVersionCount())
                ->formatStateUsing(fn (int $state) => "{$state}/2")
                ->badge()
                ->color(fn (int $state) => $state >= 2 ? 'success' : ($state === 1 ? 'warning' : 'gray'))
                ->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('approved')
                ->label('Approved')
                ->state(fn (Product $record) => $record->isApprovedByTwo())
                ->boolean()
                ->trueColor('success')
                ->falseColor('gray')
                ->toggleable(isToggledHiddenByDefault: true),
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
            Action::make('quickEdit')
                ->label('Quick Edit')
                ->icon('heroicon-o-adjustments-horizontal')
                ->modalHeading(function (Product $record): HtmlString {
                    $name = trim((string) ($record->title ?? ''));
                    if ($name === '') {
                        $name = trim((string) ($record->handle ?? ''));
                    }
                    if ($name === '') {
                        return new HtmlString('Quick Edit');
                    }

                    $safeName = e($name);
                    return new HtmlString("Quick Edit | <em>{$safeName}</em>");
                })
                ->form(function (Action $action, Form $form, Product $record): array {
                    return self::form($form->model($record))->getComponents();
                })
                ->mountUsing(function (Action $action, Form $form, Product $record): void {
                    $record->refresh();
                    $form->fill($record->attributesToArray());
                })
                ->action(function (Product $record, array $data): void {
                    self::applyEditModal($record, $data);
                })
                ->visible(fn (Product $record): bool => static::canEdit($record)),
            Tables\Actions\DeleteAction::make()
                ->visible(fn (Product $record): bool => static::canDelete($record)),
        ])->bulkActions([
            BulkActionGroup::make([
                BulkAction::make('bulkEdit')
                    ->label('Bulk Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->form(self::bulkEditFormSchema())
                    ->action(function (Collection $records, array $data): void {
                        self::applyBulkEdits($records, $data);
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulkApprove')
                    ->label('Bulk Approve')
                    ->icon('heroicon-o-check-badge')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $errorCount = $records->filter(fn (Product $record) => $record->has_errors)->count();
                        $approvedCount = 0;
                        $skippedCount = 0;

                        foreach ($records as $record) {
                            if ($record->has_errors) {
                                continue;
                            }

                            $exists = Approval::where('product_id', $record->id)
                                ->where('user_id', Auth::id())
                                ->where('approval_version', $record->approval_version)
                                ->exists();

                            if ($exists) {
                                $skippedCount++;
                                continue;
                            }

                            Approval::create([
                                'product_id' => $record->id,
                                'user_id' => Auth::id(),
                                'approval_version' => $record->approval_version,
                            ]);
                            $approvedCount++;
                        }

                        $parts = [];
                        if ($approvedCount > 0) {
                            $parts[] = "Approved {$approvedCount}.";
                        }
                        if ($skippedCount > 0) {
                            $parts[] = "Skipped {$skippedCount} already approved by you.";
                        }
                        if ($errorCount > 0) {
                            $parts[] = "Errors on {$errorCount}; fix before approval.";
                        }

                        Notification::make()
                            ->title('Bulk approval complete')
                            ->body($parts ? implode(' ', $parts) : 'No products were approved.')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
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
                  && $header !== HeaderStore::TARGET_GENDER
                  && $header !== 'Cost per item'
                  && $header !== HeaderStore::JEWELRY_MATERIAL
                  && $header !== HeaderStore::MATERIALS_AND_DIMENSIONS
                  && $header !== HeaderStore::JEWELRY_TYPE
                  && $header !== HeaderStore::AGE_GROUP
                  && $header !== HeaderStore::BRACELET_DESIGN
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
        $templatePath = self::latestTemplatePath();
        if ($templatePath === null || !is_file($templatePath)) {
            return [];
        }

        $csv = Reader::createFromPath($templatePath);
        $csv->setHeaderOffset(0);
        return $csv->getHeader();
    }

    private static function latestTemplatePath(): ?string
    {
        $templateDir = storage_path('app/public/template');
        $paths = glob($templateDir . '/*.csv') ?: [];

        if (empty($paths)) {
            $legacyPath = storage_path('app/private/imports/products.csv');
            return is_file($legacyPath) ? $legacyPath : null;
        }

        usort($paths, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        return $paths[0] ?? null;
    }

    private static function bulkEditFormSchema(): array
    {
        $fields = self::bulkEditableFields();
        if (empty($fields)) {
            return [
                Placeholder::make('no_bulk_fields')
                    ->content('No bulk-editable fields are configured.'),
            ];
        }

        $schema = [
            Placeholder::make('bulk_edit_hint')
                ->label('')
                ->content('Tick a field to enable it, then enter the value to apply to all selected products.'),
        ];

        $schema[] = Grid::make(1)
            ->schema(array_map(function (array $field) {
                $safeKey = $field['safe_key'];
                $label = $field['label'];

                return Grid::make(12)
                    ->schema([
                        Checkbox::make("fields.{$safeKey}")
                            ->label($label)
                            ->live()
                            ->columnSpan(4),
                        self::bulkEditComponent($field)
                            ->label('')
                            ->statePath("values.{$safeKey}")
                            ->columnSpan(8)
                            ->disabled(fn (Get $get): bool => !($get("fields.{$safeKey}") ?? false))
                            ->dehydrated(fn (Get $get): bool => (bool) ($get("fields.{$safeKey}") ?? false)),
                    ]);
            }, $fields));

        return $schema;
    }

    private static function quickEditFormSchema(): array
    {
        $fields = self::quickEditableFields();
        if (empty($fields)) {
            return [
                Placeholder::make('no_quick_fields')
                    ->content('No quick-edit fields are configured.'),
            ];
        }

        $schema = [
            Placeholder::make('quick_edit_hint')
                ->label('Quick edit hint - (Tick the fields to edit for this row, then enter the values.)')
                ->content(''),
        ];

        $schema[] = Grid::make(1)
            ->schema(array_map(function (array $field) {
                $safeKey = $field['safe_key'];
                $label = $field['label'];

                return Grid::make(12)
                    ->schema([
                        Checkbox::make("fields.{$safeKey}")
                            ->label($label)
                            ->live()
                            ->columnSpan(4),
                        self::bulkEditComponent($field)
                            ->label('')
                            ->statePath("values.{$safeKey}")
                            ->columnSpan(8)
                            ->disabled(fn (Get $get): bool => !($get("fields.{$safeKey}") ?? false))
                            ->dehydrated(fn (Get $get): bool => (bool) ($get("fields.{$safeKey}") ?? false)),
                    ]);
            }, $fields));

        return $schema;
    }

    private static function bulkEditableFields(): array
    {
        return self::editableFieldsByFlag('bulk_editable');
    }

    private static function quickEditableFields(): array
    {
        return self::editableFieldsByFlag('quick_edit');
    }

    private static function editableFieldsByFlag(string $flagColumn): array
    {
        $labelOverrides = [
            'product|color_string' => 'Colors',
            'product|product_category' => 'Category',
            'product|google_product_category' => 'Google product category',
            'product|seo_title' => 'SEO title',
            'product|seo_description' => 'SEO description',
            'row|' . HeaderStore::JEWELRY_MATERIAL => 'Jewelry material',
            'row|Bracelet design (product.metafields.shopify.bracelet-design)' => 'Bracelet design',
        ];

        $lockedProductFields = $flagColumn === 'quick_edit'
            ? ['handle']
            : [
                'handle',
                'title',
                'body_html',
                'seo_title',
                'seo_description',
            ];

        $lockedRowFields = $flagColumn === 'quick_edit'
            ? []
            : [
                HeaderStore::IMAGE_SRC,
                HeaderStore::IMAGE_ALT_TEXT,
                HeaderStore::IMAGE_POSITION,
            ];

        $query = RequiredField::query()
            ->where($flagColumn, true)
            ->whereIn('source', $flagColumn === 'quick_edit'
                ? ['product', 'row', 'variant', 'image']
                : ['product', 'row']
            )
            ->orderBy('label');

        $fields = [];
        foreach ($query->get() as $field) {
            if ($field->source === 'product' && in_array($field->attribute, $lockedProductFields, true)) {
                continue;
            }
            if ($field->source === 'row' && in_array($field->attribute, $lockedRowFields, true)) {
                continue;
            }

            $labelKey = "{$field->source}|{$field->attribute}";
            $label = $labelOverrides[$labelKey] ?? $field->label;

            $key = "{$field->source}__{$field->attribute}";
            $fields[] = [
                'key' => $key,
                'safe_key' => 'f_' . md5($key),
                'source' => $field->source,
                'attribute' => $field->attribute,
                'label' => $label,
            ];
        }

        return $fields;
    }

    private static function bulkEditComponent(array $field): Forms\Components\Component
    {
        $name = $field['safe_key'];

        if ($field['source'] === 'product' && $field['attribute'] === 'tags') {
            return Select::make($name)
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn () => Tag::query()->orderBy('name')->pluck('name', 'name')->all());
        }

        if ($field['source'] === 'product' && $field['attribute'] === 'color_string') {
            return Select::make($name)
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn () => Color::query()->orderBy('name')->pluck('name', 'name')->all());
        }

        if ($field['source'] === 'product' && $field['attribute'] === 'type') {
            $types = CategoryTypeMap::types();
            return Select::make($name)
                ->options(array_combine($types, $types))
                ->searchable();
        }

        if ($field['source'] === 'product' && $field['attribute'] === 'product_category') {
            $categories = CategoryTypeMap::categories();
            return Select::make($name)
                ->options(array_combine($categories, $categories))
                ->searchable();
        }

        if ($field['source'] === 'product' && $field['attribute'] === 'status') {
            return Select::make($name)
                ->options(fn () => Status::query()->orderBy('name')->pluck('name', 'name')->all())
                ->searchable();
        }

        if ($field['source'] === 'product' && $field['attribute'] === 'published') {
            return Toggle::make($name);
        }

        if ($field['source'] === 'product' && $field['attribute'] === 'body_html') {
            return Textarea::make($name)->rows(4);
        }

        return TextInput::make($name);
    }

    private static function quickEditDefaults(Product $record): array
    {
        $rows = ShopifyRow::where('import_id', $record->import_id)
            ->where('handle', $record->handle)
            ->orderBy('row_index')
            ->get();

        $primaryRow = $rows->firstWhere('row_type', 'product_primary') ?? $rows->first();
        $variantRow = $rows->firstWhere('row_type', 'variant');
        $imageRow = $rows->firstWhere('row_type', 'image');

        $values = [];
        foreach (self::quickEditableFields() as $field) {
            $values[$field['safe_key']] = self::quickEditDefaultValue(
                $record,
                $field,
                $primaryRow,
                $variantRow,
                $imageRow
            );
        }

        return [
            'fields' => [],
            'values' => $values,
        ];
    }

    private static function editModalDefaults(Product $record): array
    {
        $data = $record->toArray();
        $data['extra_shopify_fields'] = self::extraShopifyFields($record);

        $ageGroupRaw = trim(self::shopifyRowValue($record, HeaderStore::GOOGLE_SHOPPING_AGE_GROUP));
        $data['google_shopping_age_group'] = $ageGroupRaw !== '' ? $ageGroupRaw : null;
        $data['target_gender'] = self::shopifyRowValue($record, HeaderStore::TARGET_GENDER);
        $data['cost_per_item'] = self::shopifyRowValue($record, HeaderStore::COST_PER_ITEM);

        return $data;
    }

    private static function quickEditDefaultValue(
        Product $record,
        array $field,
        ?ShopifyRow $primaryRow,
        ?ShopifyRow $variantRow,
        ?ShopifyRow $imageRow
    )
    {
        if ($field['source'] === 'product') {
            $attribute = $field['attribute'];
            $attributeHeaders = [
                'handle' => HeaderStore::HANDLE,
                'title' => HeaderStore::TITLE,
                'body_html' => HeaderStore::BODY_HTML,
                'vendor' => HeaderStore::VENDOR,
                'tags' => HeaderStore::TAGS,
                'type' => HeaderStore::TYPE,
                'published' => HeaderStore::PUBLISHED,
                'product_category' => HeaderStore::PRODUCT_CATEGORY,
                'google_product_category' => HeaderStore::GOOGLE_PRODUCT_CATEGORY,
                'status' => HeaderStore::STATUS,
                'seo_title' => HeaderStore::SEO_TITLE,
                'seo_description' => HeaderStore::SEO_DESCRIPTION,
                'color_string' => HeaderStore::COLOR_METAFIELD,
            ];

            $rowHeader = $attributeHeaders[$attribute] ?? null;
            if ($attribute === 'tags') {
                $raw = (string) ($record->tags ?? '');
                if (trim($raw) === '' && $rowHeader && $primaryRow) {
                    $raw = (string) $primaryRow->get($rowHeader, '');
                }
                return TagNormalizer::parseTokens($raw);
            }
            if ($attribute === 'color_string') {
                $raw = (string) ($record->color_string ?? '');
                if (trim($raw) === '' && $rowHeader && $primaryRow) {
                    $raw = (string) $primaryRow->get($rowHeader, '');
                }
                $normalized = str_replace(',', ';', $raw);
                return array_values(array_filter(array_map('trim', explode(';', $normalized))));
            }
            if ($attribute === 'published') {
                $raw = $record->published;
                if ($raw === null && $rowHeader && $primaryRow) {
                    $raw = $primaryRow->get($rowHeader, null);
                }
                if (is_bool($raw)) {
                    return $raw;
                }
                if (is_numeric($raw)) {
                    return (bool) $raw;
                }
                return strtolower((string) $raw) === 'true';
            }

            $value = $record->{$attribute} ?? null;
            if (is_string($value) && trim($value) === '') {
                $value = null;
            }

            if ($value === null && $rowHeader && $primaryRow) {
                $value = $primaryRow->get($rowHeader, '');
            }

            return $value;
        }

        if ($field['source'] === 'row') {
            return $primaryRow?->get($field['attribute'], '') ?? '';
        }

        if ($field['source'] === 'variant') {
            return $variantRow?->get($field['attribute'], '') ?? '';
        }

        if ($field['source'] === 'image') {
            return $imageRow?->get($field['attribute'], '') ?? '';
        }

        return null;
    }

    private static function applyBulkEdits(Collection $records, array $data): void
    {
        $selected = array_keys(array_filter($data['fields'] ?? []));
        $values = $data['values'] ?? [];
        if (!is_array($selected) || empty($selected)) {
            return;
        }

        $fieldMap = [];
        foreach (self::bulkEditableFields() as $field) {
            $fieldMap[$field['safe_key']] = $field;
        }

        foreach ($records as $product) {
            $productUpdates = [];
            $rowUpdates = [];

            foreach ($selected as $safeKey) {
                $field = $fieldMap[$safeKey] ?? null;
                if (!$field) {
                    continue;
                }

                $value = $values[$safeKey] ?? null;
                if ($field['source'] === 'product') {
                    $attribute = $field['attribute'];
                    if ($attribute === 'tags') {
                        $arr = is_array($value) ? $value : [];
                        $productUpdates['tags'] = TagNormalizer::normalizeFromArray($arr);
                        continue;
                    }
                    if ($attribute === 'color_string') {
                        $arr = is_array($value) ? $value : [];
                        $clean = array_values(array_unique(array_filter(array_map(
                            fn ($v) => trim((string) $v),
                            $arr
                        ))));
                        $productUpdates['color_string'] = $clean ? implode('; ', $clean) : null;
                        continue;
                    }
                    if ($attribute === 'published') {
                        $productUpdates['published'] = $value ? 'true' : 'false';
                        continue;
                    }

                    $productUpdates[$attribute] = $value;
                    continue;
                }

                if ($field['source'] === 'row') {
                    $rowUpdates[$field['attribute']] = $value;
                }
            }

            if (!empty($productUpdates)) {
                $product->fill($productUpdates);
                $product->save();
            }

            if (!empty($rowUpdates)) {
                $row = ShopifyRow::where('import_id', $product->import_id)
                    ->where('handle', $product->handle)
                    ->where('row_type', 'product_primary')
                    ->first();
                if ($row) {
                    $dataRow = $row->data ?? [];
                    foreach ($rowUpdates as $header => $value) {
                        if (is_array($value)) {
                            $value = implode('; ', array_values(array_filter(array_map('trim', $value))));
                        }
                        $dataRow[$header] = $value ?? '';
                    }
                    $row->data = $dataRow;
                    $row->save();
                }
            }

            app(Normalizer::class)->recalculateErrorsForProduct($product);
        }
    }

    private static function applyQuickEdits(Product $product, array $data): void
    {
        $selected = array_keys(array_filter($data['fields'] ?? []));
        $values = $data['values'] ?? [];
        if (!is_array($selected) || empty($selected)) {
            return;
        }

        $fieldMap = [];
        foreach (self::quickEditableFields() as $field) {
            $fieldMap[$field['safe_key']] = $field;
        }

        $productUpdates = [];
        $rowUpdates = [];

        foreach ($selected as $safeKey) {
            $field = $fieldMap[$safeKey] ?? null;
            if (!$field) {
                continue;
            }

            $value = $values[$safeKey] ?? null;
            if ($field['source'] === 'product') {
                $attribute = $field['attribute'];
                if ($attribute === 'tags') {
                    $arr = is_array($value) ? $value : [];
                    $productUpdates['tags'] = TagNormalizer::normalizeFromArray($arr);
                    continue;
                }
                if ($attribute === 'color_string') {
                    $arr = is_array($value) ? $value : [];
                    $clean = array_values(array_unique(array_filter(array_map(
                        fn ($v) => trim((string) $v),
                        $arr
                    ))));
                    $productUpdates['color_string'] = $clean ? implode('; ', $clean) : null;
                    continue;
                }
                if ($attribute === 'published') {
                    $productUpdates['published'] = $value ? 'true' : 'false';
                    continue;
                }

                $productUpdates[$attribute] = $value;
                continue;
            }

            if ($field['source'] === 'row') {
                $rowUpdates[$field['attribute']] = $value;
            }
        }

        if (!empty($productUpdates)) {
            $product->fill($productUpdates);
            $product->save();
        }

        if (!empty($rowUpdates)) {
            $row = ShopifyRow::where('import_id', $product->import_id)
                ->where('handle', $product->handle)
                ->where('row_type', 'product_primary')
                ->first();
            if ($row) {
                $dataRow = $row->data ?? [];
                foreach ($rowUpdates as $header => $value) {
                    if (is_array($value)) {
                        $value = implode('; ', array_values(array_filter(array_map('trim', $value))));
                    }
                    $dataRow[$header] = $value ?? '';
                }
                $row->data = $dataRow;
                $row->save();
            }
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);
    }

    private static function applyEditModal(Product $product, array $data): void
    {
        $extra = $data['extra_shopify_fields'] ?? [];
        $googleShoppingAgeGroup = $data['google_shopping_age_group'] ?? '';
        $targetGender = $data['target_gender'] ?? '';
        $ageGroup = $data['age_group'] ?? '';
        $costPerItem = $data['cost_per_item'] ?? '';
        $materialsAndDimensions = $data['materials_and_dimensions'] ?? '';
        $jewelryMaterial = $data['jewelry_material'] ?? '';
        $jewelryType = $data['jewelry_type'] ?? '';
        $braceletDesign = $data['bracelet_design'] ?? '';
        $variantWeightUnit = $data['variant_weight_unit'] ?? null;

        $productData = Arr::except($data, [
            'extra_shopify_fields',
            'google_shopping_age_group',
            'target_gender',
            'age_group',
            'cost_per_item',
            'materials_and_dimensions',
            'jewelry_material',
            'jewelry_type',
            'bracelet_design',
            'variant_weight_unit',
        ]);

        $product->fill($productData);
        $product->save();

        $headers = $product->import?->headers ?? [];
        $allowed = array_flip(HeaderStore::extraProductHeaders($headers));

        $row = ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->first();

        if ($row) {
            $rowData = $row->data ?? [];
            if ($googleShoppingAgeGroup !== null) {
                $rowData[HeaderStore::GOOGLE_SHOPPING_AGE_GROUP] = $googleShoppingAgeGroup ?: '';
            }
            if ($targetGender !== null) {
                $rowData[HeaderStore::TARGET_GENDER] = $targetGender ?: '';
            }
            if ($ageGroup !== null) {
                $rowData[HeaderStore::AGE_GROUP] = $ageGroup ?: '';
            }
            if ($costPerItem !== null) {
                $rowData['Cost per item'] = $costPerItem ?: '';
            }
            if ($materialsAndDimensions !== null) {
                $rowData[HeaderStore::MATERIALS_AND_DIMENSIONS] = $materialsAndDimensions ?: '';
            }
            if ($jewelryMaterial !== null) {
                $rowData[HeaderStore::JEWELRY_MATERIAL] = $jewelryMaterial ?: '';
            }
            if ($jewelryType !== null) {
                $rowData[HeaderStore::JEWELRY_TYPE] = $jewelryType ?: '';
            }
            if ($braceletDesign !== null) {
                $rowData[HeaderStore::BRACELET_DESIGN] = $braceletDesign ?: '';
            }

            foreach ($extra as $item) {
                $key = $item['key'] ?? null;
                if (!$key || empty($allowed) || !isset($allowed[$key])) {
                    continue;
                }
                $rowData[$key] = $item['value'] ?? '';
            }

            $row->data = $rowData;
            $row->save();
        }

        if ($variantWeightUnit !== null) {
            $normalized = trim((string) $variantWeightUnit);
            if ($normalized === '') {
                $normalized = 'g';
            }
            $product->variants()->update([
                'weight_unit' => $normalized,
            ]);
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);
    }
}
