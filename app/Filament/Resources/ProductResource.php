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
use Filament\Forms\Components\RichEditor;
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
use App\Services\DropdownCollectionCatalog;
use App\Models\Tag;
use App\Models\Color;
use App\Models\DropdownOption;
use League\Csv\Reader;
use Illuminate\Validation\Rule;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Arr;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalog';
    protected static ?int $navigationSort = 2;

    protected static function isDraftOwnedLocked(?Product $record): bool
    {
        return (bool) $record;
    }

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
                            TextInput::make('title')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record)),
                            Textarea::make('body_html')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))->rows(5)->columnSpanFull(),
                            Grid::make(3)->schema([
                            Select::make('target_gender')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Target gender')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    HeaderStore::TARGET_GENDER,
                                    tags: $get('tags')
                                ))
                                ->default('unisex')
                                ->placeholder('Select target gender')
                                ->searchable()
                                ->reactive()
                                ->createOptionForm([
                                    TextInput::make('value')->required()->maxLength(255),
                                ])
                                ->createOptionUsing(function (array $data) {
                                    $value = trim((string) ($data['value'] ?? ''));
                                    if ($value === '') {
                                        return null;
                                    }

                                    DropdownOption::create([
                                        'header' => HeaderStore::TARGET_GENDER,
                                        'value' => $value,
                                        'active' => true,
                                    ]);

                                    return $value;
                                })
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue($record, HeaderStore::TARGET_GENDER));
                                }),
                                Select::make('type')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
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
                                            $set('product_category', $mapping['shopify_taxonomy_gid'] ?? $mapping['category']);
                                            $set('google_product_category', $mapping['google_product_category']);
                                        }
                                    }),
                                TextInput::make('google_product_category')
                                    ->label('Google Product Category')
                                    ->disabled()
                                    ->dehydrated(),
                            ])->columnSpanFull(),
                            Grid::make(2)->schema([
                                Select::make('product_category')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                    ->label('Category')
                                    ->options(fn (): array => CategoryTypeMap::categoryOptions())
                                    ->searchable()
                                    ->reactive()
                                    ->getOptionLabelUsing(fn ($value): ?string => CategoryTypeMap::categoryLabelForValue(
                                        is_string($value) ? $value : null
                                    ))
                                    ->dehydrateStateUsing(function ($state): ?string {
                                        if (!is_string($state) || trim($state) === '') {
                                            return null;
                                        }

                                        $mapping = CategoryTypeMap::byCategoryValue($state);
                                        return $mapping['shopify_taxonomy_gid'] ?? $state;
                                    })
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        if (!$state) {
                                            $set('type', null);
                                            $set('google_product_category', null);
                                            return;
                                        }

                                        $mapping = CategoryTypeMap::byCategoryValue(is_string($state) ? $state : null);
                                        if ($mapping) {
                                            $set('type', $mapping['type']);
                                            $set('google_product_category', $mapping['google_product_category']);
                                        }
                                    }),
                            Select::make('tags')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Tags')
                                ->multiple()
                                ->searchable()
                                ->reactive()
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
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Colors')
                                ->helperText(fn (Get $get, ?Product $record): ?HtmlString => self::invalidDropdownHint(
                                    $get,
                                    $record,
                                    'color_string',
                                    HeaderStore::COLOR_METAFIELD,
                                    true,
                                    true
                                ))
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->reactive()
                                ->options(function (Get $get): array {
                                    $vendor = $get('vendor');
                                    $type = $get('type');
                                    $tags = self::filterTags($get, $vendor, $type);
                                    return self::dropdownOptionsForHeader(
                                        HeaderStore::COLOR_METAFIELD,
                                        vendor: $vendor,
                                        productType: $type,
                                        tags: $tags
                                    );
                                })
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
                                    TextInput::make('value')
                                        ->required()
                                        ->maxLength(255)
                                        ->default(fn (Get $get): ?string => self::defaultInvalidDropdownValue(
                                            $get,
                                            'color_string',
                                            HeaderStore::COLOR_METAFIELD,
                                            true,
                                            true
                                        )),
                                    TextInput::make('vendor')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                        ->label('Vendor')
                                        ->helperText('Leave blank for global options.'),
                                    TextInput::make('product_type')
                                        ->label('Product type')
                                        ->helperText('Optional; use to limit to a specific type.'),
                                    Select::make('collection_style')
                                        ->label('Collection')
                                        ->options(fn (): array => self::collectionOptions())
                                        ->default(fn (Get $get): ?string => self::defaultCollectionStyleForState($get))
                                        ->searchable()
                                        ->placeholder('Use current product collection by default')
                                        ->helperText('Pick a collection only if you want to override the current one.'),
                                ])
                                ->createOptionUsing(fn (array $data): ?string => self::createControlledDropdownOption(
                                    $data,
                                    HeaderStore::COLOR_METAFIELD,
                                    true
                                )),
                                Select::make('materials_and_dimensions')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                    ->label('Materials and dimensions')
                                    ->helperText(fn (Get $get, ?Product $record): ?HtmlString => self::invalidDropdownHint(
                                        $get,
                                        $record,
                                        'materials_and_dimensions',
                                        HeaderStore::MATERIALS_AND_DIMENSIONS
                                    ))
                                    ->placeholder('Select option')
                                    ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                        HeaderStore::MATERIALS_AND_DIMENSIONS,
                                        tags: self::filterTags($get)
                                    ))
                                    ->searchable()
                                    ->reactive()
                                    ->createOptionForm(self::controlledDropdownCreateOptionForm(
                                        'materials_and_dimensions',
                                        HeaderStore::MATERIALS_AND_DIMENSIONS
                                    ))
                                    ->createOptionUsing(fn (array $data): ?string => self::createControlledDropdownOption(
                                        $data,
                                        HeaderStore::MATERIALS_AND_DIMENSIONS
                                    ))
                                    ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                        if (!$record) {
                                            return;
                                        }
                                        $component->state(self::shopifyRowValue($record, HeaderStore::MATERIALS_AND_DIMENSIONS));
                                    }),
                            ])->columnSpanFull(),
                            Grid::make(2)->schema([
                                Select::make('jewelry_material')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                    ->label('Jewelry material')
                                    ->helperText(fn (Get $get, ?Product $record): ?HtmlString => self::invalidDropdownHint(
                                        $get,
                                        $record,
                                        'jewelry_material',
                                        HeaderStore::JEWELRY_MATERIAL,
                                        true
                                    ))
                                    ->placeholder('Select option')
                                    ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                        HeaderStore::JEWELRY_MATERIAL,
                                        tags: self::filterTags($get)
                                    ))
                                    ->multiple()
                                    ->searchable()
                                    ->reactive()
                                    ->createOptionForm(self::controlledDropdownCreateOptionForm(
                                        'jewelry_material',
                                        HeaderStore::JEWELRY_MATERIAL,
                                        true
                                    ))
                                    ->createOptionUsing(fn (array $data): ?string => self::createControlledDropdownOption(
                                        $data,
                                        HeaderStore::JEWELRY_MATERIAL
                                    ))
                                    ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                        if (!$record) {
                                            return;
                                        }
                                        $raw = self::shopifyRowValue($record, HeaderStore::JEWELRY_MATERIAL);
                                        if (!is_string($raw) || trim($raw) === '') {
                                            $component->state([]);
                                            return;
                                        }

                                        $normalized = str_replace(',', ';', $raw);
                                        $component->state(
                                            array_values(array_filter(array_map('trim', explode(';', $normalized))))
                                        );
                                    })
                                    ->dehydrateStateUsing(function ($state): ?string {
                                        $arr = is_array($state) ? $state : [];
                                        $clean = array_values(array_unique(array_filter(array_map(
                                            fn ($v) => trim((string) $v),
                                            $arr
                                        ))));

                                        return $clean ? implode('; ', $clean) : null;
                                    }),
                                Select::make('jewelry_type')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                    ->label('Jewelry type')
                                    ->placeholder('Select option')
                                    ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                        HeaderStore::JEWELRY_TYPE,
                                        tags: self::filterTags($get)
                                    ))
                                    ->searchable()
                                    ->reactive()
                                    ->createOptionForm([
                                        TextInput::make('value')->required()->maxLength(255),
                                    ])
                                    ->createOptionUsing(function (array $data) {
                                        $value = trim((string) ($data['value'] ?? ''));
                                        if ($value === '') {
                                            return null;
                                        }

                                        DropdownOption::create([
                                            'header' => HeaderStore::JEWELRY_TYPE,
                                            'value' => strtolower($value),
                                            'active' => true,
                                        ]);

                                        return strtolower($value);
                                    })
                                    ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                        if (!$record) {
                                            return;
                                        }
                                        $component->state(self::shopifyRowValue($record, HeaderStore::JEWELRY_TYPE));
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
                            Grid::make(3)->schema([
                                Toggle::make('seo_deindex')
                                    ->label('SEO: Deindex products')
                                    ->helperText('Exported as true/false.')
                                    ->afterStateHydrated(function (Toggle $component, ?Product $record): void {
                                        if (!$record) {
                                            return;
                                        }
                                        $raw = self::shopifyRowValue($record, HeaderStore::SEO_DEINDEX);
                                        $component->state(filter_var($raw, FILTER_VALIDATE_BOOLEAN));
                                    })
                                    ->dehydrateStateUsing(fn (bool $state): string => $state ? 'true' : 'false'),
                                Placeholder::make('approvals_current')
                                    ->label('Approvals')
                                    ->content(fn (?Product $record): string => $record
                                        ? ($record->approvalsForCurrentVersionCount() . '/2')
                                        : '0/2'),
                                Toggle::make('is_bundle')
                                    ->label('Bundle')
                                    ->helperText('Internal only. Not exported.'),
                            ])->columnSpanFull(),

                        ])->columnSpan(2)->columns(2),
                        Section::make()->schema([
                            Select::make('vendor')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Vendor')
                                ->placeholder('Select option')
                                ->options(fn () => Product::query()
                                    ->whereNotNull('vendor')
                                    ->where('vendor', '!=', '')
                                    ->distinct()
                                    ->orderBy('vendor')
                                    ->pluck('vendor', 'vendor')
                                    ->all())
                                ->searchable()
                                ->preload(),
                            Select::make('collection_filter')
                                ->label('Collection')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->placeholder('Select option')
                                ->options(fn (): array => self::collectionOptions())
                                ->searchable()
                                ->reactive()
                                ->dehydrated(false)
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::collectionFromTags($record->tags));
                                })
                                ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                                    $collectionTags = self::collectionTags($state);
                                    if ($collectionTags === []) {
                                        return;
                                    }

                                    $current = $get('tags');
                                    $normalized = self::normalizeTagList($current);
                                    $collectionPool = self::allCollectionTags();

                                    $kept = array_values(array_filter(
                                        $normalized,
                                        fn (string $tag): bool => !in_array($tag, $collectionPool, true)
                                    ));

                                    $merged = array_values(array_unique(array_merge($kept, $collectionTags)));
                                    $set('tags', $merged);
                                }),

                        Select::make('google_shopping_age_group')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                            ->label('Google Shopping / Age Group')
                            ->placeholder('Select option')
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
                                $component->state($raw !== '' ? strtolower($raw) : null);
                            })
                            ->dehydrateStateUsing(function ($state): ?string {
                                $value = is_string($state) ? strtolower(trim($state)) : '';
                                return $value === '' ? null : $value;
                            }),
                        Hidden::make('color_conflict_message')
                            ->dehydrated(false),
                        Hidden::make('color_selection_prev')
                            ->dehydrated(false),
                        Grid::make(2)->schema([
                            Select::make('bracelet_design')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Bracelet design')
                                ->helperText(fn (Get $get, ?Product $record): ?HtmlString => self::invalidDropdownHint(
                                    $get,
                                    $record,
                                    'bracelet_design',
                                    HeaderStore::BRACELET_DESIGN
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    HeaderStore::BRACELET_DESIGN,
                                    tags: self::filterTags($get)
                                ))
                                ->searchable()
                                ->reactive()
                                ->createOptionForm(self::controlledDropdownCreateOptionForm(
                                    'bracelet_design',
                                    HeaderStore::BRACELET_DESIGN
                                ))
                                ->createOptionUsing(fn (array $data): ?string => self::createControlledDropdownOption(
                                    $data,
                                    HeaderStore::BRACELET_DESIGN
                                ))
                                ->visible(fn (Get $get): bool => in_array('bracelets', self::filterTags($get), true))
                                ->columnSpanFull()
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue($record, HeaderStore::BRACELET_DESIGN));
                                }),
                            TextInput::make('variant_price')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Variant price')
                                ->numeric()
                                ->inputMode('decimal')
                                ->afterStateHydrated(function (TextInput $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $value = $record->variants()->orderBy('id')->value('price');
                                    if ($value === null || trim((string) $value) === '') {
                                        $value = self::shopifyRowValue($record, HeaderStore::VARIANT_PRICE);
                                    }
                                    $component->state($value);
                                }),
                            TextInput::make('cost_per_item')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Cost per item')
                                ->numeric()
                                ->inputMode('decimal')
                                ->afterStateHydrated(function (TextInput $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue($record, 'Cost per item'));
                                }),
                            Select::make('variant_weight_unit')
                                ->label('Variant weight unit')
                                ->placeholder('Select option')
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
                        ])->columnSpanFull(),
                        Grid::make(1)->schema([
                            Select::make('necklace_design')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Necklace design')
                                ->helperText(fn (Get $get, ?Product $record): ?HtmlString => self::invalidDropdownHint(
                                    $get,
                                    $record,
                                    'necklace_design',
                                    'Necklace design (product.metafields.shopify.necklace-design)'
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    'Necklace design (product.metafields.shopify.necklace-design)',
                                    tags: self::filterTags($get)
                                ))
                                ->searchable()
                                ->reactive()
                                ->createOptionForm(self::controlledDropdownCreateOptionForm(
                                    'necklace_design',
                                    'Necklace design (product.metafields.shopify.necklace-design)'
                                ))
                                ->createOptionUsing(fn (array $data): ?string => self::createControlledDropdownOption(
                                    $data,
                                    'Necklace design (product.metafields.shopify.necklace-design)'
                                ))
                                ->visible(fn (Get $get): bool => in_array('necklaces', self::filterTags($get), true))
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue(
                                        $record,
                                        'Necklace design (product.metafields.shopify.necklace-design)'
                                    ));
                                }),
                            Select::make('earring_design')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Earring design')
                                ->helperText(fn (Get $get, ?Product $record): ?HtmlString => self::invalidDropdownHint(
                                    $get,
                                    $record,
                                    'earring_design',
                                    'Earring design (product.metafields.shopify.earring-design)'
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    'Earring design (product.metafields.shopify.earring-design)',
                                    tags: self::filterTags($get)
                                ))
                                ->searchable()
                                ->reactive()
                                ->createOptionForm(self::controlledDropdownCreateOptionForm(
                                    'earring_design',
                                    'Earring design (product.metafields.shopify.earring-design)'
                                ))
                                ->createOptionUsing(fn (array $data): ?string => self::createControlledDropdownOption(
                                    $data,
                                    'Earring design (product.metafields.shopify.earring-design)'
                                ))
                                ->visible(fn (Get $get): bool => in_array('earrings', self::filterTags($get), true))
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue(
                                        $record,
                                        'Earring design (product.metafields.shopify.earring-design)'
                                    ));
                                }),
                        ])->columnSpanFull(),
                        Grid::make(2)->schema([
                            Select::make('pattern_category')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Pattern category')
                                ->helperText(fn (Get $get, ?Product $record): ?HtmlString => self::invalidDropdownHint(
                                    $get,
                                    $record,
                                    'pattern_category',
                                    HeaderStore::PATTERN_CATEGORY
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    'Pattern Category (product.metafields.custom.pattern_category)',
                                    tags: self::filterTags($get)
                                ))
                                ->searchable()
                                ->reactive()
                                ->createOptionForm(self::controlledDropdownCreateOptionForm(
                                    'pattern_category',
                                    HeaderStore::PATTERN_CATEGORY
                                ))
                                ->createOptionUsing(fn (array $data): ?string => self::createControlledDropdownOption(
                                    $data,
                                    HeaderStore::PATTERN_CATEGORY
                                ))
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue(
                                        $record,
                                        'Pattern Category (product.metafields.custom.pattern_category)'
                                    ));
                                }),
                            Select::make('product_metals')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Product metals')
                                ->helperText(fn (Get $get, ?Product $record): ?HtmlString => self::invalidDropdownHint(
                                    $get,
                                    $record,
                                    'product_metals',
                                    HeaderStore::PRODUCT_METALS
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    'Product Metals (product.metafields.custom.product_metals)',
                                    tags: self::filterTags($get)
                                ))
                                ->searchable()
                                ->reactive()
                                ->createOptionForm(self::controlledDropdownCreateOptionForm(
                                    'product_metals',
                                    HeaderStore::PRODUCT_METALS
                                ))
                                ->createOptionUsing(fn (array $data): ?string => self::createControlledDropdownOption(
                                    $data,
                                    HeaderStore::PRODUCT_METALS
                                ))
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue(
                                        $record,
                                        'Product Metals (product.metafields.custom.product_metals)'
                                    ));
                                }),
                        ])->columnSpanFull(),
                        Grid::make(2)->schema([
                            Select::make('age_group')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Age group')
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    HeaderStore::AGE_GROUP,
                                    tags: self::filterTags($get)
                                ))
                                ->default('universal')
                                ->searchable()
                                ->reactive()
                                ->afterStateHydrated(function (Select $component, ?Product $record): void {
                                    if (!$record) {
                                        return;
                                    }
                                    $component->state(self::shopifyRowValue($record, HeaderStore::AGE_GROUP));
                                }),
                            Select::make('status')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Status')
                                ->placeholder('Select option')
                                ->searchable()
                                ->preload()
                                ->options(fn () => Status::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'name')
                                    ->all()),
                        ])->columnSpanFull(),
                        Toggle::make('published')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                            ->label('Published')
                            ->helperText('Exported as true/false.')
                            ->afterStateHydrated(function (Toggle $component, $state): void {
                                $component->state(filter_var($state, FILTER_VALIDATE_BOOLEAN));
                            })
                            ->dehydrateStateUsing(fn (bool $state): string => $state ? 'true' : 'false'),
                        TextInput::make('you_save')
                            ->label('You Save')
                            ->numeric()
                            ->inputMode('decimal')
                            ->helperText('Internal only. Not exported.'),
                            TextInput::make('batch')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('Batch')
                                ->datalist(fn () => Product::query()
                                    ->whereNotNull('batch')
                                    ->distinct()
                                    ->orderBy('batch')
                                    ->pluck('batch')
                                    ->all())
                                ->placeholder('import_YYYYMMDDH')
                                ->helperText('Internal only. Not exported.'),
                            RichEditor::make('uvp_short_paragraph')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->label('UVP Short Paragraph')
                                ->toolbarButtons([
                                    'bold',
                                ])
                                ->columnSpanFull(),
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
            TextColumn::make('handle')
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->where('handle', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhereHas('variants', fn (Builder $variantQuery) => $variantQuery->where('sku', 'like', "%{$search}%"));
                })
                ->toggleable(),
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
            SelectFilter::make('collection')
                ->label('Collection')
                ->searchable()
                ->preload()
                ->options(fn () => DropdownOption::query()
                    ->whereNotNull('collection_style')
                    ->where('collection_style', '!=', '')
                    ->distinct()
                    ->orderBy('collection_style')
                    ->pluck('collection_style', 'collection_style')
                    ->all())
                ->query(function (Builder $query, array $data): Builder {
                    $collection = $data['value'] ?? null;
                    if (!is_string($collection) || trim($collection) === '') {
                        return $query;
                    }

                    $tagsRow = DropdownOption::query()
                        ->where('collection_style', $collection)
                        ->whereNotNull('collection_tag_primary')
                        ->select(['collection_tag_primary', 'collection_tag_secondary'])
                        ->first();

                    if (!$tagsRow) {
                        return $query;
                    }

                    $tags = array_filter([
                        $tagsRow->collection_tag_primary,
                        $tagsRow->collection_tag_secondary,
                    ], fn (?string $tag): bool => $tag !== null && trim($tag) !== '');

                    foreach ($tags as $tag) {
                        $query->whereRaw(
                            "FIND_IN_SET(?, REPLACE(tags, ', ', ','))",
                            [$tag]
                        );
                    }

                    return $query;
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
                BulkAction::make('bulkSyncShopify')
                    ->label('Sync Approved to Shopify')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $ids = $records->pluck('id')->all();
                        \App\Jobs\ProductShopifyUpdateJob::dispatch($ids, Auth::id());

                        Notification::make()
                            ->title('Shopify sync queued')
                            ->body('Only approved products will be synced.')
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
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(PermissionEnum::ProductView->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
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
                ->options(fn (): array => self::dropdownOptionsForHeader(HeaderStore::COLOR_METAFIELD));
        }

        if ($field['source'] === 'product' && $field['attribute'] === 'type') {
            $types = CategoryTypeMap::types();
            return Select::make($name)
                ->options(array_combine($types, $types))
                ->searchable();
        }

        if ($field['source'] === 'product' && $field['attribute'] === 'product_category') {
            return Select::make($name)
                ->options(CategoryTypeMap::categoryOptions())
                ->getOptionLabelUsing(fn ($value): ?string => CategoryTypeMap::categoryLabelForValue(
                    is_string($value) ? $value : null
                ))
                ->dehydrateStateUsing(function ($state): ?string {
                    if (!is_string($state) || trim($state) === '') {
                        return null;
                    }

                    $mapping = CategoryTypeMap::byCategoryValue($state);
                    return $mapping['shopify_taxonomy_gid'] ?? $state;
                })
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
        $data['variant_price'] = $record->variants()->orderBy('id')->value('price')
            ?? self::shopifyRowValue($record, HeaderStore::VARIANT_PRICE);
        $data['cost_per_item'] = self::shopifyRowValue($record, HeaderStore::COST_PER_ITEM);
        $data['uvp_short_paragraph'] = $record->uvp_short_paragraph
            ?? self::shopifyRowValue($record, HeaderStore::UVP_SHORT_PARAGRAPH);

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
            $approvalBumped = false;

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
                $approvalBumped = $product->wasChanged('approval_version');
            }

            if (!empty($rowUpdates)) {
                $row = ShopifyRow::where('import_id', $product->import_id)
                    ->where('handle', $product->handle)
                    ->where('row_type', 'product_primary')
                    ->first();
                if ($row) {
                    $rowChanged = self::applyRowUpdates($row, $rowUpdates);
                    if ($rowChanged && !$approvalBumped) {
                        self::bumpApprovalVersion($product);
                        $approvalBumped = true;
                    }
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
        $approvalBumped = false;

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
            $approvalBumped = $product->wasChanged('approval_version');
        }

        if (!empty($rowUpdates)) {
            $row = ShopifyRow::where('import_id', $product->import_id)
                ->where('handle', $product->handle)
                ->where('row_type', 'product_primary')
                ->first();
            if ($row) {
                $rowChanged = self::applyRowUpdates($row, $rowUpdates);
                if ($rowChanged && !$approvalBumped) {
                    self::bumpApprovalVersion($product);
                    $approvalBumped = true;
                }
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
        $seoDeindex = $data['seo_deindex'] ?? null;
        $variantPrice = self::nullIfEmpty($data['variant_price'] ?? null);
        $costPerItem = $data['cost_per_item'] ?? '';
        $colorString = $data['color_string'] ?? null;
        if (is_array($colorString)) {
            $colorString = implode('; ', array_values(array_unique(array_filter(array_map(
                fn ($value) => trim((string) $value),
                $colorString
            )))));
        }
        $materialsAndDimensions = $data['materials_and_dimensions'] ?? '';
        $jewelryMaterial = $data['jewelry_material'] ?? '';
        if (is_array($jewelryMaterial)) {
            $jewelryMaterial = implode('; ', array_values(array_unique(array_filter(array_map(
                fn ($v) => trim((string) $v),
                $jewelryMaterial
            )))));
        }
        $jewelryType = $data['jewelry_type'] ?? '';
        $braceletDesign = $data['bracelet_design'] ?? '';
        $necklaceDesign = $data['necklace_design'] ?? '';
        $earringDesign = $data['earring_design'] ?? '';
        $patternCategory = $data['pattern_category'] ?? '';
        $productMetals = $data['product_metals'] ?? '';
        $variantWeightUnit = $data['variant_weight_unit'] ?? null;

        $productData = Arr::except($data, [
            'extra_shopify_fields',
            'google_shopping_age_group',
            'target_gender',
            'age_group',
            'seo_deindex',
            'variant_price',
            'cost_per_item',
            'materials_and_dimensions',
            'jewelry_material',
            'jewelry_type',
            'bracelet_design',
            'necklace_design',
            'earring_design',
            'pattern_category',
            'product_metals',
            'variant_weight_unit',
        ]);

        $product->fill($productData);
        $product->save();
        $approvalBumped = $product->wasChanged('approval_version');

        $headers = $product->import?->headers ?? [];
        $allowed = array_flip(HeaderStore::extraProductHeaders($headers));

        $row = ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->first();

        if (!$row) {
            $rowIndex = (int) (ShopifyRow::where('import_id', $product->import_id)->max('row_index') ?? 0);
            $row = ShopifyRow::create([
                'import_id' => $product->import_id,
                'row_index' => $rowIndex + 1,
                'handle' => $product->handle,
                'row_type' => 'product_primary',
                'variant_key' => null,
                'image_key' => null,
                'data' => [
                    HeaderStore::HANDLE => $product->handle,
                    HeaderStore::TITLE => $product->title,
                    HeaderStore::BODY_HTML => $product->body_html,
                    HeaderStore::VENDOR => $product->vendor,
                    HeaderStore::TAGS => $product->tags,
                    HeaderStore::TYPE => $product->type,
                    HeaderStore::STATUS => $product->status,
                    HeaderStore::PUBLISHED => $product->published,
                    HeaderStore::PRODUCT_CATEGORY => $product->product_category,
                    HeaderStore::GOOGLE_PRODUCT_CATEGORY => $product->google_product_category,
                ],
            ]);
        }

        if ($row) {
            $rowUpdates = [];
            if ($googleShoppingAgeGroup !== null) {
                $value = trim((string) $googleShoppingAgeGroup);
                $rowUpdates[HeaderStore::GOOGLE_SHOPPING_AGE_GROUP] = $value === '' ? '' : strtolower($value);
            }
            if ($targetGender !== null) {
                $value = trim((string) $targetGender);
                $rowUpdates[HeaderStore::TARGET_GENDER] = $value === '' ? '' : strtolower($value);
            }
            if ($ageGroup !== null) {
                $value = trim((string) $ageGroup);
                $rowUpdates[HeaderStore::AGE_GROUP] = $value === '' ? '' : strtolower($value);
            }
            if ($seoDeindex !== null) {
                $rowUpdates[HeaderStore::SEO_DEINDEX] = $seoDeindex ? 'true' : 'false';
            }
            $uvpShortParagraph = self::nullIfEmpty($data['uvp_short_paragraph'] ?? null);
            if ($uvpShortParagraph !== null) {
                $rowUpdates[HeaderStore::UVP_SHORT_PARAGRAPH] = $uvpShortParagraph;
            }
            if ($colorString !== null) {
                $rowUpdates[HeaderStore::COLOR_METAFIELD] = $colorString;
            }
            if ($variantPrice !== null) {
                $rowUpdates[HeaderStore::VARIANT_PRICE] = $variantPrice;
            }
            if ($costPerItem !== null) {
                $rowUpdates['Cost per item'] = $costPerItem ?: '';
            }
            if ($materialsAndDimensions !== null) {
                $rowUpdates[HeaderStore::MATERIALS_AND_DIMENSIONS] = $materialsAndDimensions ?: '';
            }
            if ($jewelryMaterial !== null) {
                $rowUpdates[HeaderStore::JEWELRY_MATERIAL] = $jewelryMaterial ?: '';
            }
            if ($jewelryType !== null) {
                $rowUpdates[HeaderStore::JEWELRY_TYPE] = $jewelryType ?: '';
            }
            if ($braceletDesign !== null) {
                $rowUpdates[HeaderStore::BRACELET_DESIGN] = $braceletDesign ?: '';
            }
            if ($necklaceDesign !== null) {
                $rowUpdates['Necklace design (product.metafields.shopify.necklace-design)'] = $necklaceDesign ?: '';
            }
            if ($earringDesign !== null) {
                $rowUpdates['Earring design (product.metafields.shopify.earring-design)'] = $earringDesign ?: '';
            }
            if ($patternCategory !== null) {
                $rowUpdates['Pattern Category (product.metafields.custom.pattern_category)'] = $patternCategory ?: '';
            }
            if ($productMetals !== null) {
                $rowUpdates['Product Metals (product.metafields.custom.product_metals)'] = $productMetals ?: '';
            }

            foreach ($extra as $item) {
                $key = $item['key'] ?? null;
                if (!$key || empty($allowed) || !isset($allowed[$key])) {
                    continue;
                }
                $rowUpdates[$key] = $item['value'] ?? '';
            }

            $rowChanged = self::applyRowUpdates($row, $rowUpdates);
            if ($rowChanged && !$approvalBumped) {
                self::bumpApprovalVersion($product);
                $approvalBumped = true;
            }
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
        if ($variantPrice !== null) {
            $product->variants()->update([
                'price' => $variantPrice,
            ]);
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function applyRowUpdates(ShopifyRow $row, array $updates): bool
    {
        if (empty($updates)) {
            return false;
        }

        $rowData = $row->data ?? [];
        $changed = false;
        foreach ($updates as $header => $value) {
            if (is_array($value)) {
                $value = implode('; ', array_values(array_filter(array_map('trim', $value))));
            }
            $normalized = $value ?? '';
            if (!array_key_exists($header, $rowData) || (string) $rowData[$header] !== (string) $normalized) {
                $changed = true;
            }
            $rowData[$header] = $normalized;
        }

        if ($changed) {
            $row->data = $rowData;
            $row->save();
        }

        return $changed;
    }

    private static function bumpApprovalVersion(Product $product): void
    {
        Product::withoutEvents(function () use ($product): void {
            $product->forceFill([
                'approval_version' => ($product->approval_version ?? 1) + 1,
            ])->save();
        });
    }

    private static function dropdownOptionsForHeader(
        string $header,
        ?string $vendor = null,
        ?string $productType = null,
        mixed $tags = null
    ): array {
        return DropdownOption::optionsForHeader($header, $vendor, $productType, $tags)
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $value): array => [$value => $value])
            ->all();
    }

    private static function controlledDropdownCreateOptionForm(
        string $field,
        string $header,
        bool $multiple = false,
        bool $isColor = false
    ): array
    {
        return [
            TextInput::make('value')
                ->required()
                ->maxLength(255)
                ->default(fn (Get $get): ?string => self::defaultInvalidDropdownValue(
                    $get,
                    $field,
                    $header,
                    $multiple,
                    $isColor
                )),
            Select::make('collection_style')
                ->label('Collection')
                ->options(fn (): array => self::collectionOptions())
                ->default(fn (Get $get): ?string => self::defaultCollectionStyleForState($get))
                ->searchable()
                ->placeholder('Use current product collection by default')
                ->helperText('Pick a collection only if you want to override the current one.'),
        ];
    }

    private static function defaultInvalidDropdownValue(
        Get $get,
        string $field,
        string $header,
        bool $multiple = false,
        bool $isColor = false
    ): ?string {
        $invalid = self::invalidDropdownValuesForField(
            $get,
            null,
            $field,
            $header,
            $multiple,
            $isColor
        );

        return $invalid[0] ?? null;
    }

    private static function defaultCollectionStyleForState(Get $get): ?string
    {
        $selected = self::nullIfEmpty(self::stateFromGet($get, 'collection_filter'));
        if ($selected !== null) {
            return $selected;
        }

        $tags = self::normalizeTagList(self::stateFromGet($get, 'tags'));
        if (empty($tags)) {
            return null;
        }

        return self::collectionFromTags(implode(', ', $tags));
    }

    private static function invalidDropdownHint(
        Get $get,
        ?Product $record,
        string $field,
        string $header,
        bool $multiple = false,
        bool $isColor = false
    ): ?HtmlString {
        $invalid = self::invalidDropdownValuesForField($get, $record, $field, $header, $multiple, $isColor);
        if (empty($invalid)) {
            return null;
        }

        $values = implode(', ', $invalid);
        $safe = e($values);
        return new HtmlString(
            "<span class='text-danger-600 font-medium'>Invalid value(s): {$safe}. ".
            "Remove them or add them to dropdown options for a collection.</span>"
        );
    }

    private static function invalidDropdownValuesForField(
        Get $get,
        ?Product $record,
        string $field,
        string $header,
        bool $multiple,
        bool $isColor
    ): array {
        $state = self::stateFromGet($get, $field);
        $values = self::normalizeDropdownStateValues($state, $multiple, $isColor);
        if (empty($values)) {
            return [];
        }

        $vendor = null;
        $type = null;
        if ($header === HeaderStore::COLOR_METAFIELD) {
            $vendor = self::nullIfEmpty(self::stateFromGet($get, 'vendor') ?? $record?->vendor);
            $type = self::nullIfEmpty(self::stateFromGet($get, 'type') ?? $record?->type);
        }

        $tags = self::filterTags($get, $vendor, $type);
        $options = self::dropdownOptionsForHeader($header, $vendor, $type, $tags);
        $known = array_fill_keys(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            array_keys($options)
        ), true);

        $invalid = [];
        foreach ($values as $value) {
            $key = strtolower(trim($value));
            if ($key === '' || isset($known[$key])) {
                continue;
            }
            $invalid[] = $value;
        }

        return array_values(array_unique($invalid));
    }

    private static function normalizeDropdownStateValues(mixed $state, bool $multiple, bool $isColor): array
    {
        if ($state === null) {
            return [];
        }

        if ($multiple) {
            $raw = is_array($state) ? $state : explode(';', (string) $state);
            $values = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $raw
            ), static fn (string $value): bool => $value !== ''));

            if (!$isColor) {
                return $values;
            }

            return self::normalizeColorTokens($values);
        }

        $value = trim((string) $state);
        if ($value === '') {
            return [];
        }

        if ($isColor) {
            return self::normalizeColorTokens([$value]);
        }

        return [$value];
    }

    private static function normalizeColorTokens(array $values): array
    {
        $tokens = [];
        $seen = [];

        foreach ($values as $value) {
            $normalized = strtolower(trim((string) $value));
            if ($normalized === '') {
                continue;
            }
            $normalized = str_replace('&', 'and', $normalized);
            $normalized = preg_replace('/\s+/', '-', $normalized) ?? $normalized;
            $normalized = preg_replace('/-+/', '-', $normalized) ?? $normalized;
            $normalized = trim($normalized, '-');
            if ($normalized === 'multi') {
                $normalized = 'multicolour';
            }
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $tokens[] = $normalized;
        }

        return $tokens;
    }

    private static function createControlledDropdownOption(
        array $data,
        string $header,
        bool $isColor = false
    ): ?string {
        $value = trim((string) ($data['value'] ?? ''));
        if ($value === '') {
            return null;
        }

        if ($isColor) {
            $tokens = self::normalizeColorTokens([$value]);
            $value = $tokens[0] ?? $value;
        }

        $collectionStyle = self::nullIfEmpty($data['collection_style'] ?? null);
        $context = self::contextForCreateOption($header, $collectionStyle);

        $vendor = null;
        $productType = null;
        if ($header === HeaderStore::COLOR_METAFIELD) {
            $vendor = self::nullIfEmpty($data['vendor'] ?? null);
            $productType = self::nullIfEmpty($data['product_type'] ?? null);
        }

        $query = DropdownOption::query()
            ->where('header', $header)
            ->whereRaw('LOWER(value) = ?', [strtolower($value)]);

        if ($context['tag_primary'] !== null) {
            $query->where('collection_tag_primary', $context['tag_primary']);
        } else {
            $query->whereNull('collection_tag_primary');
        }

        if ($context['tag_secondary'] !== null) {
            $query->where('collection_tag_secondary', $context['tag_secondary']);
        } else {
            $query->whereNull('collection_tag_secondary');
        }

        if ($header === HeaderStore::COLOR_METAFIELD) {
            if ($vendor !== null) {
                $query->where('vendor', $vendor);
            } else {
                $query->whereNull('vendor');
            }
            if ($productType !== null) {
                $query->where('product_type', $productType);
            } else {
                $query->whereNull('product_type');
            }
        }

        $existing = $query->first();
        if ($existing) {
            if (!$existing->active) {
                $existing->update(['active' => true]);
            }
        } else {
            DropdownOption::create([
                'header' => $header,
                'value' => $value,
                'vendor' => $vendor,
                'product_type' => $productType,
                'collection_style' => $context['collection_style'],
                'collection_tag_primary' => $context['tag_primary'],
                'collection_tag_secondary' => $context['tag_secondary'],
                'active' => true,
                'sort_order' => 0,
            ]);
        }

        self::recalculateAllProductErrors();

        return $value;
    }

    private static function contextForCreateOption(string $header, ?string $collectionStyle): array
    {
        $contexts = self::applicableCollectionContexts($header);
        if (empty($contexts)) {
            return [
                'collection_style' => null,
                'tag_primary' => null,
                'tag_secondary' => null,
            ];
        }

        if ($collectionStyle !== null) {
            foreach ($contexts as $ctx) {
                if (strcasecmp((string) $ctx['collection_style'], $collectionStyle) === 0) {
                    return $ctx;
                }
            }
        }

        return $contexts[0];
    }

    private static function recalculateAllProductErrors(): void
    {
        $normalizer = app(Normalizer::class);
        Product::query()->chunkById(200, function ($products) use ($normalizer): void {
            foreach ($products as $product) {
                $normalizer->recalculateErrorsForProduct($product);
            }
        });
    }

    /**
     * @return array<int, array{collection_style:string,tag_primary:string,tag_secondary:?string}>
     */
    private static function collectionContexts(): array
    {
        return app(DropdownCollectionCatalog::class)->contexts();
    }

    /**
     * @return array<int, array{collection_style:string,tag_primary:string,tag_secondary:?string}>
     */
    private static function applicableCollectionContexts(string $header): array
    {
        $contexts = self::collectionContexts();

        $needle = match ($header) {
            HeaderStore::BRACELET_DESIGN => 'bracelet',
            'Necklace design (product.metafields.shopify.necklace-design)' => 'necklace',
            'Earring design (product.metafields.shopify.earring-design)' => 'earring',
            default => null,
        };

        if ($needle === null) {
            return $contexts;
        }

        return array_values(array_filter($contexts, function (array $ctx) use ($needle): bool {
            $haystack = strtolower(implode(' ', array_filter([
                $ctx['collection_style'] ?? '',
                $ctx['tag_primary'] ?? '',
                $ctx['tag_secondary'] ?? '',
            ])));

            return str_contains($haystack, $needle) || str_contains($haystack, $needle . 's');
        }));
    }

    private static function collectionOptions(): array
    {
        $collections = array_values(array_unique(array_filter(array_map(
            static fn (array $ctx): ?string => self::nullIfEmpty($ctx['collection_style'] ?? null),
            self::collectionContexts()
        ))));
        sort($collections);

        return empty($collections) ? [] : array_combine($collections, $collections);
    }

    private static function collectionFromTags(?string $tags): ?string
    {
        if ($tags === null || trim($tags) === '') {
            return null;
        }

        $normalized = TagNormalizer::parseTokens($tags);
        if (empty($normalized)) {
            return null;
        }

        $tokenSet = array_map('strtolower', $normalized);
        foreach (self::collectionContexts() as $ctx) {
            $primary = strtolower((string) ($ctx['tag_primary'] ?? ''));
            $secondary = strtolower((string) ($ctx['tag_secondary'] ?? ''));
            if ($primary === '' || !in_array($primary, $tokenSet, true)) {
                continue;
            }
            if ($secondary !== '' && !in_array($secondary, $tokenSet, true)) {
                continue;
            }
            return (string) $ctx['collection_style'];
        }

        return null;
    }

    private static function collectionTags(?string $collection): array
    {
        if ($collection === null || trim($collection) === '') {
            return [];
        }

        foreach (self::collectionContexts() as $ctx) {
            if (strcasecmp((string) ($ctx['collection_style'] ?? ''), $collection) !== 0) {
                continue;
            }

            return array_values(array_filter([
                self::nullIfEmpty($ctx['tag_primary'] ?? null),
                self::nullIfEmpty($ctx['tag_secondary'] ?? null),
            ]));
        }

        return [];
    }

    private static function allCollectionTags(): array
    {
        $tags = [];
        foreach (self::collectionContexts() as $ctx) {
            $primary = self::nullIfEmpty($ctx['tag_primary'] ?? null);
            $secondary = self::nullIfEmpty($ctx['tag_secondary'] ?? null);
            if ($primary !== null) {
                $tags[] = $primary;
            }
            if ($secondary !== null) {
                $tags[] = $secondary;
            }
        }

        return array_values(array_unique($tags));
    }

    private static function normalizeTagList(mixed $value): array
    {
        if (is_array($value)) {
            $tokens = [];
            foreach ($value as $item) {
                $token = TagNormalizer::normalizeToken((string) $item);
                if ($token !== null) {
                    $tokens[] = $token;
                }
            }
            return array_values(array_unique($tokens));
        }

        return TagNormalizer::parseTokens(is_string($value) ? $value : '');
    }

    private static function filterTags(Get $get, ?string $vendor = null, ?string $productType = null): array
    {
        $collection = self::stateFromGet($get, 'collection_filter');
        if ($collection) {
            $tags = self::collectionTags($collection);
            if (!empty($tags)) {
                return $tags;
            }
        }

        $rawTags = self::stateFromGet($get, 'tags');
        if (is_array($rawTags)) {
            $tokens = [];
            foreach ($rawTags as $value) {
                $token = TagNormalizer::normalizeToken((string) $value);
                if ($token !== null) {
                    $tokens[] = $token;
                }
            }
            return array_values(array_unique($tokens));
        }

        return TagNormalizer::parseTokens(is_string($rawTags) ? $rawTags : '');
    }

    private static function stateFromGet(Get $get, string $field): mixed
    {
        $paths = [
            $field,
            "../{$field}",
            "../../{$field}",
            "../../../{$field}",
            "../../../../{$field}",
            "data.{$field}",
            "../data.{$field}",
            "../../data.{$field}",
            "../../../data.{$field}",
            "../../../../data.{$field}",
        ];

        foreach ($paths as $path) {
            try {
                $value = $get($path);
            } catch (\Throwable $e) {
                continue;
            }

            if ($value !== null && !(is_string($value) && trim($value) === '')) {
                return $value;
            }
        }

        return null;
    }
}

