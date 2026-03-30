<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\NewProductDraftResource\RelationManagers;
use App\Filament\Resources\NewProductDraftResource\Pages;
use App\Models\Color;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\DropdownOption;
use App\Models\Tag;
use App\Models\Variant;
use App\Services\NewProductDraftAssignmentService;
use App\Services\CategoryTypeMap;
use App\Services\NewProductDraftCsvImporter;
use App\Services\HeaderStore;
use App\Services\TagNormalizer;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Group;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Illuminate\Support\HtmlString;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use App\Jobs\NewProductDraftShopifyCreateJob;
use App\Jobs\SendNewProductDraftAssignmentEmailJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;

class NewProductDraftResource extends Resource
{
    protected static ?string $model = NewProductDraft::class;
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationLabel = 'New Products';
    protected static ?int $navigationSort = 1;

    private static function defaultBatch(): string
    {
        return 'batch' . now()->format('Ymd');
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Grid::make(3)
                ->schema([
                    Group::make([
                        Section::make('Core')
                            ->schema([
                    Forms\Components\Grid::make(3)
                        ->schema([
                            TextInput::make('title')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(1),
                            TextInput::make('sku')
                                ->maxLength(255)
                                ->rules([
                                    function (?NewProductDraft $record) {
                                        return function (string $attribute, $value, $fail) use ($record): void {
                                            $sku = trim((string) $value);
                                            if ($sku === '') {
                                                return;
                                            }

                                            $draftQuery = NewProductDraft::query()->where('sku', $sku);
                                            if ($record) {
                                                $draftQuery->where('id', '!=', $record->id);
                                            }

                                            $variantQuery = Variant::query()->where('sku', $sku);
                                            if ($record && $record->handle) {
                                                $currentProductId = Product::query()
                                                    ->where('handle', $record->handle)
                                                    ->value('id');
                                                if ($currentProductId) {
                                                    $variantQuery->where('product_id', '!=', $currentProductId);
                                                }
                                            }

                                            if ($draftQuery->exists() || $variantQuery->exists()) {
                                                $fail('SKU must be unique across new products and existing products.');
                                            }
                                        };
                                    },
                                ])
                                ->afterStateHydrated(function (TextInput $component, ?NewProductDraft $record): void {
                                    if (!$record) {
                                        return;
                                    }

                                    $component->state(self::resolvedSkuForDraft($record));
                                })
                                ->columnSpan(1),
                            TextInput::make('handle')
                                ->maxLength(255)
                                ->disabled()
                                ->columnSpan(1),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(3)
                        ->schema([
                            Select::make('collection_filter')
                                ->label('Collection')
                                ->placeholder('Select option')
                                ->options(fn (): array => self::collectionOptions())
                                ->searchable()
                                ->preload()
                                ->reactive()
                                ->dehydrated(false)
                                ->afterStateHydrated(function (Select $component, ?NewProductDraft $record): void {
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
                            Select::make('vendor')
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
                            TextInput::make('material_cost')
                                ->label('Material Cost')
                                ->numeric()
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    if (!is_string($state)) {
                                        return;
                                    }
                                    $normalized = str_replace([' ', ','], ['', '.'], $state);
                                    $normalized = preg_replace('/[^0-9.]/', '', $normalized ?? '');
                                    if ($normalized === null) {
                                        return;
                                    }
                                    $parts = explode('.', $normalized);
                                    if (count($parts) > 2) {
                                        $normalized = array_shift($parts) . '.' . implode('', $parts);
                                    }
                                    if ($normalized !== $state) {
                                        $set('material_cost', $normalized);
                                    }
                                })
                                ->dehydrateStateUsing(function ($state) {
                                    if (!is_string($state)) {
                                        return $state;
                                    }
                                    $normalized = str_replace([' ', ','], ['', '.'], $state);
                                    $normalized = preg_replace('/[^0-9.]/', '', $normalized ?? '');
                                    if ($normalized === null) {
                                        return $state;
                                    }
                                    $parts = explode('.', $normalized);
                                    if (count($parts) > 2) {
                                        $normalized = array_shift($parts) . '.' . implode('', $parts);
                                    }
                                    return $normalized;
                                }),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(3)
                        ->schema([
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
                                        $set('product_category', $mapping['shopify_taxonomy_gid'] ?? $mapping['category']);
                                        $set('google_product_category', $mapping['google_product_category']);
                                    }
                                }),
                            Select::make('product_category')
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
                            TextInput::make('google_product_category')
                                ->label('Google Product Category')
                                ->disabled()
                                ->dehydrated(),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Textarea::make('body_html')
                                ->label('Description')
                                ->rows(3)
                                ->columnSpan(2),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Select::make('color_string')
                                ->label('Colors')
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
                                ->afterStateHydrated(function (Select $component, $state): void {
                                    if (! is_string($state) || trim($state) === '') {
                                        $component->state([]);
                                        return;
                                    }

                                    $normalized = str_replace(',', ';', $state);

                                    $component->state(
                                        array_values(array_filter(array_map('trim', explode(';', $normalized))))
                                    );
                                })
                                ->dehydrateStateUsing(function ($state) {
                                    $arr = is_array($state) ? $state : [];

                                    $clean = array_values(array_unique(array_filter(array_map(
                                        fn ($v) => trim((string) $v),
                                        $arr
                                    ))));

                                    return $clean ? implode('; ', $clean) : null;
                                }),
                            Select::make('tags')
                                ->label('Tags')
                                ->multiple()
                                ->searchable()
                                ->reactive()
                                ->preload()
                                ->options(fn () => Tag::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'name')
                                    ->all())
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
                        ])
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Textarea::make('materials_and_dimensions')
                                ->label('Materials and Dimensions')
                                ->rows(2),
                            Select::make('complementary_products')
                                ->label('Complementary products')
                                ->placeholder('Select products')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options(fn (Get $get): array => self::complementaryProductOptions(
                                    $get('complementary_products')
                                ))
                                ->afterStateHydrated(function (Select $component, $state): void {
                                    $component->state(self::parseComplementaryProductState($state));
                                })
                                ->dehydrateStateUsing(fn ($state): ?string => self::dehydrateComplementaryProductState($state)),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Select::make('jewelry_material')
                                ->label('Jewelry material')
                                ->helperText(fn (Get $get): ?HtmlString => self::invalidCollectionSelectionHint(
                                    $get,
                                    'jewelry_material',
                                    HeaderStore::JEWELRY_MATERIAL
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    HeaderStore::JEWELRY_MATERIAL,
                                    tags: self::filterTags($get, $get('vendor'), $get('type'))
                                ))
                                ->searchable()
                                ->reactive()
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, $value, $fail) use ($get): void {
                                        $invalid = self::invalidCollectionSelectionValues(
                                            $value,
                                            self::dropdownOptionsForHeader(
                                                HeaderStore::JEWELRY_MATERIAL,
                                                tags: self::filterTags($get, $get('vendor'), $get('type'))
                                            )
                                        );
                                        if (!empty($invalid)) {
                                            $fail('Invalid value(s) for selected collection: ' . implode('; ', $invalid));
                                        }
                                    },
                                ]),
                            Select::make('product_materials')
                                ->label('Product Materials')
                                ->helperText(fn (Get $get): ?HtmlString => self::invalidCollectionSelectionHint(
                                    $get,
                                    'product_materials',
                                    HeaderStore::PRODUCT_MATERIALS
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    HeaderStore::PRODUCT_MATERIALS,
                                    tags: self::filterTags($get, $get('vendor'), $get('type'))
                                ))
                                ->searchable()
                                ->reactive()
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, $value, $fail) use ($get): void {
                                        $invalid = self::invalidCollectionSelectionValues(
                                            $value,
                                            self::dropdownOptionsForHeader(
                                                HeaderStore::PRODUCT_MATERIALS,
                                                tags: self::filterTags($get, $get('vendor'), $get('type'))
                                            )
                                        );
                                        if (!empty($invalid)) {
                                            $fail('Invalid value(s) for selected collection: ' . implode('; ', $invalid));
                                        }
                                    },
                                ]),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(3)
                        ->schema([
                            Select::make('metal')
                                ->label('Metal')
                                ->helperText(fn (Get $get): ?HtmlString => self::invalidCollectionSelectionHint(
                                    $get,
                                    'metal',
                                    HeaderStore::PRODUCT_METALS
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    HeaderStore::PRODUCT_METALS,
                                    tags: self::filterTags($get, $get('vendor'), $get('type'))
                                ))
                                ->searchable()
                                ->reactive()
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, $value, $fail) use ($get): void {
                                        $invalid = self::invalidCollectionSelectionValues(
                                            $value,
                                            self::dropdownOptionsForHeader(
                                                HeaderStore::PRODUCT_METALS,
                                                tags: self::filterTags($get, $get('vendor'), $get('type'))
                                            )
                                        );
                                        if (!empty($invalid)) {
                                            $fail('Invalid value(s) for selected collection: ' . implode('; ', $invalid));
                                        }
                                    },
                                ])
                                ->afterStateHydrated(function (Select $component, $state): void {
                                    if (self::normalizeDesignAliasValue($state) === null) {
                                        $component->state(null);
                                    }
                                }),
                            Select::make('colour_style')
                                ->label('Pattern category')
                                ->helperText(fn (Get $get): ?HtmlString => self::invalidCollectionSelectionHint(
                                    $get,
                                    'colour_style',
                                    HeaderStore::PATTERN_CATEGORY
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    HeaderStore::PATTERN_CATEGORY,
                                    tags: self::filterTags($get, $get('vendor'), $get('type'))
                                ))
                                ->searchable()
                                ->reactive()
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, $value, $fail) use ($get): void {
                                        $invalid = self::invalidCollectionSelectionValues(
                                            $value,
                                            self::dropdownOptionsForHeader(
                                                HeaderStore::PATTERN_CATEGORY,
                                                tags: self::filterTags($get, $get('vendor'), $get('type'))
                                            )
                                        );
                                        if (!empty($invalid)) {
                                            $fail('Invalid value(s) for selected collection: ' . implode('; ', $invalid));
                                        }
                                    },
                                ])
                                ->afterStateHydrated(function (Select $component, $state): void {
                                    if (self::normalizeDesignAliasValue($state) === null) {
                                        $component->state(null);
                                    }
                                }),
                            Select::make('size')
                                ->label('Size')
                                ->helperText(fn (Get $get): ?HtmlString => self::invalidCollectionSelectionHint(
                                    $get,
                                    'size',
                                    HeaderStore::SIZE
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    HeaderStore::SIZE,
                                    tags: self::filterTags($get, $get('vendor'), $get('type'))
                                ))
                                ->searchable()
                                ->reactive()
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, $value, $fail) use ($get): void {
                                        $invalid = self::invalidCollectionSelectionValues(
                                            $value,
                                            self::dropdownOptionsForHeader(
                                                HeaderStore::SIZE,
                                                tags: self::filterTags($get, $get('vendor'), $get('type'))
                                            )
                                        );
                                        if (!empty($invalid)) {
                                            $fail('Invalid value(s) for selected collection: ' . implode('; ', $invalid));
                                        }
                                    },
                                ])
                                ->afterStateHydrated(function (Select $component, $state): void {
                                    if (self::normalizeDesignAliasValue($state) === null) {
                                        $component->state(null);
                                    }
                                }),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            TextInput::make('siblings')
                                ->label('Siblings'),
                            Select::make('siblings_collection_name')
                                ->label('Siblings Collection Name')
                                ->placeholder('Select option')
                                ->searchable()
                                ->options(fn (Get $get): array => self::siblingsCollectionNameOptions(
                                    is_string($get('siblings_collection_name')) ? $get('siblings_collection_name') : null
                                )),
                        ])
                        ->columnSpanFull(),
                            ])->columns(2),
                        Section::make('Variant Defaults')
                            ->schema([
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        TextInput::make('variant_price')
                                            ->label('Price')
                                            ->numeric()
                                            ->afterStateHydrated(function (TextInput $component, $state, ?NewProductDraft $record): void {
                                                if ($record === null || $state !== null) {
                                                    return;
                                                }
                                                $defaults = self::resolvedVariantDefaultsForDraft($record);
                                                if ($defaults['variant_price'] !== null) {
                                                    $component->state($defaults['variant_price']);
                                                }
                                            }),
                                        TextInput::make('variant_compare_at_price')
                                            ->label('Compare-at price')
                                            ->numeric()
                                            ->afterStateHydrated(function (TextInput $component, $state, ?NewProductDraft $record): void {
                                                if ($record === null || $state !== null) {
                                                    return;
                                                }
                                                $defaults = self::resolvedVariantDefaultsForDraft($record);
                                                if ($defaults['variant_compare_at_price'] !== null) {
                                                    $component->state($defaults['variant_compare_at_price']);
                                                }
                                            }),
                                        TextInput::make('variant_inventory_qty')
                                            ->label('Inventory')
                                            ->numeric()
                                            ->afterStateHydrated(function (TextInput $component, $state, ?NewProductDraft $record): void {
                                                if ($record === null || $state !== null) {
                                                    return;
                                                }
                                                $defaults = self::resolvedVariantDefaultsForDraft($record);
                                                if ($defaults['variant_inventory_qty'] !== null) {
                                                    $component->state($defaults['variant_inventory_qty']);
                                                }
                                            }),
                                    ])
                                    ->columnSpanFull(),
                            ]),
                    ])->columnSpan(2),
                    Section::make('Images')
                        ->schema([
                    Placeholder::make('image_preview')
                        ->label('Preview')
                        ->content(function (Get $get, ?NewProductDraft $record): HtmlString {
                            $url = self::resolvedDraftPreviewImageUrl($get, $record);

                            if (!$url) {
                                return new HtmlString('<div class="text-sm text-gray-500">No image selected.</div>');
                            }

                            $safeUrl = e($url);
                            $isImage = (bool) preg_match('/\\.(jpe?g|png|gif|webp|svg)(\\?.*)?$/i', $url);

                            if ($isImage) {
                                return new HtmlString(
                                    '<div style="width:100%;max-height:250px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
                                    . '<img src="' . $safeUrl . '" alt="Image preview" style="width:100%;height:250px;object-fit:cover;display:block;" />'
                                    . '</div>'
                                );
                            }

                            return new HtmlString(
                                '<div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
                                . '<iframe src="' . $safeUrl . '" style="width:100%;height:250px;border:0;"></iframe>'
                                . '</div>'
                            );
                        }),
                    Forms\Components\FileUpload::make('image_path')
                        ->label('Primary Image')
                                ->dehydrated(fn (Get $get, ?NewProductDraft $record): bool => !self::draftImageLocked($get, $record))
                                ->disk('public')
                                ->directory('new-product-images')
                                ->preserveFilenames()
                                ->getUploadedFileNameForStorageUsing(function ($file, Forms\Get $get): string {
                                    $disk = Storage::disk('public');
                                    $directory = 'new-product-images';
                                    $original = $file->getClientOriginalName();
                                    $name = pathinfo($original, PATHINFO_FILENAME);
                                    $ext = strtolower($file->getClientOriginalExtension());
                                    $ext = $ext !== '' ? ".{$ext}" : '';

                                    $candidate = "{$name}{$ext}";
                                    $path = "{$directory}/{$candidate}";
                                    $suffix = 1;

                                    while ($disk->exists($path)) {
                                        $candidate = "{$name}-{$suffix}{$ext}";
                                        $path = "{$directory}/{$candidate}";
                                        $suffix++;
                                    }

                                    return $candidate;
                                })
                                ->image()
                                ->imageEditor()
                                ->maxSize(5120)
                                ->helperText('Optional. Uploaded image will be used for Shopify creation.')
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    if ($state) {
                                        $set('image_url', null);
                                    }
                                })
                                ->visible(fn (Get $get, ?NewProductDraft $record): bool => !self::draftImageLocked($get, $record) && blank($get('image_url'))),
                    TextInput::make('image_url')
                        ->label('Product Image URL')
                        ->placeholder('https://...')
                        ->dehydrated(fn (Get $get, ?NewProductDraft $record): bool => !self::draftImageLocked($get, $record))
                        ->helperText('Use a direct image URL (not a product page).')
                        ->afterStateUpdated(function ($state, callable $set): void {
                            if (is_string($state) && trim($state) !== '') {
                                $set('image_path', null);
                            }
                        })
                        ->visible(fn (Get $get, ?NewProductDraft $record): bool => !self::draftImageLocked($get, $record) && blank($get('image_path'))),
                    Placeholder::make('image_locked_notice')
                        ->label('')
                        ->content(function (Get $get, ?NewProductDraft $record): ?string {
                            if (!self::draftImageLocked($get, $record)) {
                                return null;
                            }

                            return 'This product already exists. Image is read-only here and synced product images take priority.';
                        })
                        ->visible(fn (Get $get, ?NewProductDraft $record): bool => self::draftImageLocked($get, $record)),
                    Select::make('product_design')
                        ->label('Product design')
                        ->placeholder('Select option')
                        ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                            HeaderStore::BRACELET_DESIGN,
                            tags: self::filterTags($get, $get('vendor'), $get('type'))
                        ))
                        ->searchable()
                        ->reactive(),
                    Select::make('status')
                        ->options([
                            'draft' => 'draft',
                            'active' => 'active',
                            'archived' => 'archived',
                        ])
                        ->default('draft')
                        ->required(),
                    Select::make('published')
                        ->options([
                            'true' => 'true',
                            'false' => 'false',
                        ])
                        ->default('false')
                        ->required(),
                    RichEditor::make('uvp_short_paragraph')
                        ->label('UVP Short Paragraph')
                        ->toolbarButtons([
                            'bold',
                        ]),
                    TextInput::make('batch')
                        ->label('Batch')
                        ->default(fn () => self::defaultBatch()),
                ])
                ->columnSpan(1),
                ])
                ->columnSpanFull(),
        ]);
    }

    private static function collectionOptions(): array
    {
        $collections = DropdownOption::query()
            ->whereNotNull('collection_style')
            ->where('collection_style', '!=', '')
            ->distinct()
            ->orderBy('collection_style')
            ->pluck('collection_style')
            ->all();

        return array_combine($collections, $collections) ?: [];
    }

    private static function collectionFromTags(?string $tags): ?string
    {
        if (!$tags) {
            return null;
        }

        $normalized = self::normalizeTagList($tags);
        if (empty($normalized)) {
            return null;
        }

        return DropdownOption::query()
            ->whereIn('collection_tag_primary', $normalized)
            ->where(function ($query) use ($normalized) {
                $query->whereIn('collection_tag_secondary', $normalized)
                    ->orWhereNull('collection_tag_secondary');
            })
            ->orderBy('collection_style')
            ->value('collection_style');
    }

    private static function collectionTags(?string $collection): array
    {
        if ($collection === null || trim($collection) === '') {
            return [];
        }

        $rows = DropdownOption::query()
            ->where('collection_style', $collection)
            ->whereNotNull('collection_tag_primary')
            ->orderBy('collection_tag_primary')
            ->get(['collection_tag_primary', 'collection_tag_secondary']);

        $tags = [];
        foreach ($rows as $row) {
            if ($row->collection_tag_primary) {
                $tags[] = $row->collection_tag_primary;
            }
            if ($row->collection_tag_secondary) {
                $tags[] = $row->collection_tag_secondary;
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $tags))));
    }

    private static function allCollectionTags(): array
    {
        $primary = DropdownOption::query()
            ->whereNotNull('collection_tag_primary')
            ->where('collection_tag_primary', '!=', '')
            ->pluck('collection_tag_primary')
            ->all();

        $secondary = DropdownOption::query()
            ->whereNotNull('collection_tag_secondary')
            ->where('collection_tag_secondary', '!=', '')
            ->pluck('collection_tag_secondary')
            ->all();

        return array_values(array_unique(array_filter(array_merge($primary, $secondary))));
    }

    private static function normalizeTagList(mixed $tags): array
    {
        if (is_array($tags)) {
            return array_values(array_filter(array_map('trim', $tags)));
        }

        if (is_string($tags) && trim($tags) !== '') {
            return TagNormalizer::parseTokens($tags);
        }

        return [];
    }

    private static function resolvedSkuForDraft(NewProductDraft $record): ?string
    {
        $variantSku = null;
        if ($record->handle) {
            $variantSku = Product::query()
                ->where('handle', $record->handle)
                ->first()?->variants()
                ->orderBy('id')
                ->value('sku');
        }

        $resolved = trim((string) ($variantSku ?? $record->sku ?? ''));
        return $resolved === '' ? null : $resolved;
    }

    /**
     * @return array{variant_price:?string,variant_compare_at_price:?string,variant_inventory_qty:?int}
     */
    private static function resolvedVariantDefaultsForDraft(NewProductDraft $record): array
    {
        $variant = null;
        if ($record->handle) {
            $variant = Product::query()
                ->where('handle', $record->handle)
                ->first()?->variants()
                ->orderBy('id')
                ->first();
        }

        return [
            'variant_price' => $variant?->price !== null ? (string) $variant->price : null,
            'variant_compare_at_price' => $variant?->compare_at_price !== null ? (string) $variant->compare_at_price : null,
            'variant_inventory_qty' => $variant?->inventory_qty !== null ? (int) $variant->inventory_qty : null,
        ];
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

    /**
     * @param array<string, string> $options
     * @return array<int, string>
     */
    private static function invalidCollectionSelectionValues(mixed $value, array $options): array
    {
        $selected = self::normalizeSelectedOptionTokens($value);
        if (empty($selected)) {
            return [];
        }

        $allowed = [];
        foreach (array_keys($options) as $key) {
            $normalized = strtolower(trim((string) $key));
            if ($normalized !== '') {
                $allowed[$normalized] = true;
            }
        }

        $invalid = [];
        foreach ($selected as $token) {
            $normalized = strtolower(trim($token));
            if ($normalized === '') {
                continue;
            }
            if (!isset($allowed[$normalized])) {
                $invalid[] = $token;
            }
        }

        return array_values(array_unique($invalid));
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeSelectedOptionTokens(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn (mixed $item): string => trim((string) $item),
                $value
            ), fn (string $item): bool => $item !== ''));
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        $separator = str_contains($raw, ';') ? ';' : ',';
        return array_values(array_filter(array_map(
            fn (string $item): string => trim($item),
            explode($separator, $raw)
        ), fn (string $item): bool => $item !== ''));
    }

    private static function invalidCollectionSelectionHint(Get $get, string $field, string $header): ?HtmlString
    {
        $value = $get($field);
        $invalid = self::invalidCollectionSelectionValues(
            $value,
            self::dropdownOptionsForHeader(
                $header,
                tags: self::filterTags($get, $get('vendor'), $get('type'))
            )
        );

        if (empty($invalid)) {
            return null;
        }

        $message = 'Invalid value(s) for selected collection: ' . implode('; ', $invalid)
            . '. Remove them or choose values available for this collection.';

        return new HtmlString('<span class="text-danger-600">' . e($message) . '</span>');
    }

    /**
     * @return array<string, string>
     */
    private static function complementaryProductOptions(mixed $currentValue = null): array
    {
        $products = Product::query()
            ->whereNotNull('shopify_id')
            ->where('shopify_id', '!=', '')
            ->orderBy('title')
            ->orderBy('handle')
            ->get(['shopify_id', 'title', 'handle']);

        $options = [];
        foreach ($products as $product) {
            $gid = trim((string) $product->shopify_id);
            if ($gid === '') {
                continue;
            }

            $title = trim((string) $product->title);
            $handle = trim((string) $product->handle);
            $label = $title !== '' ? $title : ($handle !== '' ? $handle : $gid);
            if ($handle !== '' && strcasecmp($label, $handle) !== 0) {
                $label .= " ({$handle})";
            }
            $options[$gid] = $label;
        }

        $selected = self::parseComplementaryProductState($currentValue);
        foreach ($selected as $gid) {
            if (!isset($options[$gid])) {
                $options[$gid] = $gid;
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function parseComplementaryProductState(mixed $state): array
    {
        if (is_array($state)) {
            return array_values(array_filter(array_map(
                fn (mixed $item): string => trim((string) $item),
                $state
            ), fn (string $item): bool => $item !== ''));
        }

        $raw = trim((string) $state);
        if ($raw === '') {
            return [];
        }

        if (str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return self::parseComplementaryProductState($decoded);
            }
        }

        $parts = str_contains($raw, ';')
            ? explode(';', $raw)
            : explode(',', $raw);

        return array_values(array_filter(array_map(
            fn (string $item): string => trim($item),
            $parts
        ), fn (string $item): bool => $item !== ''));
    }

    private static function dehydrateComplementaryProductState(mixed $state): ?string
    {
        $tokens = self::parseComplementaryProductState($state);
        if (empty($tokens)) {
            return null;
        }

        $tokens = array_values(array_unique($tokens));
        return implode('; ', $tokens);
    }

    private static function complementaryProductsAsLabels(?string $value): string
    {
        $tokens = self::parseComplementaryProductState($value);
        if (empty($tokens)) {
            return '';
        }

        $labelsByGid = self::complementaryProductOptions();
        $labels = array_map(
            fn (string $gid): string => $labelsByGid[$gid] ?? $gid,
            $tokens
        );

        return implode('; ', $labels);
    }

    private static function siblingsCollectionNameOptions(?string $currentValue = null): array
    {
        $titles = ShopifyCollection::query()
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->orderBy('title')
            ->pluck('title')
            ->all();

        $titles = array_values(array_unique(array_filter(array_map(
            fn (mixed $value): string => trim((string) $value),
            $titles
        ))));

        $current = trim((string) ($currentValue ?? ''));
        if ($current !== '' && !in_array($current, $titles, true)) {
            $titles[] = $current;
            sort($titles);
        }

        return array_combine($titles, $titles) ?: [];
    }

    private static function normalizeDesignAliasValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $lower = strtolower($normalized);
        if (in_array($lower, ['non applicable', 'not applicable', 'n/a', 'na'], true)) {
            return null;
        }

        return $normalized;
    }

    private static function filterTags(Get $get, ?string $vendor = null, ?string $productType = null): array
    {
        $collection = $get('collection_filter');
        if ($collection) {
            $tags = self::collectionTags($collection);
            if (!empty($tags)) {
                return $tags;
            }
        }

        $rawTags = $get('tags');
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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with([
                'product:id,handle,has_errors,error_fields',
                'product.images:id,product_id,src,position',
            ]))
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->square()
                    ->size(40)
                    ->state(fn (NewProductDraft $record) => self::draftDisplayImageUrl($record))
                    ->toggleable(),
                TextColumn::make('handle')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('handle', 'like', "%{$search}%")
                            ->orWhere('title', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->orWhereHas('product.variants', fn (Builder $variantQuery) => $variantQuery->where('sku', 'like', "%{$search}%"));
                    })
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('color_string')
                    ->label('Colors')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('jewelry_material')
                    ->label('Jewelry material')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('material_cost')
                    ->label('Cost per item')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('linked_product_errors')
                    ->label('Errors')
                    ->icon(fn (bool $state): string => $state ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (bool $state): string => $state ? 'danger' : 'success')
                    ->state(fn (NewProductDraft $record): bool => self::draftHasLinkedProductErrors($record))
                    ->toggleable(),
                TextColumn::make('linked_product_error_fields')
                    ->label('Error fields')
                    ->color(fn (NewProductDraft $record): string => self::draftHasLinkedProductErrors($record) ? 'danger' : 'gray')
                    ->state(fn (NewProductDraft $record): string => self::draftErrorFieldsSummary($record))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('type')->label('Type')->toggleable(),
                TextColumn::make('vendor')->toggleable(),
                IconColumn::make('published')
                    ->label('Published')
                    ->boolean()
                    ->state(fn (NewProductDraft $record): bool => filter_var($record->published, FILTER_VALIDATE_BOOLEAN))
                    ->toggleable(),
                TextColumn::make('batch')
                    ->label('Batch')
                    ->toggleable(),
                TextColumn::make('sku')
                    ->state(fn (NewProductDraft $record): ?string => self::resolvedSkuForDraft($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_category')
                    ->label('Product Category')
                    ->formatStateUsing(fn ($state): string => (string) (
                        CategoryTypeMap::categoryLabelForValue(is_string($state) ? $state : null) ?? $state ?? ''
                    ))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('google_product_category')
                    ->label('Google Product Category')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tags')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('body_html')
                    ->label('Description')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variant_price')
                    ->label('Price')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variant_compare_at_price')
                    ->label('Compare-at')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variant_inventory_qty')
                    ->label('Inventory')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_materials')
                    ->label('Product Materials')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('materials_and_dimensions')
                    ->label('Materials and Dimensions')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_design')
                    ->label('Product design')
                    ->formatStateUsing(fn ($state): string => self::normalizeDesignAliasValue($state) ?? '')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('metal')
                    ->label('Metal')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('colour_style')
                    ->label('Pattern category')
                    ->formatStateUsing(fn ($state): string => self::normalizeDesignAliasValue($state) ?? '')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('size')
                    ->label('Size')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('siblings')
                    ->label('Siblings')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('siblings_collection_name')
                    ->label('Siblings Collection Name')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uvp_short_paragraph')
                    ->label('UVP Short Paragraph')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('complementary_products')
                    ->label('Complementary products')
                    ->formatStateUsing(fn (?string $state): string => self::complementaryProductsAsLabels($state))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->color('warning'),
                Tables\Actions\DeleteAction::make()->color('danger'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Create Draft')
                    ->color('success'),
                Tables\Actions\Action::make('downloadTemplate')
                    ->label('Download Template')
                    ->color('gray')
                    ->outlined()
                    ->action(function (): void {
                        $headers = self::templateHeaders();
                        $writer = Writer::createFromFileObject(new SplTempFileObject());
                        $writer->insertOne($headers);

                        $timestamp = now()->format('Ymd_His');
                        $name = "new_products_template_{$timestamp}.csv";
                        $disk = Storage::disk('public');
                        $disk->put("template/{$name}", $writer->toString());
                        $url = $disk->url("template/{$name}");

                        Notification::make()
                            ->title('Template ready')
                            ->body("Saved to public/template/{$name}")
                            ->success()
                            ->actions([
                                \Filament\Notifications\Actions\Action::make('download')
                                    ->label('Download')
                                    ->url($url, shouldOpenInNewTab: true),
                            ])
                            ->send();
                    }),
                Tables\Actions\Action::make('importCsv')
                    ->label('Import CSV')
                    ->color('info')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('CSV File')
                            ->required()
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel']),
                    ])
                    ->action(function (array $data, NewProductDraftCsvImporter $importer): void {
                        $path = Storage::disk('local')->path($data['file']);
                        $result = $importer->importFromPath($path);

                        Notification::make()
                            ->title('Import complete')
                            ->body(
                                "Total: {$result['total']}, Created: {$result['created']}, " .
                                "Updated: {$result['updated']}, SEO Drafts: {$result['seo_drafts_upserted']}, " .
                                "Missing handle: {$result['skipped_missing_handle']}, " .
                                "Duplicate SKU: {$result['skipped_duplicate_sku']}"
                            )
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('mergeToProducts')
                        ->label('Create In Shopify')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $draftIds = $records->pluck('id')->all();
                            if (empty($draftIds)) {
                                Notification::make()
                                    ->title('Nothing to queue')
                                    ->body('No drafts selected.')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            NewProductDraftShopifyCreateJob::dispatch($draftIds, Auth::id());

                            Notification::make()
                                ->title('Shopify create queued')
                                ->body('The background job has been queued. You will be notified when it finishes.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('sendAssignmentEmail')
                        ->label('Send Columns by Email')
                        ->icon('heroicon-o-envelope')
                        ->color('info')
                        ->form([
                            Textarea::make('to_emails')
                                ->label('To')
                                ->required()
                                ->rows(2)
                                ->helperText('Separate multiple email addresses with commas, semicolons, or new lines.'),
                            Textarea::make('cc_emails')
                                ->label('CC')
                                ->rows(2)
                                ->helperText('Optional. Separate multiple email addresses with commas, semicolons, or new lines.'),
                            TextInput::make('from_name')
                                ->label('From Name')
                                ->default(fn (): ?string => Auth::user()?->name),
                            TextInput::make('from_email')
                                ->label('From Email')
                                ->email()
                                ->required()
                                ->default(fn (): ?string => Auth::user()?->email),
                            TextInput::make('subject')
                                ->label('Subject')
                                ->required()
                                ->maxLength(255)
                                ->default('New product draft assignment'),
                            Textarea::make('body')
                                ->label('Message')
                                ->rows(4)
                                ->helperText('Optional note shown in the email body.'),
                            CheckboxList::make('context_columns')
                                ->label('Reference Columns')
                                ->options(fn (): array => app(NewProductDraftAssignmentService::class)->contextColumnOptions())
                                ->columns(2)
                                ->default(['title', 'sku', 'vendor', 'type'])
                                ->helperText('Handle is always included as the identifier.'),
                            CheckboxList::make('selected_columns')
                                ->label('Work Columns')
                                ->required()
                                ->options(fn (): array => app(NewProductDraftAssignmentService::class)->workColumnOptions())
                                ->columns(2)
                                ->helperText('Choose the columns the recipient should work on.'),
                        ])
                        ->action(function ($records, array $data, NewProductDraftAssignmentService $service): void {
                            try {
                                $assignment = $service->createAssignment($records, $data, Auth::user());
                                SendNewProductDraftAssignmentEmailJob::dispatch($assignment->id);

                                Notification::make()
                                    ->title('Assignment queued')
                                    ->body("Assignment #{$assignment->id} was recorded and the email has been queued.")
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('Assignment failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(fn () => NewProductDraft::query()
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
                    ->options(fn () => NewProductDraft::query()
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
                TernaryFilter::make('has_errors')
                    ->label('Errors')
                    ->queries(
                        true: fn ($query) => $query->whereHas('product', fn ($productQuery) => $productQuery->where('has_errors', true)),
                        false: fn ($query) => $query->where(function ($subQuery) {
                            $subQuery->whereHas('product', fn ($productQuery) => $productQuery->where('has_errors', false))
                                ->orWhereDoesntHave('product');
                        }),
                    ),
            ]);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNewProductDrafts::route('/'),
            'create' => Pages\CreateNewProductDraft::route('/create'),
            'edit' => Pages\EditNewProductDraft::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\StyleProfileRelationManager::class,
        ];
    }

    private static function draftHasLinkedProductErrors(NewProductDraft $record): bool
    {
        return (bool) ($record->product?->has_errors ?? false);
    }

    private static function draftDisplayImageUrl(NewProductDraft $record): ?string
    {
        $product = $record->relationLoaded('product') ? $record->product : $record->product()->with('images')->first();
        $productImage = $product?->images
            ?->sortBy(fn ($image) => $image->position ?? PHP_INT_MAX)
            ->first()?->src;

        return $productImage ?: $record->imageUrl();
    }

    private static function draftErrorFieldsSummary(NewProductDraft $record): string
    {
        $fields = $record->product?->error_fields;

        if (is_array($fields)) {
            return empty($fields) ? 'All required fields are good.' : implode(', ', $fields);
        }

        $value = trim((string) $fields);
        return $value === '' ? 'All required fields are good.' : $value;
    }

    private static function draftImageLocked(Get $get, ?NewProductDraft $record): bool
    {
        return self::linkedProductExists($get, $record);
    }

    private static function resolvedDraftPreviewImageUrl(Get $get, ?NewProductDraft $record): ?string
    {
        $productImage = self::linkedProductFirstImageUrl($get, $record);
        if ($productImage) {
            return $productImage;
        }

        $imageUrl = $get('image_url');
        if (is_string($imageUrl) && trim($imageUrl) !== '') {
            return trim($imageUrl);
        }

        $imagePath = $get('image_path');
        if (is_string($imagePath) && trim($imagePath) !== '') {
            return Storage::disk('public')->url($imagePath);
        }

        return $record?->imageUrl();
    }

    private static function linkedProductHasImages(Get $get, ?NewProductDraft $record): bool
    {
        return self::linkedProductFirstImageUrl($get, $record) !== null;
    }

    private static function linkedProductExists(Get $get, ?NewProductDraft $record): bool
    {
        $handle = trim((string) ($get('handle') ?? $record?->handle ?? ''));
        if ($handle === '') {
            return false;
        }

        return Product::query()
            ->where('handle', $handle)
            ->exists();
    }

    private static function linkedProductFirstImageUrl(Get $get, ?NewProductDraft $record): ?string
    {
        $handle = trim((string) ($get('handle') ?? $record?->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        $product = Product::query()
            ->where('handle', $handle)
            ->with(['images' => fn ($query) => $query->orderBy('position')])
            ->first();

        $src = $product?->images->first()?->src;
        $src = is_string($src) ? trim($src) : '';

        return $src !== '' ? $src : null;
    }

    private static function templateHeaders(): array
    {
        $templatePath = self::newProductsTemplatePath();
        if ($templatePath && is_file($templatePath)) {
            $csv = Reader::createFromPath($templatePath);
            $csv->setHeaderOffset(0);
            $headers = $csv->getHeader();
            if (!empty($headers)) {
                $withHandle = array_merge(['Handle', 'SKU'], $headers);
                return array_values(array_unique($withHandle));
            }
        }

        return [
            'Handle',
            'SKU',
            'Title',
            'Description',
            'Product Image (Add location)',
            'Lifestyle Image (Add location)',
            'Price',
            'Compare-at price (Stricked Out Price)',
            'Material Cost (Use 19.00 not 19,00)',
            'Inventory (available in stock)',
            'Color',
            'Jewelry material',
            'Propduct Materials (New Metafield)',
            'Materials and Dimensions',
            'Product design (beaded, ...)',
            'Metal',
            'Colour Style (solid / multicolor)',
            'Collection (Livi Road, Pata Pata,...)',
            'Product Category (Bracelets, Charms,...)',
            'Size',
            'Siblings (Add product siblings here)',
            'Siblings Collection Name',
            'UVP Short Paragraph',
            'Complementary products (Finish the Set, And Get One Free)',
        ];
    }

    private static function newProductsTemplatePath(): ?string
    {
        $templateDir = storage_path('app/public/template');
        $paths = glob($templateDir . '/*.csv') ?: [];
        $paths = array_values(array_filter($paths, function (string $path): bool {
            $name = strtolower(basename($path));
            return str_contains($name, 'new products template');
        }));

        if (empty($paths)) {
            return null;
        }

        usort($paths, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        return $paths[0] ?? null;
    }
}
