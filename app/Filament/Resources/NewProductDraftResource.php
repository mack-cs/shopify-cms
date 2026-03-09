<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\NewProductDraftResource\Pages;
use App\Models\NewProductDraft;
use App\Models\NewProductDraftApproval;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\ShopifyCollection;
use App\Models\StyleProfile;
use App\Models\DropdownOption;
use App\Models\Tag;
use App\Models\Variant;
use App\Services\CategoryTypeMap;
use App\Services\NewProductDraftCsvImporter;
use App\Services\HeaderStore;
use App\Services\TagNormalizer;
use Filament\Forms;
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
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use App\Jobs\NewProductDraftShopifyCreateJob;
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
                            $url = null;
                            $imagePath = $get('image_path');
                            $imageUrl = $get('image_url');

                                    if (is_string($imageUrl) && trim($imageUrl) !== '') {
                                        $url = trim($imageUrl);
                                    } elseif (is_string($imagePath) && trim($imagePath) !== '') {
                                        $url = Storage::disk('public')->url($imagePath);
                                    } elseif ($record) {
                                        $url = $record->imageUrl();
                                    }

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
                                ->visible(fn (Get $get): bool => blank($get('image_url'))),
                    TextInput::make('image_url')
                        ->label('Product Image URL')
                        ->placeholder('https://...')
                        ->helperText('Use a direct image URL (not a product page).')
                        ->afterStateUpdated(function ($state, callable $set): void {
                            if (is_string($state) && trim($state) !== '') {
                                $set('image_path', null);
                            }
                        })
                        ->visible(fn (Get $get): bool => blank($get('image_path'))),
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
            ->orderBy('collection_style')
            ->pluck('collection_style')
            ->all();

        return array_combine($collections, $collections);
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
            ->columns([
                ImageColumn::make('image_thumb')
                    ->label('')
                    ->circular()
                    ->size(32)
                    ->state(fn (NewProductDraft $record) => $record->imageUrl())
                    ->toggleable(),
                TextInputColumn::make('title')
                    ->rules(['required'])
                    ->searchable()
                    ->sortable()
                    ->placeholder('Title')
                    ->toggleable(),
                TextColumn::make('handle')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextInputColumn::make('sku')
                    ->state(fn (NewProductDraft $record): ?string => self::resolvedSkuForDraft($record))
                    ->rules([
                        function () {
                            return function (string $attribute, $value, $fail): void {
                                $sku = trim((string) $value);
                                if ($sku === '') {
                                    return;
                                }

                                $recordId = request()->input('componentData.0.id')
                                    ?? request()->input('componentData.0.recordId');
                                $recordId = is_numeric($recordId) ? (int) $recordId : null;

                                $draftQuery = NewProductDraft::query()->where('sku', $sku);
                                if ($recordId) {
                                    $draftQuery->where('id', '!=', $recordId);
                                }

                                $variantQuery = Variant::query()->where('sku', $sku);
                                if ($recordId) {
                                    $record = NewProductDraft::query()->find($recordId);
                                    if ($record?->handle) {
                                        $currentProductId = Product::query()
                                            ->where('handle', $record->handle)
                                            ->value('id');
                                        if ($currentProductId) {
                                            $variantQuery->where('product_id', '!=', $currentProductId);
                                        }
                                    }
                                }

                                if ($draftQuery->exists() || $variantQuery->exists()) {
                                    $fail('SKU must be unique across new products and existing products.');
                                }
                            };
                        },
                    ])
                    ->toggleable(),
                TextColumn::make('type')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextInputColumn::make('batch')
                    ->placeholder('batchYYYYMMDD')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vendor')
                    ->searchable()
                    ->toggleable(),
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
                TextColumn::make('color_string')
                    ->label('Colors')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('body_html')
                    ->label('Description')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('published')
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
                TextColumn::make('material_cost')
                    ->label('Material Cost')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('jewelry_material')
                    ->label('Jewelry material')
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
                TextColumn::make('approvals_current')
                    ->label('Approvals')
                    ->state(fn (NewProductDraft $record) => $record->approvalsForCurrentVersionCount())
                    ->toggleable(),
                IconColumn::make('approved')
                    ->label('Approved')
                    ->boolean(fn (NewProductDraft $record) => $record->isApprovedByTwo())
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->disabled(function (NewProductDraft $record): bool {
                        return NewProductDraftApproval::where('new_product_draft_id', $record->id)
                            ->where('user_id', Auth::id())
                            ->where('approval_version', $record->approval_version)
                            ->exists();
                    })
                    ->tooltip(function (NewProductDraft $record): ?string {
                        $approved = NewProductDraftApproval::where('new_product_draft_id', $record->id)
                            ->where('user_id', Auth::id())
                            ->where('approval_version', $record->approval_version)
                            ->exists();
                        return $approved ? 'Already approved by you' : 'Approve this draft';
                    })
                    ->extraAttributes(function (NewProductDraft $record): array {
                        $approved = NewProductDraftApproval::where('new_product_draft_id', $record->id)
                            ->where('user_id', Auth::id())
                            ->where('approval_version', $record->approval_version)
                            ->exists();
                        return ['title' => $approved ? 'Already approved by you' : 'Approve this draft'];
                    })
                    ->action(function (NewProductDraft $record): void {
                        NewProductDraftApproval::firstOrCreate([
                            'new_product_draft_id' => $record->id,
                            'user_id' => Auth::id(),
                            'approval_version' => $record->approval_version,
                        ]);

                        Notification::make()
                            ->title('Draft approved')
                            ->body("Approved \"{$record->title}\"")
                            ->success()
                            ->send();
                    }),
                Action::make('editStyle')
                    ->label('Style')
                    ->icon('heroicon-o-swatch')
                    ->color('info')
                    ->tooltip('Edit style profile')
                    ->extraAttributes(['title' => 'Edit style profile'])
                    ->visible(function (NewProductDraft $record): bool {
                        if (!$record->handle) {
                            return false;
                        }
                        return Product::where('handle', $record->handle)->exists();
                    })
                    ->modalHeading(function (NewProductDraft $record) {
                        return $record->title ? "Edit style for {$record->title}" : 'Edit style';
                    })
                    ->form([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('sku')
                                    ->maxLength(80)
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('style_type')
                                    ->label('Style')
                                    ->maxLength(120)
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->columnSpanFull(),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('product_colors')
                                    ->label('Colors')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('jewelry_material_display')
                                    ->label('Jewelry material')
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('materials')->maxLength(255),
                        Forms\Components\TextInput::make('components')->maxLength(255),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Textarea::make('colour_prompt')
                                    ->rows(4),
                                Forms\Components\Textarea::make('product_description')
                                    ->label('Description')
                                    ->rows(4)
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('draft_seo_title')
                            ->label('SEO Title')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('draft_seo_description')
                            ->label('SEO Description (160 chars)')
                            ->rows(2)
                            ->maxLength(160)
                            ->columnSpanFull(),
                    ])
                    ->fillForm(function (NewProductDraft $record): array {
                        $product = Product::where('handle', $record->handle)->with(['variants', 'images'])->first();
                        if (!$product) {
                            return [];
                        }

                        $styleProfile = StyleProfile::where('product_id', $product->id)->first();
                        $row = ShopifyRow::where('import_id', $product->import_id)
                            ->where('handle', $product->handle)
                            ->where('row_type', 'product_primary')
                            ->first();

                        $sku = trim((string) ($product->variants->first()?->sku ?? ''));
                        if ($sku === '') {
                            $sku = $product->handle;
                        }

                        $description = trim(strip_tags((string) ($product->body_html ?? '')));

                        return [
                            'sku' => $sku,
                            'style_type' => $product->type,
                            'product_colors' => $product->color_string,
                            'jewelry_material_display' => (string) ($row?->get(HeaderStore::JEWELRY_MATERIAL, '') ?? ''),
                            'materials' => $styleProfile?->materials,
                            'components' => $styleProfile?->components,
                            'colour_prompt' => $styleProfile?->colour_prompt,
                            'product_description' => $description,
                            'draft_seo_title' => $styleProfile?->draft_seo_title,
                            'draft_seo_description' => $styleProfile?->draft_seo_description,
                        ];
                    })
                    ->action(function (NewProductDraft $record, array $data): void {
                        $product = Product::where('handle', $record->handle)->with(['variants', 'images'])->first();
                        if (!$product) {
                            Notification::make()
                                ->title('Product not found')
                                ->body('Sync products before editing styles.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $styleProfile = StyleProfile::firstOrCreate(
                            ['product_id' => $product->id],
                            [
                                'handle' => $product->handle,
                                'sku' => trim((string) ($product->variants->first()?->sku ?? $product->handle)),
                            ]
                        );

                        $styleProfile->update([
                            'materials' => $data['materials'] ?? null,
                            'components' => $data['components'] ?? null,
                            'colour_prompt' => $data['colour_prompt'] ?? null,
                            'draft_seo_title' => $data['draft_seo_title'] ?? null,
                            'draft_seo_description' => $data['draft_seo_description'] ?? null,
                        ]);

                        Notification::make()
                            ->title('Style saved')
                            ->success()
                            ->send();
                    }),
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
                                "Updated: {$result['updated']}, Missing handle: {$result['skipped_missing_handle']}, " .
                                "Duplicate SKU: {$result['skipped_duplicate_sku']}"
                            )
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('bulkApprove')
                        ->label('Bulk Approve')
                        ->icon('heroicon-o-check-badge')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $approvedCount = 0;
                            $skippedCount = 0;

                            foreach ($records as $record) {
                                $exists = NewProductDraftApproval::where('new_product_draft_id', $record->id)
                                    ->where('user_id', Auth::id())
                                    ->where('approval_version', $record->approval_version)
                                    ->exists();

                                if ($exists) {
                                    $skippedCount++;
                                    continue;
                                }

                                NewProductDraftApproval::create([
                                    'new_product_draft_id' => $record->id,
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

                            Notification::make()
                                ->title('Bulk approval complete')
                                ->body($parts ? implode(' ', $parts) : 'No drafts were approved.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
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
                ]),
            ])
            ->filters([
                Filter::make('title_filter')
                    ->form([
                        TextInput::make('title')
                            ->label('Title'),
                    ])
                    ->query(function ($query, array $data) {
                        $title = trim((string) ($data['title'] ?? ''));
                        if ($title === '') {
                            return $query;
                        }
                        return $query->where('title', 'like', "%{$title}%");
                    }),
                Filter::make('sku_filter')
                    ->form([
                        TextInput::make('sku')
                            ->label('SKU'),
                    ])
                    ->query(function ($query, array $data) {
                        $sku = trim((string) ($data['sku'] ?? ''));
                        if ($sku === '') {
                            return $query;
                        }
                        return $query->where('sku', 'like', "%{$sku}%");
                    }),
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
