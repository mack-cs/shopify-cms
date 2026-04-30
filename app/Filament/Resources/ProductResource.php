<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Models\Status;
use App\Models\Product;
use App\Models\Approval;
use App\Models\DeletionRequest;
use App\Models\Image;
use App\Models\Import;
use App\Models\ProductPartialApprovalRequest;
use App\Models\ShopifyRow;
use App\Models\RequiredField;
use App\Models\Setting;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\CheckboxList;
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
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Support\Collection;
use App\Filament\Exports\ProductExporter;
use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\ProductResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Services\HeaderStore;
use App\Services\AdminNotification;
use App\Services\CategoryTypeMap;
use App\Services\DeletionRequestWorkflowService;
use App\Services\TagNormalizer;
use App\Services\Normalizer;
use App\Services\NewProductDraftSeeder;
use App\Services\DropdownCollectionCatalog;
use App\Services\ProductShopifyUpdater;
use App\Services\ProductPartialApprovalService;
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
                                ->helperText(fn (?Product $record): ?string => $record
                                    ? 'Current live Shopify handle. This stays unchanged until users run the Apply Approved URLs action.'
                                    : 'Initial live handle. The first 2/2 approval will also lock an SEO-approved handle from the approved title.')
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
                            TextInput::make('approved_handle')
                                ->label('Approved SEO Handle')
                                ->disabled()
                                ->dehydrated(false)
                                ->visible(fn (?Product $record): bool => (bool) $record)
                                ->helperText('Locked once from the approved title on the first 2/2 approval. Review products with URL Update Pending, then run Apply Approved URLs only for the selected products that should change.'),
                            TextInput::make('title')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record)),
                            RichEditor::make('body_html')
                                ->disabled(fn (?Product $record): bool => self::isDraftOwnedLocked($record))
                                ->columnSpanFull(),
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
                                    }),

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
                                ->options(fn (Get $get): array => self::vendorOptionsForCollection(
                                    $get('collection_filter'),
                                    $get('vendor')
                                ))
                                ->searchable()
                                ->preload()
                                ->reactive()
                                ->helperText(fn (Get $get): ?HtmlString => self::vendorSelectionHint(
                                    $get('collection_filter'),
                                    $get('vendor')
                                ))
                                ->rules([
                                    fn (Get $get): \Closure => self::vendorMatchesCollectionRule(
                                        $get('collection_filter')
                                    ),
                                ]),
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

                                    $expectedVendor = self::expectedVendorForCollection(
                                        is_string($state) ? $state : null
                                    );
                                    if ($expectedVendor !== null) {
                                        $set('vendor', $expectedVendor);
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
                                ->label('Color Style')
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
                                ->toolbarButtons(self::compactRichTextToolbarButtons())
                                ->columnSpanFull(),
                        ])->columnSpan(1),
                    ]),
                ]),
                Tabs\Tab::make('Extra Fields')->schema([
                    Section::make('Extra Shopify Fields')
                        ->schema([
                            Repeater::make('extra_shopify_fields')
                                ->label('Fields')
                                ->helperText('Edit these fields from New Products.')
                                ->schema([
                                    TextInput::make('key')
                                        ->label('Field')
                                        ->disabled()
                                        ->dehydrated(),
                                    TextInput::make('value')
                                        ->label('Value')
                                        ->disabled()
                                        ->dehydrated(false),
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
            Placeholder::make('product_edit_scroll_hint')
                ->label('')
                ->visible(fn (?Product $record): bool => (bool) $record)
                ->content(new HtmlString(
                    '<div class="flex items-center justify-center gap-4">' .
                    '<div class="text-sm font-medium" style="color:#2563eb;">Scroll up to edit product details.</div>' .
                    '<button type="button" class="fi-btn fi-btn-size-sm rounded-lg px-3 py-2 text-sm font-semibold" style="background-color:#2563eb;color:#ffffff;border:1px solid #2563eb;" onclick="window.scrollTo({ top: 0, behavior: \'smooth\' })">Scroll Up</button>' .
                    '</div>'
                ))
                ->columnSpanFull(),
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
                ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortProductsByThumbnail($query, $direction))
                ->toggleable(),
            TextColumn::make('handle')
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->where('handle', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhereHas('variants', fn (Builder $variantQuery) => $variantQuery->where('sku', 'like', "%{$search}%"));
                })
                ->sortable()
                ->toggleable(),
            TextColumn::make('approved_handle')
                ->label('Approved URL')
                ->placeholder('-')
                ->color(fn (Product $record): string => trim((string) ($record->approved_handle ?? '')) !== ''
                    && trim((string) ($record->approved_handle ?? '')) !== trim((string) ($record->handle ?? ''))
                    ? 'warning'
                    : 'gray')
                ->sortable()
                ->toggleable(),
            TextColumn::make('title')->searchable()->sortable()->toggleable(),
            TextColumn::make('seo_title')
                ->label('SEO title')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('seo_description')
                ->label('SEO description')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('color_string')
                ->label('Colors')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('jewelry_material')
                ->label('Jewelry material')
                ->state(fn (Product $record): string => self::shopifyRowValue($record, HeaderStore::JEWELRY_MATERIAL))
                ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortProductsByShopifyPrimaryHeader($query, HeaderStore::JEWELRY_MATERIAL, $direction))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('google_shopping_age_group')
                ->label('Age group')
                ->state(fn (Product $record): string => self::shopifyRowValue($record, HeaderStore::GOOGLE_SHOPPING_AGE_GROUP))
                ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortProductsByShopifyPrimaryHeader($query, HeaderStore::GOOGLE_SHOPPING_AGE_GROUP, $direction))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('cost_per_item')
                ->label('Cost per item')
                ->state(fn (Product $record): string => self::shopifyRowValue($record, HeaderStore::COST_PER_ITEM))
                ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortProductsByShopifyPrimaryHeader($query, HeaderStore::COST_PER_ITEM, $direction))
                ->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('has_errors')
                ->label('Errors')
                ->icon(fn (bool $state): string => $state ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                ->color(fn (bool $state): string => $state ? 'danger' : 'success')
                ->sortable()
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
                ->sortable()
                ->toggleable(),
            TextColumn::make('type')->label('Type')->sortable()->toggleable(),
            TextColumn::make('vendor')->sortable()->toggleable(),
            IconColumn::make('published')
                ->label('Published')
                ->boolean()
                ->state(fn (Product $record): bool => filter_var($record->published, FILTER_VALIDATE_BOOLEAN))
                ->sortable()
                ->toggleable(),
            TextColumn::make('batch')
                ->label('Batch')
                ->sortable()
                ->toggleable(),
            IconColumn::make('is_bundle')
                ->label('Bundle')
                ->boolean()
                ->trueColor('warning')
                ->falseColor('gray')
                ->sortable()
                ->toggleable(),
            TextColumn::make('you_save')
                ->label('You Save')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('approvals_current')
                ->label('Approvals')
                ->state(fn (Product $record) => $record->approvalsForCurrentVersionCount())
                ->formatStateUsing(fn (int $state) => "{$state}/2")
                ->badge()
                ->color(fn (int $state) => $state >= 2 ? 'success' : ($state === 1 ? 'warning' : 'gray'))
                ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortProductsByApprovalCount($query, $direction))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('delete_request_status')
                ->label('Delete Request')
                ->state(fn (Product $record): string => self::deletionRequestStatusLabel($record))
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Processing' => 'danger',
                    'Pending 1/2', 'Pending 2/2' => 'warning',
                    default => 'gray',
                })
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('partial_approval_status')
                ->label('Partial Approval')
                ->state(fn (Product $record): string => self::partialApprovalStatusLabel($record))
                ->badge()
                ->color(fn (string $state): string => match (true) {
                    str_starts_with($state, 'Pending') => 'warning',
                    str_starts_with($state, 'Approved') => 'success',
                    default => 'gray',
                })
                ->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('approved')
                ->label('Approved')
                ->state(fn (Product $record) => $record->isApprovedByTwo())
                ->boolean()
                ->trueColor('success')
                ->falseColor('gray')
                ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortProductsByApprovalCount($query, $direction))
                ->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
             Filter::make('recently_edited_today')
                ->label('Recently Edited Today')
                ->query(fn (Builder $query): Builder => $query->whereDate('updated_at', today())),
            Filter::make('edited_last_7_days')
                ->label('Edited in Last 7 Days')
                ->query(fn (Builder $query): Builder => $query->where('updated_at', '>=', now()->subDays(7))),
            Filter::make('pending_changes')
                ->label('Pending Changes')
                ->query(fn (Builder $query): Builder => $query->whereRaw(
                    '(select count(distinct user_id) from approvals where approvals.product_id = products.id and approvals.approval_version = products.approval_version) < 2'
                )),
            Filter::make('awaiting_approval')
                ->label('Awaiting Approval')
                ->query(fn (Builder $query): Builder => $query->whereRaw(
                    '(select count(distinct user_id) from approvals where approvals.product_id = products.id and approvals.approval_version = products.approval_version) < 2'
                )),
            Filter::make('awaiting_delete_approval')
                ->label('Awaiting Delete Approval')
                ->query(fn (Builder $query): Builder => $query->whereHas('deletionRequests', function (Builder $deletionQuery): void {
                    $deletionQuery->whereIn('status', ['pending', 'processing']);
                })),
            Filter::make('updated_at')
                ->form([
                    DatePicker::make('updated_from'),
                    DatePicker::make('updated_until'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return $query
                        ->when(
                            $data['updated_from'] ?? null,
                            fn (Builder $query, $date): Builder => $query->whereDate('updated_at', '>=', $date),
                        )
                        ->when(
                            $data['updated_until'] ?? null,
                            fn (Builder $query, $date): Builder => $query->whereDate('updated_at', '<=', $date),
                        );
                }),
            SelectFilter::make('type')
                ->label('Type')
                ->multiple()
                ->options(fn () => ['__none__' => 'No type'] + Product::query()
                    ->whereNotNull('type')
                    ->where('type', '!=', '')
                    ->distinct()
                    ->orderBy('type')
                    ->pluck('type', 'type')
                    ->all())
                ->searchable()
                ->preload()
                ->query(function (Builder $query, array $data): Builder {
                    $values = $data['values'] ?? [];
                    if (!is_array($values) || empty($values)) {
                        return $query;
                    }

                    $values = array_values(array_filter(array_map(
                        fn ($value): string => trim((string) $value),
                        $values
                    )));

                    if ($values === []) {
                        return $query;
                    }

                    $includeNone = in_array('__none__', $values, true);
                    $types = array_values(array_filter(
                        $values,
                        fn (string $value): bool => $value !== '__none__'
                    ));

                    return $query->where(function (Builder $sub) use ($includeNone, $types): void {
                        if ($types !== []) {
                            $sub->orWhereIn('type', $types);
                        }

                        if ($includeNone) {
                            $sub->orWhereNull('type')
                                ->orWhereRaw("TRIM(COALESCE(type, '')) = ''");
                        }
                    });
                }),
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
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn () => ['__none__' => 'No collection'] + DropdownOption::query()
                    ->whereNotNull('collection_style')
                    ->where('collection_style', '!=', '')
                    ->distinct()
                    ->orderBy('collection_style')
                    ->pluck('collection_style', 'collection_style')
                    ->all())
                ->query(function (Builder $query, array $data): Builder {
                    $collections = $data['values'] ?? [];
                    if (!is_array($collections) || empty($collections)) {
                        return $query;
                    }

                    $collections = array_values(array_filter(array_map(
                        fn ($value): string => trim((string) $value),
                        $collections
                    )));

                    if ($collections === []) {
                        return $query;
                    }

                    $includeNone = in_array('__none__', $collections, true);
                    $collections = array_values(array_filter(
                        $collections,
                        fn (string $value): bool => $value !== '__none__'
                    ));

                    $collectionTags = DropdownOption::query()
                        ->whereIn('collection_style', $collections)
                        ->whereNotNull('collection_tag_primary')
                        ->get(['collection_style', 'collection_tag_primary', 'collection_tag_secondary'])
                        ->reduce(function (array $carry, DropdownOption $option): array {
                            $style = trim((string) $option->collection_style);
                            if ($style === '') {
                                return $carry;
                            }

                            $tags = array_values(array_filter([
                                $option->collection_tag_primary,
                                $option->collection_tag_secondary,
                            ], fn (?string $tag): bool => $tag !== null && trim($tag) !== ''));

                            $carry[$style] = array_values(array_unique(array_merge($carry[$style] ?? [], $tags)));

                            return $carry;
                        }, []);

                    if ($collectionTags === [] && !$includeNone) {
                        return $query;
                    }

                    $allCollectionTags = self::allCollectionTags();

                    return $query->where(function (Builder $sub) use ($collections, $collectionTags, $includeNone, $allCollectionTags): void {
                        foreach ($collections as $collection) {
                            $tags = $collectionTags[$collection] ?? [];
                            if ($tags === []) {
                                continue;
                            }

                            $sub->orWhere(function (Builder $tagQuery) use ($tags): void {
                                foreach ($tags as $tag) {
                                    $tagQuery->whereRaw(
                                        "FIND_IN_SET(?, REPLACE(tags, ', ', ','))",
                                        [$tag]
                                    );
                                }
                            });
                        }

                        if ($includeNone) {
                            $sub->orWhere(function (Builder $noCollectionQuery) use ($allCollectionTags): void {
                                $noCollectionQuery->whereNull('tags')
                                    ->orWhereRaw("TRIM(COALESCE(tags, '')) = ''");

                                if ($allCollectionTags !== []) {
                                    $noCollectionQuery->orWhere(function (Builder $tagFreeQuery) use ($allCollectionTags): void {
                                        foreach ($allCollectionTags as $tag) {
                                            $tagFreeQuery->whereRaw(
                                                "NOT FIND_IN_SET(?, REPLACE(COALESCE(tags, ''), ', ', ','))",
                                                [$tag]
                                            );
                                        }
                                    });
                                }
                            });
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
            Filter::make('missing_image_alt_text')
                ->label('Missing Image Alt Text')
                ->query(fn (Builder $query): Builder => $query->whereHas('images', function (Builder $imageQuery): void {
                    $imageQuery->where(function (Builder $altQuery): void {
                        $altQuery->whereNull('alt_text')
                            ->orWhereRaw("TRIM(COALESCE(alt_text, '')) = ''");
                    });
                })),

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
            TernaryFilter::make('url_update_pending')
                ->label('URL Update Pending')
                ->queries(
                    true: fn (Builder $query): Builder => $query
                        ->whereNotNull('approved_handle')
                        ->where('approved_handle', '!=', '')
                        ->whereColumn('approved_handle', '!=', 'handle'),
                    false: fn (Builder $query): Builder => $query->where(function (Builder $sub): void {
                        $sub->whereNull('approved_handle')
                            ->orWhere('approved_handle', '')
                            ->orWhereColumn('approved_handle', 'handle');
                    })
                ),
            TernaryFilter::make('is_bundle')
                ->label('Bundles'),
            TernaryFilter::make('in_new_products')
                ->label('In New Products')
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereExists(function ($sub): void {
                        $sub->selectRaw('1')
                            ->from('new_product_drafts')
                            ->whereColumn('new_product_drafts.handle', 'products.handle');
                    }),
                    false: fn (Builder $query): Builder => $query->whereNotExists(function ($sub): void {
                        $sub->selectRaw('1')
                            ->from('new_product_drafts')
                            ->whereColumn('new_product_drafts.handle', 'products.handle');
                    })
                ),
            TernaryFilter::make('title_type_status')
                ->label('Title Type')
                ->placeholder('All')
                ->trueLabel('Needs Update')
                ->falseLabel('Good Title')
                ->queries(
                    true: fn (Builder $query): Builder => self::applyNeedsTitleUpdateFilter($query),
                    false: fn (Builder $query): Builder => self::applyGoodTitleFilter($query),
                    blank: fn (Builder $query): Builder => $query,
                ),
            Filter::make('missing_seo_information')
                ->label('Missing SEO Info')
                ->query(fn (Builder $query): Builder => self::applyMissingSeoInformationFilter($query)),
            TernaryFilter::make('has_errors')
                ->label('Errors'),
            Filter::make('awaiting_partial_approval')
                ->label('Awaiting Partial Approval')
                ->query(fn (Builder $query): Builder => $query->whereHas('partialApprovalRequests', function (Builder $sub): void {
                    $sub->whereColumn('approval_version', 'products.approval_version')
                        ->where('status', ProductPartialApprovalRequest::STATUS_PENDING);
                })),
        ])->actions([
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-badge')
                ->iconButton()
                ->color('success')
                ->tooltip('Approve')
                ->requiresConfirmation()
                ->disabled(fn (Product $record): bool => $record->has_errors || $record->isApprovedByTwo())
                ->action(function (Product $record): void {
                    self::approveRecord($record);
                }),
            Action::make('editDraft')
                ->label('Edit Draft')
                ->icon('heroicon-o-pencil-square')
                ->iconButton()
                ->color('gray')
                ->tooltip('Edit Draft')
                ->action(function (Product $record) {
                    $draft = app(NewProductDraftSeeder::class)->upsertFromProduct($record, Auth::id());

                    return redirect(NewProductDraftResource::getUrl('edit', ['record' => $draft]));
                })
                ->visible(fn (Product $record): bool => static::canEdit($record)),
            Action::make('requestDelete')
                ->label('Request Delete')
                ->iconButton()
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->tooltip('Request Delete')
                ->visible(fn (Product $record): bool => static::canDelete($record))
                ->disabled(fn (Product $record): bool => self::currentDeletionRequest($record) !== null)
                ->form([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->rows(3)
                        ->maxLength(1000),
                ])
                ->action(function (Product $record, array $data): void {
                    self::requestDeletion($record, $data['reason'] ?? null);
                }),
            Action::make('approveDelete')
                ->label('Approve Delete')
                ->iconButton()
                ->color('warning')
                ->icon('heroicon-o-check-circle')
                ->tooltip('Approve Delete')
                ->visible(fn (Product $record): bool => static::canDelete($record))
                ->disabled(fn (Product $record): bool => !self::canApproveDeletion($record))
                ->action(function (Product $record): void {
                    self::approveDeletion($record);
                }),
        ])->bulkActions([
            BulkActionGroup::make([
                BulkAction::make('bulkApprove')
                    ->label('Bulk Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->extraAttributes(['class' => 'product-bulk-action product-bulk-action--approve'])
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $errorCount = $records->filter(fn (Product $record) => $record->has_errors)->count();
                        $approvedCount = 0;
                        $skippedCount = 0;

                        foreach ($records as $record) {
                            if ($record->has_errors) {
                                continue;
                            }

                            if ($record->isApprovedByTwo()) {
                                $skippedCount++;
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

                        self::sendNotification(Notification::make()
                            ->title('Bulk approval complete')
                            ->body($parts ? implode(' ', $parts) : 'No products were approved.')
                            ->success()
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulkSyncShopify')
                    ->label('Sync Approved to Shopify')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('info')
                    ->extraAttributes(['class' => 'product-bulk-action product-bulk-action--sync'])
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $ids = $records->pluck('id')->all();
                        $selectedCount = count($ids);
                        \App\Jobs\ProductShopifyUpdateJob::dispatch($ids, Auth::id());

                        self::sendNotification(Notification::make()
                            ->title('Shopify sync queued')
                            ->body("Queued {$selectedCount} selected product(s). Active products will sync only fully approved or partially approved fields.")
                            ->success()
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulkApplyApprovedHandles')
                    ->label('Apply Approved URLs')
                    ->icon('heroicon-o-link')
                    ->color('primary')
                    ->extraAttributes(['class' => 'product-bulk-action product-bulk-action--url-sync'])
                    ->visible(fn (): bool => Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false)
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $partialApprovalService = app(ProductPartialApprovalService::class);
                        $handleService = app(\App\Services\ProductHandleService::class);
                        $eligibleIds = [];
                        $skippedNotApproved = 0;
                        $skippedNoChange = 0;

                        foreach ($records as $record) {
                            $isFullyApproved = $record->isApprovedByTwo();
                            $hasApprovedTitlePartial = !$isFullyApproved
                                && $partialApprovalService->hasApprovedTitleForCurrentVersion($record);

                            if ($hasApprovedTitlePartial) {
                                $handleService->syncApprovedHandleToCurrentTitle($record);
                            }

                            $approvedHandle = trim((string) ($record->approved_handle ?? ''));
                            $currentHandle = trim((string) ($record->handle ?? ''));

                            if (!$isFullyApproved && !$hasApprovedTitlePartial) {
                                $skippedNotApproved++;
                                continue;
                            }

                            if ($approvedHandle === '' || $approvedHandle === $currentHandle) {
                                $skippedNoChange++;
                                continue;
                            }

                            $eligibleIds[] = (int) $record->id;
                        }

                        if (empty($eligibleIds)) {
                            $parts = [];
                            if ($skippedNotApproved > 0) {
                                $parts[] = "Skipped {$skippedNotApproved} not approved.";
                            }
                            if ($skippedNoChange > 0) {
                                $parts[] = "Skipped {$skippedNoChange} with no approved URL change.";
                            }

                            self::sendNotification(Notification::make()
                                ->title('No URL updates queued')
                                ->body($parts ? implode(' ', $parts) : 'None of the selected products need a URL update.')
                                ->warning()
                            );
                            return;
                        }

                        \App\Jobs\ProductShopifyUpdateJob::dispatch(
                            $eligibleIds,
                            Auth::id(),
                            [ProductShopifyUpdater::SYNC_SCOPE_PRODUCT],
                            [ProductShopifyUpdater::CORE_FIELD_HANDLE]
                        );

                        $parts = ["Queued " . count($eligibleIds) . " product(s) to apply approved URLs."];
                        $parts[] = 'Pending redirects will be created only for products whose live handle changes.';
                        if ($skippedNotApproved > 0) {
                            $parts[] = "Skipped {$skippedNotApproved} not approved.";
                        }
                        if ($skippedNoChange > 0) {
                            $parts[] = "Skipped {$skippedNoChange} with no approved URL change.";
                        }

                        self::sendNotification(Notification::make()
                            ->title('Approved URL sync queued')
                            ->body(implode(' ', $parts))
                            ->success()
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulkPartialSyncShopify')
                    ->label('Sync Selected Fields to Shopify')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('warning')
                    ->extraAttributes(['class' => 'product-bulk-action product-bulk-action--partial-sync'])
                    ->form([
                        CheckboxList::make('scopes')
                            ->label('Fields to sync')
                            ->options(ProductShopifyUpdater::syncScopeLabels())
                            ->default([ProductShopifyUpdater::SYNC_SCOPE_SEO])
                            ->required()
                            ->live()
                            ->columns(2)
                            ->helperText('Only the selected fields will be pushed to Shopify. Active products can sync with approved partial fields; non-active products still need the existing full approval workflow.'),
                        CheckboxList::make('core_fields')
                            ->label('Product core fields')
                            ->options(ProductShopifyUpdater::productCoreFieldLabels())
                            ->default(array_values(array_intersect(
                                ProductShopifyUpdater::defaultCoreFields(),
                                ProductShopifyUpdater::availableProductCoreFields()
                            )))
                            ->columns(5)
                            ->visible(fn (Get $get): bool => in_array(
                                ProductShopifyUpdater::SYNC_SCOPE_PRODUCT,
                                array_values(array_filter($get('scopes') ?? [], 'is_string')),
                                true
                            ))
                            ->helperText('Choose the exact product columns to push when Product core fields is selected. URL changes are handled separately through Apply Approved URLs.'),
                        CheckboxList::make('metafield_fields')
                            ->label('Metafields')
                            ->options(ProductShopifyUpdater::metafieldFieldLabels())
                            ->columns(5)
                            ->visible(fn (Get $get): bool => in_array(
                                ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS,
                                array_values(array_filter($get('scopes') ?? [], 'is_string')),
                                true
                            ))
                            ->helperText('Choose the exact metafields to sync when Metafields is selected.'),
                    ])
                    ->requiresConfirmation()
                    ->modalWidth('7xl')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::Start)
                    ->modalSubmitAction(fn (\Filament\Actions\StaticAction $action) => $action->size(\Filament\Support\Enums\ActionSize::Medium))
                    ->modalCancelAction(fn (\Filament\Actions\StaticAction $action) => $action->size(\Filament\Support\Enums\ActionSize::Medium))
                    ->action(function (Collection $records, array $data): void {
                        $ids = $records->pluck('id')->map(fn ($id): int => (int) $id)->all();
                        $selectedCount = count($ids);
                        $scopes = array_values(array_unique(array_map(
                            'strval',
                            array_filter($data['scopes'] ?? [], fn ($scope): bool => is_string($scope) && $scope !== '')
                        )));

                        if (empty($scopes)) {
                            self::sendNotification(Notification::make()
                                ->title('No fields selected')
                                ->body('Choose at least one field group to sync.')
                                ->warning()
                            );
                            return;
                        }

                        $coreFields = array_values(array_unique(array_map(
                            'strval',
                            array_filter($data['core_fields'] ?? [], fn ($field): bool => is_string($field) && $field !== '')
                        )));

                        $metafieldFields = array_values(array_unique(array_map(
                            'strval',
                            array_filter($data['metafield_fields'] ?? [], fn ($field): bool => is_string($field) && $field !== '')
                        )));

                        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_PRODUCT, $scopes, true) && empty($coreFields)) {
                            self::sendNotification(Notification::make()
                                ->title('No core fields selected')
                                ->body('Choose at least one product core field or deselect Product core fields.')
                                ->warning()
                            );
                            return;
                        }

                        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS, $scopes, true) && empty($metafieldFields)) {
                            self::sendNotification(Notification::make()
                                ->title('No metafields selected')
                                ->body('Choose at least one metafield or deselect Metafields.')
                                ->warning()
                            );
                            return;
                        }

                        $selectedFields = array_values(array_unique(array_merge($coreFields, $metafieldFields)));

                        \App\Jobs\ProductShopifyUpdateJob::dispatch($ids, Auth::id(), $scopes, $selectedFields);

                        $scopeSummary = collect($scopes)
                            ->map(fn (string $scope): string => ProductShopifyUpdater::syncScopeLabels()[$scope] ?? $scope)
                            ->implode(', ');

                        $coreSummary = in_array(ProductShopifyUpdater::SYNC_SCOPE_PRODUCT, $scopes, true)
                            ? ' Core fields: ' . collect($coreFields)
                                ->map(fn (string $field): string => ProductShopifyUpdater::productCoreFieldLabels()[$field] ?? $field)
                                ->implode(', ') . '.'
                            : '';

                        $metafieldSummary = in_array(ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS, $scopes, true)
                            ? ' Metafields: ' . collect($metafieldFields)
                                ->map(fn (string $field): string => ProductShopifyUpdater::metafieldFieldLabels()[$field] ?? $field)
                                ->implode(', ') . '.'
                            : '';

                        self::sendNotification(Notification::make()
                            ->title('Partial Shopify sync queued')
                            ->body("Queued {$selectedCount} selected product(s). Scopes: {$scopeSummary}.{$coreSummary}{$metafieldSummary} Active products will sync only fully approved or partially approved fields.")
                            ->success()
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('requestPartialApproval')
                    ->label('Request Partial Approval')
                    ->icon('heroicon-o-check-circle')
                    ->color('warning')
                    ->form(self::partialApprovalFormSchema())
                    ->requiresConfirmation()
                    ->modalWidth('7xl')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::Start)
                    ->modalSubmitAction(fn (\Filament\Actions\StaticAction $action) => $action->size(\Filament\Support\Enums\ActionSize::Medium))
                    ->modalCancelAction(fn (\Filament\Actions\StaticAction $action) => $action->size(\Filament\Support\Enums\ActionSize::Medium))
                    ->action(function (Collection $records, array $data): void {
                        $normalized = app(ProductPartialApprovalService::class)->normalizeSelections(
                            $data['scopes'] ?? [],
                            $data['core_fields'] ?? [],
                            $data['metafield_fields'] ?? [],
                        );

                        if ($normalized['scopes'] === []) {
                            self::sendNotification(Notification::make()
                                ->title('No fields selected')
                                ->body('Choose at least one field group for partial approval.')
                                ->warning()
                            );
                            return;
                        }

                        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_PRODUCT, $normalized['scopes'], true) && $normalized['core_fields'] === []) {
                            self::sendNotification(Notification::make()
                                ->title('No core fields selected')
                                ->body('Choose at least one product core field or deselect Product core fields.')
                                ->warning()
                            );
                            return;
                        }

                        if (
                            in_array(ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS, $normalized['scopes'], true)
                            && empty(array_intersect($normalized['core_fields'], ProductShopifyUpdater::availableMetafieldFields()))
                        ) {
                            self::sendNotification(Notification::make()
                                ->title('No metafields selected')
                                ->body('Choose at least one metafield or deselect Metafields.')
                                ->warning()
                            );
                            return;
                        }

                        $service = app(ProductPartialApprovalService::class);
                        $targetApproverId = ($data['approval_target'] ?? 'any') === 'specific'
                            ? (int) ($data['target_approver_id'] ?? 0)
                            : null;

                        $summary = $service->request(
                            $records,
                            (int) Auth::id(),
                            $normalized['scopes'],
                            $normalized['core_fields'],
                            $targetApproverId,
                            $data['request_note'] ?? null,
                        );

                        if (($summary['skipped_invalid'] ?? 0) > 0 && !($data['continue_on_invalid'] ?? false)) {
                            $sample = collect($summary['invalid_products'])
                                ->take(5)
                                ->map(fn (array $item): string => "{$item['title']} (" . implode(', ', $item['errors']) . ")")
                                ->implode(' | ');

                            self::sendNotification(Notification::make()
                                ->title('Some selected products failed partial validation')
                                ->body("{$summary['skipped_invalid']} product(s) have errors in the selected fields. Enable 'Continue and skip invalid products' to continue. {$sample}")
                                ->warning()
                            );
                            return;
                        }

                        $parts = [];
                        if ($summary['requested'] > 0) {
                            $parts[] = "Requested {$summary['requested']}.";
                            if (!empty($summary['request_batch_id'])) {
                                $parts[] = 'Batch ' . app(ProductPartialApprovalService::class)->batchLabel($summary['request_batch_id']) . '.';
                            }
                            if ($targetApproverId) {
                                $approverName = self::partialApprovalApproverOptions()[$targetApproverId] ?? 'selected reviewer';
                                $parts[] = "Assigned to {$approverName} only.";
                            } else {
                                $parts[] = 'Available to any eligible reviewer.';
                            }
                        }
                        if ($summary['skipped_inactive'] > 0) {
                            $sample = collect($summary['inactive_products'] ?? [])
                                ->take(5)
                                ->map(fn (array $item): string => "{$item['title']} [status: {$item['status']}]")
                                ->implode(' | ');
                            $parts[] = "Skipped {$summary['skipped_inactive']} because partial approval requests only work for active products.";
                            if ($sample !== '') {
                                $parts[] = "Inactive examples: {$sample}";
                            }
                        }
                        if ($summary['skipped_existing'] > 0) {
                            $sample = collect($summary['existing_products'] ?? [])
                                ->take(5)
                                ->map(fn (array $item): string => $item['title'])
                                ->implode(' | ');
                            $parts[] = "Skipped {$summary['skipped_existing']} because they already have a pending partial approval request.";
                            if ($sample !== '') {
                                $parts[] = "Existing request examples: {$sample}";
                            }
                        }
                        if ($summary['skipped_invalid'] > 0) {
                            $sample = collect($summary['invalid_products'])
                                ->take(5)
                                ->map(fn (array $item): string => "{$item['title']} (" . implode(', ', $item['errors']) . ")")
                                ->implode(' | ');
                            $parts[] = "Skipped {$summary['skipped_invalid']} with selected-field errors.";
                            if ($sample !== '') {
                                $parts[] = "Examples: {$sample}";
                            }
                        }

                        self::sendNotification(Notification::make()
                            ->title('Partial approval request complete')
                            ->body($parts ? implode(' ', $parts) : 'No partial approvals were requested.')
                            ->status($summary['requested'] > 0 ? 'success' : 'warning')
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('approvePartialRequests')
                    ->label('Approve Partial Requests')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $summary = app(ProductPartialApprovalService::class)->approve($records, (int) Auth::id());

                        $parts = [];
                        if ($summary['approved'] > 0) {
                            $parts[] = "Approved {$summary['approved']} partial request(s).";
                        }
                        if (($summary['skipped_no_pending'] ?? 0) > 0) {
                            $parts[] = "Skipped {$summary['skipped_no_pending']} with no pending partial request.";
                        }
                        if (($summary['skipped_own_request'] ?? 0) > 0) {
                            $parts[] = "Skipped {$summary['skipped_own_request']} because you cannot approve your own request.";
                        }
                        if (($summary['skipped_targeted'] ?? 0) > 0) {
                            $parts[] = "Skipped {$summary['skipped_targeted']} because they were assigned to a different reviewer.";
                        }

                        self::sendNotification(Notification::make()
                            ->title('Partial approval complete')
                            ->body($parts ? implode(' ', $parts) : 'No partial approvals were recorded.')
                            ->status($summary['approved'] > 0 ? 'success' : 'warning')
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulkBackupImages')
                    ->label('Queue Image Backup')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->extraAttributes(['class' => 'product-bulk-action product-bulk-action--backup'])
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $ids = $records->pluck('id')->map(fn ($id): int => (int) $id)->all();
                        $selectedCount = count($ids);

                        \App\Jobs\ProductImageBackupJob::dispatch(
                            $ids,
                            Auth::id(),
                            'Manual product image backup'
                        );

                        self::sendNotification(Notification::make()
                            ->title('Image backup queued')
                            ->body("Queued image backup for {$selectedCount} selected product(s). Existing backups will be reused.")
                            ->success()
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
                ExportBulkAction::make()
                    ->color('danger')
                    ->extraAttributes(['class' => 'product-bulk-action product-bulk-action--export'])
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

    public static function approveRecord(Product $record): void
    {
        if ($record->has_errors) {
            self::sendNotification(Notification::make()
                ->title('Approval blocked')
                ->body('Fix product errors before approval.')
                ->warning()
            );
            return;
        }

        if ($record->isApprovedByTwo()) {
            self::sendNotification(Notification::make()
                ->title('Already fully approved')
                ->body('This version already has 2 approvals.')
                ->warning()
            );
            return;
        }

        $exists = Approval::where('product_id', $record->id)
            ->where('user_id', Auth::id())
            ->where('approval_version', $record->approval_version)
            ->exists();

        if ($exists) {
            self::sendNotification(Notification::make()
                ->title('Already approved')
                ->body('You have already approved this version.')
                ->warning()
            );
            return;
        }

        Approval::create([
            'product_id' => $record->id,
            'user_id' => Auth::id(),
            'approval_version' => $record->approval_version,
        ]);

        $record->refresh();

        self::sendNotification(Notification::make()
            ->title('Product approved')
            ->body("Approvals: {$record->approvalsForCurrentVersionCount()}/2.")
            ->success()
        );
    }

    /**
     * @param array<int, int> $imageIds
     */
    public static function queueSelectedImageSync(Product $record, array $imageIds): void
    {
        $imageIds = array_values(array_unique(array_map('intval', $imageIds)));

        if (empty($imageIds)) {
            self::sendNotification(Notification::make()
                ->title('No images selected')
                ->body('Select at least one image to sync.')
                ->warning()
            );
            return;
        }

        if (!$record->isApprovedByTwo()) {
            self::sendNotification(Notification::make()
                ->title('Approval required')
                ->body('This product needs 2 approvals before image sync.')
                ->warning()
            );
            return;
        }

        if (!$record->handle) {
            self::sendNotification(Notification::make()
                ->title('Missing handle')
                ->body('This product must have a handle before image sync.')
                ->warning()
            );
            return;
        }

        \App\Jobs\SelectedProductImageShopifySyncJob::dispatch(
            $record->id,
            $imageIds,
            Auth::id(),
            'Manual selected image sync'
        );

        self::sendNotification(Notification::make()
            ->title('Selected image sync queued')
            ->body('Shopify image sync has been queued for the selected images.')
            ->success()
        );
    }

    public static function queueProductImageBackup(Product $record): void
    {
        \App\Jobs\ProductImageBackupJob::dispatch(
            [$record->id],
            Auth::id(),
            'Manual product image backup'
        );

        self::sendNotification(Notification::make()
            ->title('Image backup queued')
            ->body('Image backup has been queued for this product. Existing backups will be reused.')
            ->success()
        );
    }

    private static function sendNotification(Notification $notification): void
    {
        AdminNotification::send($notification);
    }

    private static function partialApprovalFormSchema(): array
    {
        $defaultCoreFields = array_values(array_intersect(
            ProductShopifyUpdater::defaultCoreFields(),
            ProductShopifyUpdater::availableProductCoreFields()
        ));

        return [
            CheckboxList::make('scopes')
                ->label('Fields to request approval for')
                ->options(ProductShopifyUpdater::syncScopeLabels())
                ->default([ProductShopifyUpdater::SYNC_SCOPE_SEO])
                ->required()
                ->live()
                ->columns(2)
                ->helperText('Partial approval applies only to active products. Non-active products still need the existing full approval workflow.'),
            CheckboxList::make('core_fields')
                ->label('Product core fields')
                ->options(ProductShopifyUpdater::productCoreFieldLabels())
                ->default($defaultCoreFields)
                ->columns(5)
                ->visible(fn (Get $get): bool => in_array(
                    ProductShopifyUpdater::SYNC_SCOPE_PRODUCT,
                    array_values(array_filter($get('scopes') ?? [], 'is_string')),
                    true
                )),
            CheckboxList::make('metafield_fields')
                ->label('Metafields')
                ->options(ProductShopifyUpdater::metafieldFieldLabels())
                ->columns(5)
                ->visible(fn (Get $get): bool => in_array(
                    ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS,
                    array_values(array_filter($get('scopes') ?? [], 'is_string')),
                    true
                )),
            Checkbox::make('continue_on_invalid')
                ->label('Continue and skip invalid products')
                ->helperText('If some selected products have errors in the selected fields, they will be skipped instead of blocking the request.'),
            Select::make('approval_target')
                ->label('Approver routing')
                ->options([
                    'any' => 'Any eligible reviewer',
                    'specific' => 'Specific reviewer only',
                ])
                ->default('any')
                ->native(false)
                ->live()
                ->helperText('Choose whether any eligible reviewer can approve this request, or assign it to one specific reviewer.'),
            Select::make('target_approver_id')
                ->label('Specific reviewer')
                ->options(fn (): array => self::partialApprovalApproverOptions())
                ->searchable()
                ->preload()
                ->visible(fn (Get $get): bool => $get('approval_target') === 'specific')
                ->required(fn (Get $get): bool => $get('approval_target') === 'specific'),
            Textarea::make('request_note')
                ->label('Review note')
                ->rows(3)
                ->maxLength(1000)
                ->helperText('Optional context for the reviewer. This stays recorded with the request.'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function partialApprovalApproverOptions(): array
    {
        return app(ProductPartialApprovalService::class)
            ->eligibleApproversQuery((int) Auth::id())
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(int) $id => $name])
            ->all();
    }

    private static function currentDeletionRequest(Product $record): ?DeletionRequest
    {
        return app(DeletionRequestWorkflowService::class)->openRequestFor($record);
    }

    private static function canApproveDeletion(Product $record): bool
    {
        $request = self::currentDeletionRequest($record);

        return $request !== null
            && $request->status === DeletionRequest::STATUS_PENDING
            && !$request->userHasApproved(Auth::id());
    }

    private static function deletionRequestStatusLabel(Product $record): string
    {
        $request = self::currentDeletionRequest($record);
        if (!$request) {
            return 'None';
        }

        if ($request->status === DeletionRequest::STATUS_PROCESSING) {
            return 'Processing';
        }

        return 'Pending ' . $request->approvalCount() . '/2';
    }

    private static function partialApprovalStatusLabel(Product $record): string
    {
        $pending = $record->partialApprovalRequests()
            ->where('approval_version', $record->approval_version)
            ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
            ->count();

        if ($pending > 0) {
            return "Pending {$pending}";
        }

        $approved = $record->partialApprovalRequests()
            ->where('approval_version', $record->approval_version)
            ->where('status', ProductPartialApprovalRequest::STATUS_APPROVED)
            ->count();

        if ($approved > 0) {
            return "Approved {$approved}";
        }

        return 'None';
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

    public static function requestDeletion(Product $record, ?string $reason = null): void
    {
        try {
            $request = app(DeletionRequestWorkflowService::class)->submit($record, (int) Auth::id(), $reason);

            self::sendNotification(Notification::make()
                ->title('Delete request created')
                ->body('Delete approvals: ' . $request->approvalCount() . '/2.')
                ->warning()
            );
        } catch (\Throwable $e) {
            self::sendNotification(Notification::make()
                ->title('Delete request not created')
                ->body($e->getMessage())
                ->danger()
            );
        }
    }

    public static function approveDeletion(Product $record): void
    {
        try {
            $result = app(DeletionRequestWorkflowService::class)->approve($record, (int) Auth::id());
            /** @var DeletionRequest $request */
            $request = $result['request'];

            self::sendNotification(Notification::make()
                ->title($result['queued'] ? 'Delete approved and queued' : 'Delete approval recorded')
                ->body($result['queued']
                    ? 'Two delete approvals were recorded. Shopify and local deletion are now queued.'
                    : 'Delete approvals: ' . $request->approvalCount() . '/2.')
                ->warning()
            );
        } catch (\Throwable $e) {
            self::sendNotification(Notification::make()
                ->title('Delete approval failed')
                ->body($e->getMessage())
                ->danger()
            );
        }
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

    public static function applyMissingSeoInformationFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $sub): void {
            $sub->where(function (Builder $seo): void {
                $seo->whereNull('seo_title')
                    ->orWhere('seo_title', '');
            })->where(function (Builder $seo): void {
                $seo->whereNull('seo_description')
                    ->orWhere('seo_description', '');
            });
        });
    }

    private static function sortProductsByApprovalCount(Builder $query, string $direction): Builder
    {
        return $query->orderBy(
            Approval::query()
                ->selectRaw('COUNT(DISTINCT user_id)')
                ->whereColumn('approvals.product_id', 'products.id')
                ->whereColumn('approvals.approval_version', 'products.approval_version'),
            $direction
        );
    }

    private static function sortProductsByThumbnail(Builder $query, string $direction): Builder
    {
        return $query->orderBy(
            Image::query()
                ->select('src')
                ->whereColumn('images.product_id', 'products.id')
                ->orderByRaw('COALESCE(position, 2147483647)')
                ->limit(1),
            $direction
        );
    }

    private static function sortProductsByShopifyPrimaryHeader(Builder $query, string $header, string $direction): Builder
    {
        return $query->orderBy(
            ShopifyRow::query()
                ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(data, ?))', [self::jsonPathForHeader($header)])
                ->whereColumn('shopify_rows.import_id', 'products.import_id')
                ->whereColumn('shopify_rows.handle', 'products.handle')
                ->where('row_type', 'product_primary')
                ->limit(1),
            $direction
        );
    }

    private static function jsonPathForHeader(string $header): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $header);

        return '$."' . $escaped . '"';
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

    /**
     * @return array<string, string>
     */
    private static function vendorOptionsForCollection(mixed $collection, mixed $currentValue = null): array
    {
        $expected = self::expectedVendorForCollection(is_string($collection) ? $collection : null);
        if ($expected !== null) {
            return self::withCurrentOption([$expected => $expected], $currentValue);
        }

        $options = Product::query()
            ->whereNotNull('vendor')
            ->where('vendor', '!=', '')
            ->distinct()
            ->orderBy('vendor')
            ->pluck('vendor', 'vendor')
            ->all();

        return self::withCurrentOption($options, $currentValue);
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

    /**
     * @return array<int, string>
     */
    private static function compactRichTextToolbarButtons(): array
    {
        return [
            'bold',
            'italic',
            'bulletList',
            'orderedList',
            'undo',
            'redo',
        ];
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

    private static function expectedVendorForCollection(?string $collection): ?string
    {
        return app(DropdownCollectionCatalog::class)->vendorForCollection($collection);
    }

    private static function vendorSelectionHint(mixed $collection, mixed $vendor): ?HtmlString
    {
        $collectionName = is_string($collection) ? trim($collection) : '';
        if ($collectionName === '') {
            return null;
        }

        $expected = self::expectedVendorForCollection($collectionName);
        if ($expected === null) {
            return null;
        }

        $current = self::nullIfEmpty($vendor);
        if ($current !== null && strcasecmp($current, $expected) !== 0) {
            return null;
        }

        return new HtmlString('<span class="text-gray-600">Expected vendor: ' . e($expected) . '.</span>');
    }

    private static function vendorMatchesCollectionRule(mixed $collection): \Closure
    {
        $expected = self::expectedVendorForCollection(is_string($collection) ? $collection : null);

        return function (string $attribute, $value, $fail) use ($expected): void {
            if ($expected === null) {
                return;
            }

            $vendor = self::nullIfEmpty($value);
            if ($vendor === null) {
                $fail("Expected vendor: {$expected}.");
                return;
            }

            if (strcasecmp($vendor, $expected) !== 0) {
                $fail("Expected vendor: {$expected}.");
            }
        };
    }

    /**
     * @param array<string, string> $options
     */
    private static function withCurrentOption(array $options, mixed $currentValue): array
    {
        $current = trim((string) ($currentValue ?? ''));
        if ($current === '') {
            return $options;
        }

        if (!array_key_exists($current, $options)) {
            $options[$current] = $current;
            ksort($options);
        }

        return $options;
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

    public static function applyNeedsTitleUpdateFilter(Builder $query): Builder
    {
        return $query
            ->whereNotNull('type')
            ->whereRaw("TRIM(type) != ''")
            ->where(function (Builder $sub): void {
                $sub->whereNull('title')
                    ->orWhereRaw("TRIM(title) = ''")
                    ->orWhere(function (Builder $missingMatch): void {
                        self::applyTitleTypeMissingQuery($missingMatch);
                    });
            });
    }

    public static function applyGoodTitleFilter(Builder $query): Builder
    {
        return $query
            ->whereNotNull('type')
            ->whereRaw("TRIM(type) != ''")
            ->whereNotNull('title')
            ->whereRaw("TRIM(title) != ''")
            ->where(function (Builder $sub): void {
                self::applyTitleTypeMatchesQuery($sub);
            });
    }

    private static function applyTitleTypeMatchesQuery(Builder $query): void
    {
        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) IN ('bracelet', 'bracelets')")
                ->where(function (Builder $title): void {
                    $title->whereRaw("LOWER(title) LIKE '%bracelet%'")
                        ->orWhereRaw("LOWER(title) LIKE '%bracelets%'");
                });
        });

        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) IN ('charm', 'charms')")
                ->where(function (Builder $title): void {
                    $title->whereRaw("LOWER(title) LIKE '%charm%'")
                        ->orWhereRaw("LOWER(title) LIKE '%charms%'");
                });
        });

        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) IN ('necklace', 'necklaces')")
                ->where(function (Builder $title): void {
                    $title->whereRaw("LOWER(title) LIKE '%necklace%'")
                        ->orWhereRaw("LOWER(title) LIKE '%necklaces%'");
                });
        });

        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) IN ('earring', 'earrings')")
                ->where(function (Builder $title): void {
                    $title->whereRaw("LOWER(title) LIKE '%earring%'")
                        ->orWhereRaw("LOWER(title) LIKE '%earrings%'");
                });
        });

        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) IN ('gift card', 'gift cards')")
                ->where(function (Builder $title): void {
                    $title->whereRaw("LOWER(title) LIKE '%gift card%'")
                        ->orWhereRaw("LOWER(title) LIKE '%gift cards%'");
                });
        });

        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) NOT IN ('bracelet', 'bracelets', 'charm', 'charms', 'necklace', 'necklaces', 'earring', 'earrings', 'gift card', 'gift cards')")
                ->whereRaw("LOWER(TRIM(title)) LIKE CONCAT('%', LOWER(TRIM(type)), '%')");
        });
    }

    private static function applyTitleTypeMissingQuery(Builder $query): void
    {
        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) IN ('bracelet', 'bracelets')")
                ->whereRaw("LOWER(title) NOT LIKE '%bracelet%'")
                ->whereRaw("LOWER(title) NOT LIKE '%bracelets%'");
        });

        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) IN ('charm', 'charms')")
                ->whereRaw("LOWER(title) NOT LIKE '%charm%'")
                ->whereRaw("LOWER(title) NOT LIKE '%charms%'");
        });

        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) IN ('necklace', 'necklaces')")
                ->whereRaw("LOWER(title) NOT LIKE '%necklace%'")
                ->whereRaw("LOWER(title) NOT LIKE '%necklaces%'");
        });

        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) IN ('earring', 'earrings')")
                ->whereRaw("LOWER(title) NOT LIKE '%earring%'")
                ->whereRaw("LOWER(title) NOT LIKE '%earrings%'");
        });

        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) IN ('gift card', 'gift cards')")
                ->whereRaw("LOWER(title) NOT LIKE '%gift card%'")
                ->whereRaw("LOWER(title) NOT LIKE '%gift cards%'");
        });

        $query->orWhere(function (Builder $sub): void {
            $sub->whereRaw("LOWER(TRIM(type)) NOT IN ('bracelet', 'bracelets', 'charm', 'charms', 'necklace', 'necklaces', 'earring', 'earrings', 'gift card', 'gift cards')")
                ->whereRaw("LOWER(TRIM(title)) NOT LIKE CONCAT('%', LOWER(TRIM(type)), '%')");
        });
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
