<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\NewProductDraftResource\RelationManagers;
use App\Filament\Resources\NewProductDraftResource\Pages;
use App\Models\ChangeLog;
use App\Models\Color;
use App\Models\DeletionRequest;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\NewProductDraftApproval;
use App\Models\Image;
use App\Models\Product;
use App\Models\RequiredField;
use App\Models\ShopifyAudit;
use App\Models\ShopifyCollection;
use App\Models\ShopifyRow;
use App\Models\Status;
use App\Models\Setting;
use App\Models\StyleProfile;
use App\Models\ProductPartialApprovalRequest;
use App\Models\SaleImportBatch;
use App\Models\SaleImportItem;
use App\Models\SaleProductUpdate;
use App\Models\DropdownOption;
use App\Models\Tag;
use App\Models\User;
use App\Models\Variant;
use App\Services\NewProductDraftAssignmentService;
use App\Services\AdminNotification;
use App\Services\AsyncJobStateService;
use App\Services\CategoryTypeMap;
use App\Services\DeletionRequestWorkflowService;
use App\Services\NewProductDraftCsvImporter;
use App\Services\NewProductDraftProductSync;
use App\Services\NewProductDraftRoundtripCsvService;
use App\Services\NewProductDraftStackAssociationImporter;
use App\Services\DropdownCollectionCatalog;
use App\Services\HeaderStore;
use App\Services\ShopifyMissingDraftWorkflowService;
use App\Services\TagNormalizer;
use App\Services\ComplementaryProductAuditService;
use App\Services\ProductPartialApprovalService;
use App\Services\SaleTagService;
use App\Services\SaleProductUpdateImporter;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use App\Jobs\NewProductDraftShopifyCreateJob;
use App\Jobs\SendNewProductDraftAssignmentEmailJob;
use App\Jobs\SendNewProductDraftAssignmentSlackJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Carbon\Carbon;

class NewProductDraftResource extends Resource
{
    protected static ?string $model = NewProductDraft::class;
    protected static bool $isScopedToTenant = false;
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationLabel = 'New Products';
    protected static ?int $navigationSort = 1;
    private static ?array $siblingCollectionLookupCache = null;
    private const SALE_TAG = 'sale';
    private const EXCLUDE_FROM_SALE_TAG = 'exclude-from-the-sale';
    private const DEFAULT_NEW_PRODUCT_TAGS = [
        'all-products-collection',
        'all-products',
    ];
    private const PRODUCT_TYPE_TAGS = [
        'anklet',
        'bracelet',
        'bundle',
        'bundles',
        'charm',
        'earring',
        'necklace',
        'ring',
        'stack',
        'stacks',
    ];
    private const DRAFT_VARIANT_CLASH_FIELDS = [
        'sku' => [
            'label' => 'SKU',
            'variant_attribute' => 'sku',
            'type' => 'string',
        ],
        'variant_price' => [
            'label' => 'Price',
            'variant_attribute' => 'price',
            'type' => 'decimal2',
        ],
        'variant_compare_at_price' => [
            'label' => 'Compare-at price',
            'variant_attribute' => 'compare_at_price',
            'type' => 'decimal2',
        ],
        'variant_inventory_qty' => [
            'label' => 'Inventory',
            'variant_attribute' => 'inventory_qty',
            'type' => 'integer',
        ],
    ];

    public static function getEloquentQuery(): Builder
    {
        return NewProductDraft::query()->select('new_product_drafts.*');
    }

    private static function defaultBatch(): string
    {
        return 'batch' . now()->format('Ymd');
    }

    public static function applyMissingSeoReportFilter(Builder $query): Builder
    {
        return self::applyWorkingDraftStatuses($query)
            ->where(function (Builder $sub): void {
                $sub->whereDoesntHave('styleProfiles', function (Builder $styleProfileQuery): void {
                    $styleProfileQuery->whereNotNull('draft_seo_title')
                        ->where('draft_seo_title', '!=', '');
                })->whereDoesntHave('product', function (Builder $productQuery): void {
                    $productQuery->whereNotNull('seo_title')
                        ->where('seo_title', '!=', '');
                });
            })
            ->where(function (Builder $sub): void {
                $sub->whereDoesntHave('styleProfiles', function (Builder $styleProfileQuery): void {
                    $styleProfileQuery->whereNotNull('draft_seo_description')
                        ->where('draft_seo_description', '!=', '');
                })->whereDoesntHave('product', function (Builder $productQuery): void {
                    $productQuery->whereNotNull('seo_description')
                        ->where('seo_description', '!=', '');
                });
            });
    }

    public static function applyMissingUvpReportFilter(Builder $query): Builder
    {
        return self::applyMissingDraftStringColumnReportFilter($query, 'uvp_short_paragraph');
    }

    public static function applyMissingSiblingsReportFilter(Builder $query): Builder
    {
        return self::applyMissingDraftStringColumnReportFilter($query, 'siblings');
    }

    public static function applyMissingComplementaryProductsReportFilter(Builder $query): Builder
    {
        return self::applyMissingDraftStringColumnReportFilter($query, 'complementary_products');
    }

    public static function applyMissingRelatedProductsReportFilter(Builder $query): Builder
    {
        return self::applyMissingComplementaryProductsReportFilter(
            self::applyMissingSiblingsReportFilter($query)
        );
    }

    public static function applyNeedsTitleUpdateFilter(Builder $query): Builder
    {
        return self::applyWorkingDraftStatuses($query)
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
        return self::applyWorkingDraftStatuses($query)
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

    private static function applyMissingDraftStringColumnReportFilter(Builder $query, string $column): Builder
    {
        return self::applyWorkingDraftStatuses($query)->where(function (Builder $sub) use ($column): void {
            $sub->whereNull($column)
                ->orWhere($column, '');
        });
    }

    public static function applyWorkingDraftStatuses(Builder $query): Builder
    {
        return $query
            ->whereIn(\DB::raw('LOWER(TRIM(COALESCE(status, "")))'), ['active', 'draft'])
            ->whereRaw('LOWER(COALESCE(title, "")) NOT LIKE ?', ['%test%']);
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Placeholder::make('draft_save_indicator')
                ->label('')
                ->content(new HtmlString(<<<'HTML'
                    <div
                        class="hidden items-start gap-3 rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-900 shadow-sm"
                        wire:loading.delay.flex
                        wire:target="create,save"
                    >
                        <svg class="mt-0.5 h-5 w-5 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="4"></circle>
                            <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                        </svg>
                        <div>
                            <div class="font-semibold">Saving draft</div>
                            <div class="mt-1">
                                Your product draft is being saved. Wait for the success message before leaving this page.
                            </div>
                        </div>
                    </div>
                HTML))
                ->columnSpanFull(),
            Placeholder::make('draft_edit_lock_status')
                ->label('')
                ->content(function ($livewire): HtmlString {
                    if (method_exists($livewire, 'editLockStatusHtml')) {
                        return $livewire->editLockStatusHtml();
                    }

                    return new HtmlString('');
                })
                ->extraAttributes([
                    'wire:poll.30s' => 'refreshEditorLockStatus',
                ])
                ->columnSpanFull(),
            Forms\Components\Tabs::make('NewProductDraftTabs')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Tabs\Tab::make('Details')
                        ->schema([
                            Forms\Components\Grid::make(3)
                                ->schema([
                    Group::make([
                        Section::make('Shopify Sync Warnings')
                            ->schema([
                                Placeholder::make('shopify_sync_warnings_blocking_notice')
                                    ->label('')
                                    ->content(fn (?NewProductDraft $record): ?HtmlString => self::shopifySyncWarningsBlockingHtml($record)),
                                Placeholder::make('shopify_sync_warnings_notice')
                                    ->label('')
                                    ->content(fn (?NewProductDraft $record): ?HtmlString => self::shopifySyncWarningsHtml($record)),
                                Actions::make([
                                    FormAction::make('useShopifyWarningValues')
                                        ->label('Use Shopify Values')
                                        ->icon('heroicon-o-arrow-down-tray')
                                        ->color('warning')
                                        ->requiresConfirmation()
                                        ->action(function (?NewProductDraft $record) {
                                            if (!$record) {
                                                return null;
                                            }

                                            $result = self::applyShopifyWarningValuesToDrafts([$record->fresh()]);

                                            self::sendNotification(Notification::make()
                                                ->title('Shopify values applied')
                                                ->body(self::warningResolutionSummary(
                                                    resolved: $result['updated'],
                                                    skipped: $result['skipped'],
                                                    extra: []
                                                ))
                                                ->success()
                                            );

                                            return redirect(self::getUrl('edit', ['record' => $record]));
                                        }),
                                    FormAction::make('keepDraftWarningValues')
                                        ->label('Keep Draft Values')
                                        ->icon('heroicon-o-arrow-up-tray')
                                        ->color('gray')
                                        ->requiresConfirmation()
                                        ->action(function (?NewProductDraft $record) {
                                            if (!$record) {
                                                return null;
                                            }

                                            $result = self::keepDraftWarningValues([$record->fresh()]);

                                            self::sendNotification(Notification::make()
                                                ->title('Draft values kept')
                                                ->body(self::warningResolutionSummary(
                                                    resolved: $result['cleared'],
                                                    skipped: $result['skipped'],
                                                    extra: $result['synced'] > 0 ? ["Synced {$result['synced']} back to Products."] : []
                                                ))
                                                ->success()
                                            );

                                            return redirect(self::getUrl('edit', ['record' => $record]));
                                        }),
                                ])->alignStart(),
                            ])
                            ->visible(fn (?NewProductDraft $record): bool => ($record?->shopifySyncWarningCount() ?? 0) > 0)
                            ->columnSpanFull(),
                        Section::make('Core')
                            ->schema([
                    Forms\Components\Grid::make(3)
                        ->schema([
                            TextInput::make('title')
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    $title = is_string($state) ? trim($state) : trim((string) ($state ?? ''));
                                    $set('siblings_collection_name', $title !== '' ? $title : null);
                                })
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

                                            $message = self::duplicateSkuMessageForDraft($sku, $record);
                                            if ($message !== null) {
                                                $fail($message);
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
                                    $current = $get('tags');
                                    $normalized = self::normalizeTagList($current);
                                    $isBundleContext = self::isBundleOrStackState(
                                        $get('type'),
                                        $normalized,
                                        $get('title')
                                    );

                                    $collectionTags = $isBundleContext
                                        ? self::bundleContextCollectionTags(is_string($state) ? $state : null)
                                        : self::collectionTags(is_string($state) ? $state : null);
                                    if ($collectionTags === []) {
                                        return;
                                    }

                                    $expectedVendor = self::expectedVendorForCollection(
                                        is_string($state) ? $state : null
                                    );
                                    if ($expectedVendor !== null) {
                                        $set('vendor', $expectedVendor);
                                    }

                                    $collectionPool = self::allCollectionTags();

                                    $kept = array_values(array_filter(
                                        $normalized,
                                        fn (string $tag): bool => !in_array($tag, $collectionPool, true)
                                    ));

                                    $merged = self::defaultedDraftTags(
                                        array_values(array_unique(array_merge($kept, $collectionTags))),
                                        $get('type'),
                                        self::saleStateFromForm($get('is_on_sale'), $get('tags'))
                                    );
                                    $set('tags', $merged);
                                }),
                            Select::make('vendor')
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
                                ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                                    if (!$state) {
                                        $set('product_category', null);
                                        $set('google_product_category', null);
                                        $set('tags', self::defaultedDraftTags(
                                            self::normalizeTagList($get('tags')),
                                            null,
                                            self::saleStateFromForm($get('is_on_sale'), $get('tags'))
                                        ));
                                        return;
                                    }

                                    $mapping = CategoryTypeMap::byType($state);
                                    if ($mapping) {
                                        $set('product_category', $mapping['shopify_taxonomy_gid'] ?? $mapping['category']);
                                        $set('google_product_category', $mapping['google_product_category']);
                                    }

                                    $set('tags', self::defaultedDraftTags(
                                        self::normalizeTagList($get('tags')),
                                        $state,
                                        self::saleStateFromForm($get('is_on_sale'), $get('tags'))
                                    ));
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
                                ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                                    if (!$state) {
                                        $set('type', null);
                                        $set('google_product_category', null);
                                        $set('tags', self::defaultedDraftTags(
                                            self::normalizeTagList($get('tags')),
                                            null,
                                            self::saleStateFromForm($get('is_on_sale'), $get('tags'))
                                        ));
                                        return;
                                    }

                                    $mapping = CategoryTypeMap::byCategoryValue(is_string($state) ? $state : null);
                                    if ($mapping) {
                                        $set('type', $mapping['type']);
                                        $set('google_product_category', $mapping['google_product_category']);
                                        $set('tags', self::defaultedDraftTags(
                                            self::normalizeTagList($get('tags')),
                                            $mapping['type'],
                                            self::saleStateFromForm($get('is_on_sale'), $get('tags'))
                                        ));
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
                            RichEditor::make('body_html')
                                ->label('Description')
                                ->columnSpan(2),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Select::make('color_string')
                                ->label('Colors')
                                ->helperText(fn (Get $get): ?HtmlString => self::colorSelectionHint($get))
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
                                    fn (Get $get): \Closure => function (string $attribute, $value, $fail) use ($get): void {
                                        $invalid = self::invalidCollectionSelectionValues(
                                            $value,
                                            self::dropdownOptionsForHeader(
                                                HeaderStore::COLOR_METAFIELD,
                                                vendor: $get('vendor'),
                                                productType: $get('type'),
                                                tags: self::filterTags($get, $get('vendor'), $get('type'))
                                            )
                                        );

                                        if (!empty($invalid)) {
                                            $fail('Invalid value(s) for selected collection: ' . implode('; ', $invalid));
                                        }
                                    },
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
                            Forms\Components\Toggle::make('is_on_sale')
                                ->label('Put product on sale')
                                ->default(false)
                                ->inline(false)
                                ->live()
                                ->helperText('Adds the sale tag, removes exclude-from-the-sale, locks compare-at price, and requires a lower sale price.')
                                ->afterStateHydrated(function (Forms\Components\Toggle $component, $state, ?NewProductDraft $record): void {
                                    $tags = self::normalizeTagList($record?->tags);
                                    $component->state((bool) $state || self::tagListContains($tags, self::SALE_TAG));
                                })
                                ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                                    $isOnSale = self::saleStateFromForm($state, null);

                                    $tags = self::defaultedDraftTags(
                                        self::normalizeTagList($get('tags')),
                                        $get('type'),
                                        $isOnSale
                                    );
                                    $set('tags', $tags);

                                    if (!$isOnSale) {
                                        return;
                                    }

                                    $currentPrice = self::decimalStringFromState($get('variant_price'));
                                    $compareAtPrice = self::decimalStringFromState($get('variant_compare_at_price'));

                                    if ($compareAtPrice === null && $currentPrice !== null) {
                                        $set('variant_compare_at_price', $currentPrice);
                                        $set('variant_price', null);
                                    }
                                }),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(2)
                        ->schema([

                            Select::make('siblings')
                                ->label('Siblings')
                                ->helperText(fn (Get $get): ?HtmlString => self::productReferenceStatusHint(
                                    $get,
                                    'siblings'
                                ))
                                ->placeholder('Select products')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options(fn (Get $get): array => self::productReferenceOptions(
                                    $get('siblings')
                                ))
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, $value, $fail): void {
                                        $invalid = self::invalidProductReferenceStatusLabels($value);
                                        if (!empty($invalid)) {
                                            $fail('Inactive products selected: ' . implode('; ', $invalid));
                                        }
                                    },
                                ])
                                ->afterStateHydrated(function (Select $component, $state): void {
                                    $component->state(self::parseProductReferenceState($state));
                                })
                                ->dehydrateStateUsing(fn ($state): ?string => self::dehydrateProductReferenceState($state)),
                            Select::make('complementary_products')
                                ->label('Complementary products')
                                ->helperText(fn (Get $get): ?HtmlString => self::productReferenceStatusHint(
                                    $get,
                                    'complementary_products'
                                ))
                                ->placeholder('Select products')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options(fn (Get $get): array => self::productReferenceOptions(
                                    $get('complementary_products')
                                ))
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, $value, $fail): void {
                                        $invalid = self::invalidProductReferenceStatusLabels($value);
                                        if (!empty($invalid)) {
                                            $fail('Inactive products selected: ' . implode('; ', $invalid));
                                        }

                                        if (!self::complementaryMinimumEnabled()) {
                                            return;
                                        }

                                        $selected = self::parseProductReferenceState($value);
                                        $minimum = self::complementaryMinimumCount();
                                        if (count($selected) < $minimum) {
                                            $fail("Select at least {$minimum} complementary products.");
                                        }
                                    },
                                ])
                                ->afterStateHydrated(function (Select $component, $state): void {
                                    $component->state(self::parseProductReferenceState($state));
                                })
                                ->dehydrateStateUsing(fn ($state): ?string => self::dehydrateProductReferenceState($state)),
                            Select::make('bundle_product_ids')
                                ->label('Associated products')
                                ->helperText('Select the single products that make up this bundle or stack. This is stored locally and does not change Shopify product references.')
                                ->placeholder('Select products')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->live()
                                ->options(fn (Get $get, ?NewProductDraft $record): array => self::bundleProductOptions(
                                    $get('bundle_product_ids'),
                                    $record
                                ))
                                ->visible(fn (Get $get, ?NewProductDraft $record): bool => self::shouldShowBundleAssociationField($get, $record))
                                ->afterStateHydrated(function (Select $component, $state): void {
                                    $component->state(self::normalizeBundleProductIds($state));
                                })
                                ->afterStateUpdated(function ($state, callable $set, Get $get, ?NewProductDraft $record): void {
                                    if (!self::shouldShowBundleImageTools($get, $record)) {
                                        return;
                                    }

                                    $allowed = array_keys(self::bundleProductImageOptions($state));
                                    $selected = array_values(array_intersect(
                                        self::normalizeBundleImageUrls($get('bundle_image_urls')),
                                        $allowed
                                    ));

                                    $set('bundle_image_urls', $selected);

                                    if ($selected !== [] && blank($get('image_path'))) {
                                        $set('image_url', $selected[0]);
                                    }
                                })
                                ->dehydrateStateUsing(fn ($state): ?array => self::nullableArray(self::normalizeBundleProductIds($state)))
                                ->columnSpanFull(),
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
                                ->multiple()
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
                                ->dehydrateStateUsing(function ($state): ?string {
                                    $arr = is_array($state) ? $state : [];
                                    $clean = array_values(array_unique(array_filter(array_map(
                                        fn ($v) => trim((string) $v),
                                        $arr
                                    ))));

                                    return $clean ? implode('; ', $clean) : null;
                                }),
                            Select::make('materials_and_dimensions')
                                ->label('Materials and Dimensions')
                                ->helperText(fn (Get $get): ?HtmlString => self::invalidCollectionSelectionHint(
                                    $get,
                                    'materials_and_dimensions',
                                    HeaderStore::MATERIALS_AND_DIMENSIONS
                                ))
                                ->placeholder('Select option')
                                ->options(fn (Get $get): array => self::dropdownOptionsForHeader(
                                    HeaderStore::MATERIALS_AND_DIMENSIONS,
                                    tags: self::filterTags($get, $get('vendor'), $get('type'))
                                ))
                                ->searchable()
                                ->reactive()
                                ->createOptionForm(self::controlledDropdownCreateOptionForm())
                                ->createOptionUsing(fn (array $data): ?string => self::createControlledDropdownOption(
                                    $data,
                                    HeaderStore::MATERIALS_AND_DIMENSIONS
                                ))
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, $value, $fail) use ($get): void {
                                        $invalid = self::invalidCollectionSelectionValues(
                                            $value,
                                            self::dropdownOptionsForHeader(
                                                HeaderStore::MATERIALS_AND_DIMENSIONS,
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
                    Forms\Components\Grid::make(2)
                        ->schema([

                            Select::make('sibling_collection')
                                ->label('Sibling Collection')
                                ->placeholder('Select sibling collection')
                                ->helperText('Select the actual Shopify collection title here. "Sibling Collection" is the metafield name.')
                                ->options(fn (): array => self::siblingCollectionOptions())
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search): array => self::siblingCollectionSearchResults($search))
                                ->preload()
                                ->getOptionLabelUsing(fn ($value): ?string => self::siblingCollectionDisplayLabel(
                                    is_string($value) ? $value : null
                                ))
                                ->afterStateHydrated(function (Select $component, $state): void {
                                    $normalized = self::normalizeSiblingCollectionValue($state);

                                    if ($normalized !== $state) {
                                        $component->state($normalized);
                                    }
                                })
                                ->dehydrateStateUsing(fn ($state): ?string => self::normalizeSiblingCollectionValue($state)),
                            TextInput::make('siblings_collection_name')
                                ->label('Siblings Option Name')
                                ->disabled()
                                ->dehydrated(false)
                                ->afterStateHydrated(function (TextInput $component, $state, ?NewProductDraft $record): void {
                                    $title = trim((string) ($record?->title ?? ''));
                                    $component->state($title !== '' ? $title : $state);
                                })
                                ->helperText('Always matches the product title.'),
                        ])
                        ->columnSpanFull(),
                            ])->columns(2),
                        Section::make('Variant Defaults')
                            ->schema([
                                Placeholder::make('variant_clash_notice')
                                    ->label('')
                                    ->content(fn (?NewProductDraft $record): ?HtmlString => self::draftVariantClashHtml($record))
                                    ->visible(fn (?NewProductDraft $record): bool => self::draftHasVariantClash($record))
                                    ->columnSpanFull(),
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        TextInput::make('variant_price')
                                            ->label('Price')
                                            ->numeric()
                                            ->required(fn (Get $get): bool => (bool) $get('is_on_sale'))
                                            ->rules([
                                                fn (Get $get): \Closure => function (string $attribute, $value, $fail) use ($get): void {
                                                    if (!self::saleStateFromForm($get('is_on_sale'), $get('tags'))) {
                                                        return;
                                                    }

                                                    $price = self::decimalFloatFromState($value);
                                                    $compareAt = self::decimalFloatFromState($get('variant_compare_at_price'));

                                                    if ($price === null) {
                                                        $fail('Enter the new sale price before saving.');
                                                        return;
                                                    }

                                                    if ($compareAt === null) {
                                                        $fail('Compare-at price is required before a product can be put on sale.');
                                                        return;
                                                    }

                                                    if ($price >= $compareAt) {
                                                        $fail('Sale price must be lower than the compare-at price.');
                                                    }
                                                },
                                            ])
                                            ->disabled(fn (?NewProductDraft $record): bool => self::draftAttributeHasShopifyConflict($record, 'variant_price'))
                                            ->afterStateHydrated(function (TextInput $component, $state, ?NewProductDraft $record): void {
                                                if (
                                                    $record !== null
                                                    && self::saleStateFromForm($record->is_on_sale, $record->tags)
                                                    && $record->variant_compare_at_price === null
                                                    && $state !== null
                                                ) {
                                                    $component->state(null);
                                                    return;
                                                }

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
                                            ->required(fn (Get $get): bool => (bool) $get('is_on_sale'))
                                            ->dehydrated(true)
                                            ->disabled(fn (Get $get, ?NewProductDraft $record): bool => (bool) $get('is_on_sale')
                                                || self::draftAttributeHasShopifyConflict($record, 'variant_compare_at_price'))
                                            ->afterStateHydrated(function (TextInput $component, $state, ?NewProductDraft $record): void {
                                                if (
                                                    $record !== null
                                                    && self::saleStateFromForm($record->is_on_sale, $record->tags)
                                                    && $state === null
                                                    && $record->variant_price !== null
                                                ) {
                                                    $component->state(self::decimalStringFromState($record->variant_price));
                                                    return;
                                                }

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
                                            ->disabled()
                                            ->helperText('Inventory is managed from the Inventory section.')
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
                    CheckboxList::make('bundle_image_urls')
                        ->label('Associated product image choices')
                        ->helperText('Pick images from the associated products. The first selected image becomes the draft primary image URL.')
                        ->columns(2)
                        ->bulkToggleable()
                        ->options(fn (Get $get): array => self::bundleProductImageOptions($get('bundle_product_ids')))
                        ->visible(fn (Get $get, ?NewProductDraft $record): bool => self::shouldShowBundleImageTools($get, $record)
                            && self::normalizeBundleProductIds($get('bundle_product_ids')) !== [])
                        ->afterStateHydrated(function (CheckboxList $component, $state): void {
                            $component->state(self::normalizeBundleImageUrls($state));
                        })
                        ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                            $selected = self::normalizeBundleImageUrls($state);
                            if ($selected === [] || filled($get('image_path'))) {
                                return;
                            }

                            $set('image_url', $selected[0]);
                        })
                        ->dehydrated(fn (Get $get, ?NewProductDraft $record): bool => self::shouldShowBundleImageTools($get, $record))
                        ->dehydrateStateUsing(fn ($state): ?array => self::nullableArray(self::normalizeBundleImageUrls($state))),
                    Placeholder::make('image_locked_notice')
                        ->label('')
                        ->content(function (Get $get, ?NewProductDraft $record): ?string {
                            if (!self::draftImageLocked($get, $record)) {
                                return null;
                            }

                            return 'This product already exists. Image is read-only here and synced product images take priority.';
                        })
                        ->visible(fn (Get $get, ?NewProductDraft $record): bool => self::draftImageLocked($get, $record)),
                    Select::make('colour_style')
                                ->label('Color Style')
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
                    Select::make('product_design')
                        ->label(fn (Get $get): string => self::designLabelForDraftState($get))
                        ->placeholder('Select option')
                        ->options(fn (Get $get): array => self::designOptionsForDraftState(
                            $get,
                            $get('product_design')
                        ))
                        ->multiple()
                        ->helperText(fn (Get $get): ?HtmlString => self::designInvalidSelectionHint($get))
                        ->searchable()
                        ->reactive()
                        ->visible(fn (Get $get): bool => self::shouldShowDesignField($get))
                        ->createOptionForm(self::controlledDesignDropdownCreateOptionForm())
                        ->createOptionUsing(fn (array $data): ?string => self::createControlledDesignDropdownOption($data))
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
                        ->dehydrateStateUsing(function ($state): ?string {
                            $arr = is_array($state) ? $state : [];
                            $clean = array_values(array_unique(array_filter(array_map(
                                fn ($v) => trim((string) $v),
                                $arr
                            ))));

                            return $clean ? implode('; ', $clean) : null;
                        })
                        ->rules([
                            fn (Get $get): \Closure => function (string $attribute, $value, $fail) use ($get): void {
                                $invalid = self::invalidCollectionSelectionValues(
                                    $value,
                                    self::allowedDesignOptionsForDraftState($get)
                                );
                                if (!empty($invalid)) {
                                    $fail('Invalid value(s) for selected collection: ' . implode('; ', $invalid));
                                }
                            },
                        ]),
                    Textarea::make('product_materials')
                                ->label('Product Materials')
                                ->placeholder('Enter product materials')
                                ->rows(3),
                    RichEditor::make('uvp_short_paragraph')
                        ->label('UVP Short Paragraph')
                        ->toolbarButtons(self::compactRichTextToolbarButtons()),
                    Forms\Components\Toggle::make('seo_deindex')
                        ->label('SEO: Deindex products')
                        ->helperText('Stored as the `seo.hide_from_google` metafield for this draft.')
                        ->inline(false),
                    Forms\Components\Grid::make(2)
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
                                        ->visible(fn (Get $get): bool => self::hasDropdownOptionsForDraftField(
                                            $get,
                                            HeaderStore::PRODUCT_METALS
                                        ))
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
                                        ->visible(fn (Get $get): bool => self::hasDropdownOptionsForDraftField(
                                            $get,
                                            HeaderStore::SIZE
                                        ))
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
                                    Select::make('status')
                                        ->options([
                                            'draft' => 'draft',
                                            'active' => 'active',
                                            'archived' => 'archived',
                                        ])
                                        ->default('draft'),
                                    Select::make('published')
                                        ->options([
                                            'true' => 'true',
                                            'false' => 'false',
                                        ])
                                        ->default('false'),
                                ])
                                ->columnSpanFull(),

                    TextInput::make('batch')
                        ->label('Batch')
                        ->default(fn () => self::defaultBatch()),
                ])
                ->columnSpan(1),
                ])
                ->columnSpanFull(),
                        ]),
                    Forms\Components\Tabs\Tab::make('Extra Fields')
                        ->schema([
                            Section::make('Extra Shopify Fields')
                                ->schema([
                                    Forms\Components\Repeater::make('extra_shopify_fields')
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
                                        ->afterStateHydrated(function (Forms\Components\Repeater $component, ?NewProductDraft $record): void {
                                            $component->state(self::extraShopifyFieldsForDraft($record));
                                        }),
                                ]),
                        ]),
                ]),
        ])->columns(1);
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

    /**
     * @return array<string, string>
     */
    private static function siblingCollectionOptions(mixed $currentValue = null): array
    {
        $options = self::siblingCollectionQuery()
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (ShopifyCollection $collection): array => self::siblingCollectionOptionPair($collection))
            ->all();

        $current = self::normalizeSiblingCollectionValue($currentValue);
        if ($current === null) {
            return $options;
        }

        $label = self::siblingCollectionDisplayLabel($current);
        if ($label !== null && !array_key_exists($current, $options)) {
            $options[$current] = $label;
            asort($options);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function siblingCollectionSearchResults(string $search): array
    {
        $term = trim($search);

        $query = self::siblingCollectionQuery();

        if ($term !== '') {
            $query->where(function (Builder $query) use ($term): void {
                $query->where('title', 'like', "%{$term}%")
                    ->orWhere('handle', 'like', "%{$term}%")
                    ->orWhere('shopify_id', 'like', "%{$term}%");
            });
        }

        return $query
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (ShopifyCollection $collection): array => self::siblingCollectionOptionPair($collection))
            ->all();
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

    private static function collectionFromTags(?string $tags): ?string
    {
        if (!$tags) {
            return null;
        }

        $normalized = self::normalizeTagList($tags);
        if (empty($normalized)) {
            return null;
        }

        $rows = DropdownOption::query()
            ->where(function ($query) use ($normalized): void {
                $query->whereIn('collection_tag_primary', $normalized)
                    ->orWhereIn('collection_tag_secondary', $normalized);
            })
            ->orderBy('collection_style')
            ->get(['collection_style', 'collection_tag_primary', 'collection_tag_secondary']);

        if ($rows->isEmpty()) {
            return null;
        }

        $hasBundleTags = self::hasBundleOrStackTag($normalized);
        $matched = $rows
            ->sortByDesc(function ($row) use ($normalized, $hasBundleTags): int {
                $primary = TagNormalizer::normalizeToken((string) ($row->collection_tag_primary ?? ''));
                $secondary = TagNormalizer::normalizeToken((string) ($row->collection_tag_secondary ?? ''));
                $score = 0;

                if ($secondary !== null && in_array($secondary, $normalized, true)) {
                    $score += 10;
                }

                if ($primary !== null && in_array($primary, $normalized, true)) {
                    $score += 3;
                }

                if ($hasBundleTags && self::isBundleCollectionRow($row->collection_style ?? null, $primary, $secondary)) {
                    $score += 20;
                }

                return $score;
            })
            ->first();

        return is_string($matched?->collection_style ?? null) ? $matched->collection_style : null;
    }

    private static function collectionTags(
        ?string $collection,
        bool $forProductTags = true,
        bool $forceBundleTags = false
    ): array
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
            $primary = TagNormalizer::normalizeToken((string) ($row->collection_tag_primary ?? ''));
            $secondary = TagNormalizer::normalizeToken((string) ($row->collection_tag_secondary ?? ''));
            $isBundleCollection = $forceBundleTags
                || self::isBundleCollectionRow($collection, $primary, $secondary);

            if ($forProductTags && $isBundleCollection) {
                $tags[] = 'bundles';
                if ($secondary !== null) {
                    $tags[] = $secondary;
                }

                continue;
            }

            if ($primary !== null) {
                $tags[] = $primary;
            }
            if ($secondary !== null) {
                $tags[] = $secondary;
            }
        }

        $tags = self::uniqueNormalizedTags($tags);

        return $forProductTags ? self::normalizeBundleCollectionTags($tags) : $tags;
    }

    private static function bundleContextCollectionTags(?string $collection, bool $forProductTags = true): array
    {
        $bundleCollection = self::bundleCollectionStyleForCollection($collection);
        if ($bundleCollection !== null) {
            return self::collectionTags($bundleCollection, forProductTags: $forProductTags);
        }

        return self::collectionTags($collection, forProductTags: $forProductTags, forceBundleTags: true);
    }

    private static function bundleCollectionStyleForCollection(?string $collection): ?string
    {
        if ($collection === null || trim($collection) === '') {
            return null;
        }

        $currentTags = self::collectionTags($collection, forProductTags: false);
        if (self::hasBundleOrStackTag($currentTags)) {
            return $collection;
        }

        $secondaryCandidates = [];
        foreach ($currentTags as $tag) {
            if (self::hasBundleOrStackTag([$tag])) {
                continue;
            }

            $secondaryCandidates[] = "{$tag}-bundles";
            $secondaryCandidates[] = "{$tag}-bundle";
            $secondaryCandidates[] = "{$tag}-stacks";
            $secondaryCandidates[] = "{$tag}-stack";
        }

        $secondaryCandidates = self::uniqueNormalizedTags($secondaryCandidates);
        if ($secondaryCandidates === []) {
            return null;
        }

        $style = DropdownOption::query()
            ->whereIn('collection_tag_secondary', $secondaryCandidates)
            ->orderBy('collection_style')
            ->value('collection_style');

        return is_string($style) && trim($style) !== '' ? $style : null;
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

        return self::uniqueNormalizedTags(array_merge($primary, $secondary, ['bundles', 'bundle', 'stack', 'stacks']));
    }

    private static function expectedVendorForCollection(?string $collection): ?string
    {
        return app(DropdownCollectionCatalog::class)->vendorForCollection($collection);
    }

    private static function normalizeSiblingCollectionValue(mixed $value): ?string
    {
        $current = trim((string) ($value ?? ''));
        if ($current === '') {
            return null;
        }

        if (str_starts_with($current, 'gid://shopify/Collection/')) {
            return $current;
        }

        $normalized = strtolower(trim($current));

        $resolved = trim((string) (self::siblingCollectionLookup()['aliases'][$normalized] ?? ''));

        return $resolved !== '' ? $resolved : $current;
    }

    private static function siblingCollectionDisplayLabel(?string $value): ?string
    {
        $normalized = self::normalizeSiblingCollectionValue($value);
        if ($normalized === null) {
            return null;
        }

        $label = self::siblingCollectionLookup()['options'][$normalized] ?? null;
        if (is_string($label) && $label !== '') {
            return $label;
        }

        return $normalized;
    }

    private static function siblingCollectionOptionLabel(?string $shopifyId, mixed $title, mixed $handle): ?string
    {
        $resolvedId = trim((string) ($shopifyId ?? ''));
        $resolvedTitle = trim((string) ($title ?? ''));
        $resolvedHandle = trim((string) ($handle ?? ''));

        if ($resolvedTitle !== '' && $resolvedHandle !== '') {
            return "{$resolvedTitle} ({$resolvedHandle})";
        }

        if ($resolvedTitle !== '') {
            return $resolvedTitle;
        }

        if ($resolvedHandle !== '') {
            return $resolvedHandle;
        }

        return $resolvedId !== '' ? $resolvedId : null;
    }

    /**
     * @return array<string, string>
     */
    private static function siblingCollectionOptionPair(ShopifyCollection $collection): array
    {
        $shopifyId = trim((string) ($collection->shopify_id ?? ''));
        $label = self::siblingCollectionOptionLabel(
            $shopifyId,
            $collection->title,
            $collection->handle
        );

        if ($shopifyId === '' || $label === null) {
            return [];
        }

        return [$shopifyId => $label];
    }

    /**
     * @return array{
     *   options: array<string, string>,
     *   aliases: array<string, string>
     * }
     */
    private static function siblingCollectionLookup(): array
    {
        if (self::$siblingCollectionLookupCache !== null) {
            return self::$siblingCollectionLookupCache;
        }

        $options = [];
        $aliases = [];

        ShopifyCollection::query()
            ->select(['shopify_id', 'title', 'handle'])
            ->whereNotNull('shopify_id')
            ->where('shopify_id', '!=', '')
            ->orderByRaw("CASE WHEN title IS NULL OR title = '' THEN 1 ELSE 0 END")
            ->orderBy('title')
            ->orderBy('handle')
            ->get()
            ->each(function (ShopifyCollection $collection) use (&$options, &$aliases): void {
                $shopifyId = trim((string) ($collection->shopify_id ?? ''));
                if ($shopifyId === '') {
                    return;
                }

                $label = self::siblingCollectionOptionLabel(
                    $shopifyId,
                    $collection->title,
                    $collection->handle
                );

                if ($label !== null) {
                    $options[$shopifyId] = $label;
                }

                foreach ([
                    trim((string) ($collection->title ?? '')),
                    trim((string) ($collection->handle ?? '')),
                ] as $alias) {
                    $normalizedAlias = strtolower($alias);
                    if ($normalizedAlias !== '' && !array_key_exists($normalizedAlias, $aliases)) {
                        $aliases[$normalizedAlias] = $shopifyId;
                    }
                }
            });

        return self::$siblingCollectionLookupCache = [
            'options' => $options,
            'aliases' => $aliases,
        ];
    }

    private static function siblingCollectionQuery(): Builder
    {
        return ShopifyCollection::query()
            ->select(['shopify_id', 'title', 'handle'])
            ->whereNotNull('shopify_id')
            ->where('shopify_id', '!=', '')
            ->distinct()
            ->orderByRaw("CASE WHEN title IS NULL OR title = '' THEN 1 ELSE 0 END")
            ->orderBy('title')
            ->orderBy('handle');
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

    private static function normalizeTagList(mixed $tags): array
    {
        if (is_array($tags)) {
            return self::uniqueNormalizedTags($tags);
        }

        if (is_string($tags) && trim($tags) !== '') {
            return TagNormalizer::parseTokens($tags);
        }

        return [];
    }

    /**
     * @param array<int, mixed> $tags
     * @return array<int, string>
     */
    private static function uniqueNormalizedTags(array $tags): array
    {
        $normalized = [];
        $seen = [];

        foreach ($tags as $tag) {
            $token = TagNormalizer::normalizeToken((string) $tag);
            if ($token === null) {
                continue;
            }

            $key = strtolower($token);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $token;
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private static function normalizeBundleCollectionTags(array $tags): array
    {
        $tags = self::uniqueNormalizedTags($tags);
        if (!self::hasBundleOrStackTag($tags)) {
            return $tags;
        }

        $remove = ['bundle', 'stack', 'stacks'];
        foreach ($tags as $tag) {
            foreach (['-bundles', '-bundle', '-stacks', '-stack'] as $suffix) {
                if (!str_ends_with($tag, $suffix)) {
                    continue;
                }

                $base = substr($tag, 0, -strlen($suffix));
                if ($base !== '') {
                    $remove[] = $base;
                }
            }
        }

        $tags = array_values(array_filter(
            $tags,
            fn (string $tag): bool => !in_array($tag, array_unique($remove), true)
        ));
        $tags[] = 'bundles';

        return self::uniqueNormalizedTags($tags);
    }

    /**
     * @param array<int, string> $tags
     */
    private static function hasBundleOrStackTag(array $tags): bool
    {
        foreach (self::uniqueNormalizedTags($tags) as $tag) {
            if (in_array($tag, ['bundle', 'bundles', 'stack', 'stacks'], true)) {
                return true;
            }

            if (
                str_ends_with($tag, '-bundle')
                || str_ends_with($tag, '-bundles')
                || str_ends_with($tag, '-stack')
                || str_ends_with($tag, '-stacks')
            ) {
                return true;
            }
        }

        return false;
    }

    private static function isBundleCollectionRow(mixed $collection, ?string $primary, ?string $secondary): bool
    {
        $collectionToken = TagNormalizer::normalizeToken((string) ($collection ?? ''));
        $tokens = array_values(array_filter([$collectionToken, $primary, $secondary]));

        return self::hasBundleOrStackTag($tokens);
    }

    /**
     * @param array<int, string> $tags
     */
    private static function isBundleOrStackState(mixed $type, array $tags = [], mixed $title = null): bool
    {
        if (self::hasBundleOrStackTag($tags)) {
            return true;
        }

        $typeTag = self::defaultTagForProductType($type);
        if ($typeTag === 'bundles') {
            return true;
        }

        $titleTag = TagNormalizer::normalizeToken((string) ($title ?? ''));

        return is_string($titleTag) && self::hasBundleOrStackTag([$titleTag]);
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private static function defaultedDraftTags(array $tags, mixed $type, bool $isOnSale): array
    {
        $tags = self::normalizeBundleCollectionTags($tags);
        $typeTag = self::isBundleOrStackState($type, $tags)
            ? 'bundles'
            : self::defaultTagForProductType($type);
        $tags = self::applySaleTags($tags, $isOnSale, $type);

        foreach (self::DEFAULT_NEW_PRODUCT_TAGS as $defaultTag) {
            $tags[] = $defaultTag;
        }

        if ($typeTag !== null) {
            $tags = array_values(array_filter(
                $tags,
                fn (string $tag): bool => !in_array($tag, self::PRODUCT_TYPE_TAGS, true) || $tag === $typeTag
            ));
            $tags[] = $typeTag;
        }

        return self::normalizeBundleCollectionTags(self::uniqueNormalizedTags($tags));
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private static function applySaleTags(array $tags, bool $isOnSale, mixed $type = null): array
    {
        return app(SaleTagService::class)->apply($tags, $isOnSale, $type);
    }

    private static function defaultTagForProductType(mixed $type): ?string
    {
        $token = TagNormalizer::normalizeToken((string) ($type ?? ''));
        if ($token === null) {
            return null;
        }

        return match ($token) {
            'anklet', 'anklets' => 'anklet',
            'bracelet', 'bracelets' => 'bracelet',
            'bundle', 'bundles', 'stack', 'stacks' => 'bundles',
            'charm', 'charms' => 'charm',
            'earring', 'earrings' => 'earring',
            'necklace', 'necklaces' => 'necklace',
            'ring', 'rings' => 'ring',
            default => str_ends_with($token, 's') && strlen($token) > 3
                ? substr($token, 0, -1)
                : $token,
        };
    }

    private static function saleStateFromForm(mixed $state, mixed $tags = null): bool
    {
        if ($state !== null && $state !== '') {
            return filter_var($state, FILTER_VALIDATE_BOOLEAN);
        }

        return self::tagListContains(self::normalizeTagList($tags), self::SALE_TAG);
    }

    /**
     * @param array<int, string> $tags
     */
    private static function tagListContains(array $tags, string $needle): bool
    {
        $normalizedNeedle = TagNormalizer::normalizeToken($needle);
        if ($normalizedNeedle === null) {
            return false;
        }

        return in_array($normalizedNeedle, self::uniqueNormalizedTags($tags), true);
    }

    private static function decimalStringFromState(mixed $state): ?string
    {
        $value = self::decimalFloatFromState($state);

        return $value === null ? null : number_format($value, 2, '.', '');
    }

    private static function decimalFloatFromState(mixed $state): ?float
    {
        if ($state === null) {
            return null;
        }

        $normalized = trim((string) $state);
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $normalized);
        $normalized = preg_replace('/[^0-9.]/', '', $normalized ?? '');
        if ($normalized === null || $normalized === '' || $normalized === '.') {
            return null;
        }

        $parts = explode('.', $normalized);
        if (count($parts) > 2) {
            $normalized = array_shift($parts) . '.' . implode('', $parts);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @return array<int, int>
     */
    private static function normalizeBundleProductIds(mixed $state): array
    {
        $values = is_array($state) ? $state : [];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): int => (int) $value,
            $values
        ), static fn (int $value): bool => $value > 0)));
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeBundleImageUrls(mixed $state): array
    {
        if (is_string($state)) {
            $state = preg_split('/[\r\n,;]+/', $state) ?: [];
        }

        $values = is_array($state) ? $state : [];
        $urls = [];
        $seen = [];

        foreach ($values as $value) {
            $url = trim((string) $value);
            if ($url === '') {
                continue;
            }

            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, mixed>|null
     */
    private static function nullableArray(array $values): ?array
    {
        return $values === [] ? null : array_values($values);
    }

    private static function shouldShowBundleAssociationField(Get $get, ?NewProductDraft $record): bool
    {
        return self::isBundleOrStackDraft($get('type'), $get('tags'), $record);
    }

    private static function shouldShowBundleImageTools(Get $get, ?NewProductDraft $record): bool
    {
        return !self::draftImageLocked($get, $record)
            && self::shouldShowBundleAssociationField($get, $record);
    }

    private static function isBundleOrStackDraft(mixed $type, mixed $tags = null, ?NewProductDraft $record = null): bool
    {
        return self::isBundleOrStackState($type, self::normalizeTagList($tags), $record?->title);
    }

    private static function draftBundleProductCount(NewProductDraft $record): int
    {
        return count(self::normalizeBundleProductIds($record->bundle_product_ids));
    }

    private static function draftStackAssociationStateLabel(NewProductDraft $record): string
    {
        $count = self::draftBundleProductCount($record);
        if ($count > 0) {
            return $count . ' linked';
        }

        return self::isBundleOrStackDraft($record->type, $record->tags, $record)
            ? 'No links'
            : 'Not stack';
    }

    private static function draftStackAssociationStateColor(NewProductDraft $record): string
    {
        if (self::draftBundleProductCount($record) > 0) {
            return 'success';
        }

        return self::isBundleOrStackDraft($record->type, $record->tags, $record)
            ? 'warning'
            : 'gray';
    }

    private static function draftStackAssociationTooltip(NewProductDraft $record): string
    {
        $count = self::draftBundleProductCount($record);
        if ($count > 0) {
            return $count . ' associated product ' . ($count === 1 ? 'link' : 'links') . ' saved for this draft.';
        }

        return self::isBundleOrStackDraft($record->type, $record->tags, $record)
            ? 'This stack has no associated products yet.'
            : 'This draft is not detected as a stack or bundle.';
    }

    private static function latestSaleUpdateForDraft(NewProductDraft $record): ?SaleProductUpdate
    {
        if (!self::saleSchedulingTablesReady()) {
            return null;
        }

        $product = self::linkedProductForDraft($record);
        if (!$product instanceof Product) {
            return null;
        }

        if ($product->relationLoaded('latestSaleProductUpdate')) {
            $update = $product->latestSaleProductUpdate;
            return $update instanceof SaleProductUpdate ? $update : null;
        }

        return $product->latestSaleProductUpdate()->first();
    }

    private static function saleSchedulingTablesReady(): bool
    {
        foreach ([
            'sale_import_batches',
            'sale_import_items',
            'sale_product_updates',
            'scheduled_jobs',
            'scheduled_job_items',
        ] as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private static function draftSaleUpdateStatusLabel(NewProductDraft $record): string
    {
        $update = self::latestSaleUpdateForDraft($record);
        if (!$update instanceof SaleProductUpdate) {
            return 'No sale update';
        }

        return match ($update->status) {
            SaleProductUpdate::STATUS_PENDING => 'Pending sale approval',
            SaleProductUpdate::STATUS_APPROVED => 'Sale approved',
            SaleProductUpdate::STATUS_SCHEDULED => 'Scheduled',
            SaleProductUpdate::STATUS_RUNNING => 'Running',
            SaleProductUpdate::STATUS_COMPLETED => 'Completed',
            SaleProductUpdate::STATUS_FAILED => 'Failed',
            SaleProductUpdate::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', (string) $update->status)),
        };
    }

    private static function draftSaleUpdateStatusColor(NewProductDraft $record): string
    {
        $update = self::latestSaleUpdateForDraft($record);

        return match ($update?->status) {
            SaleProductUpdate::STATUS_PENDING => 'warning',
            SaleProductUpdate::STATUS_APPROVED => 'success',
            SaleProductUpdate::STATUS_SCHEDULED, SaleProductUpdate::STATUS_RUNNING => 'info',
            SaleProductUpdate::STATUS_COMPLETED => 'success',
            SaleProductUpdate::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    private static function draftSaleUpdateTooltip(NewProductDraft $record): string
    {
        $update = self::latestSaleUpdateForDraft($record);
        if (!$update instanceof SaleProductUpdate) {
            return 'No staged sale update for this product.';
        }

        return self::draftSaleUpdatePreview($record);
    }

    private static function draftSaleUpdatePreview(NewProductDraft $record): string
    {
        $update = self::latestSaleUpdateForDraft($record);
        if (!$update instanceof SaleProductUpdate) {
            return '';
        }

        $pieces = [
            'SKU ' . $update->sku,
            'current ' . (string) ($update->current_price ?? '-'),
            'sale ' . (string) $update->sale_price,
            'compare-at ' . (string) $update->compare_at_price,
            'tags: ' . (string) ($update->prepared_tags ?? ''),
        ];

        if ($update->error_message) {
            $pieces[] = 'error: ' . $update->error_message;
        }

        return implode(' | ', array_filter($pieces));
    }

    /**
     * @return array<int, string>
     */
    private static function bundleProductOptions(mixed $currentValue = null, ?NewProductDraft $record = null): array
    {
        $selected = self::normalizeBundleProductIds($currentValue);
        $linkedProduct = $record instanceof NewProductDraft ? self::linkedProductForDraft($record) : null;
        $linkedProductId = $linkedProduct instanceof Product ? (int) $linkedProduct->id : null;

        $products = Product::query()
            ->where(function (Builder $query) use ($selected): void {
                $query->where(function (Builder $eligible): void {
                    $eligible
                        ->where(function (Builder $status): void {
                            $status
                                ->whereRaw('LOWER(status) = ?', ['active'])
                                ->orWhereRaw('LOWER(status) = ?', ['draft']);
                        })
                        ->where(function (Builder $singleProduct): void {
                            $singleProduct
                                ->where('is_bundle', false)
                                ->orWhereNull('is_bundle');
                        });
                });

                if ($selected !== []) {
                    $query->orWhereIn('id', $selected);
                }
            })
            ->when($linkedProductId !== null, fn (Builder $query): Builder => $query->where('id', '!=', $linkedProductId))
            ->orderBy('title')
            ->orderBy('handle')
            ->get(['id', 'title', 'handle', 'status']);

        $options = [];
        foreach ($products as $product) {
            $options[(int) $product->id] = self::localProductReferenceLabel($product);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function bundleProductImageOptions(mixed $productIds): array
    {
        $ids = self::normalizeBundleProductIds($productIds);
        if ($ids === []) {
            return [];
        }

        $order = array_flip($ids);
        $products = Product::query()
            ->whereIn('id', $ids)
            ->with(['images' => fn ($query) => $query
                ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
                ->orderBy('position')
                ->orderBy('id')])
            ->get(['id', 'title', 'handle'])
            ->sortBy(fn (Product $product): int => $order[(int) $product->id] ?? PHP_INT_MAX);

        $options = [];
        foreach ($products as $product) {
            $productLabel = self::localProductReferenceLabel($product);

            foreach ($product->images as $image) {
                if (!$image instanceof Image) {
                    continue;
                }

                $src = trim((string) ($image->src ?? ''));
                if ($src === '' || isset($options[$src])) {
                    continue;
                }

                $position = $image->position !== null ? '#' . $image->position : '#?';
                $path = parse_url($src, PHP_URL_PATH);
                $filename = is_string($path) ? basename($path) : '';

                $options[$src] = trim($productLabel . ' ' . $position . ($filename !== '' ? " - {$filename}" : ''));
            }
        }

        return $options;
    }

    private static function localProductReferenceLabel(Product $product): string
    {
        $title = trim((string) ($product->title ?? ''));
        $handle = trim((string) ($product->handle ?? ''));
        $status = strtolower(trim((string) ($product->status ?? '')));
        $label = $title !== '' ? $title : ($handle !== '' ? $handle : 'Product #' . $product->id);

        if ($handle !== '' && strcasecmp($label, $handle) !== 0) {
            $label .= " ({$handle})";
        }

        if ($status === 'draft') {
            return "[DRAFT] {$label}";
        }

        return $label;
    }

    private static function resolvedSkuForDraft(NewProductDraft $record): ?string
    {
        $variantSku = self::linkedProductForDraft($record)?->variants()
            ->orderBy('id')
            ->value('sku');

        $resolved = trim((string) ($variantSku ?? $record->sku ?? ''));
        return $resolved === '' ? null : $resolved;
    }

    /**
     * @return array{variant_price:?string,variant_compare_at_price:?string,variant_inventory_qty:?int}
     */
    private static function resolvedVariantDefaultsForDraft(NewProductDraft $record): array
    {
        $variant = self::linkedProductForDraft($record)?->variants()
            ->orderBy('id')
            ->first();

        return [
            'variant_price' => $variant?->price !== null ? (string) $variant->price : null,
            'variant_compare_at_price' => $variant?->compare_at_price !== null ? (string) $variant->compare_at_price : null,
            'variant_inventory_qty' => $variant?->inventory_tracked === false
                ? null
                : ($variant?->inventory_qty !== null ? (int) $variant->inventory_qty : null),
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

    private static function controlledDropdownCreateOptionForm(): array
    {
        return [
            TextInput::make('value')
                ->required()
                ->maxLength(255),
            Select::make('collection_style')
                ->label('Collection')
                ->options(fn (): array => self::collectionOptions())
                ->default(fn (Get $get): ?string => self::defaultCollectionStyleForState($get))
                ->searchable()
                ->placeholder('Use current product collection by default')
                ->helperText('Pick a collection only if you want to override the current one.'),
        ];
    }

    private static function controlledDesignDropdownCreateOptionForm(): array
    {
        return [
            Forms\Components\Hidden::make('header')
                ->default(fn (Get $get): ?string => self::designHeaderForDraftState($get)),
            ...self::controlledDropdownCreateOptionForm(),
        ];
    }

    private static function designHeaderForDraftState(Get $get): ?string
    {
        return HeaderStore::designHeaderForTypeAndTags(
            is_string($get('type')) ? $get('type') : null,
            self::filterTags($get, $get('vendor'), $get('type'))
        );
    }

    private static function designLabelForDraftState(Get $get): string
    {
        return match (self::designHeaderForDraftState($get)) {
            HeaderStore::NECKLACE_DESIGN => 'Necklace design',
            HeaderStore::EARRING_DESIGN => 'Earring design',
            default => 'Bracelet design',
        };
    }

    /**
     * @return array<string, string>
     */
    private static function designOptionsForDraftState(Get $get, mixed $currentValue = null): array
    {
        return self::withCurrentOptions(
            self::allowedDesignOptionsForDraftState($get),
            $currentValue
        );
    }

    /**
     * @return array<string, string>
     */
    private static function allowedDesignOptionsForDraftState(Get $get): array
    {
        $header = self::designHeaderForDraftState($get);
        if ($header === null) {
            return [];
        }

        return self::dropdownOptionsForHeader(
            $header,
            tags: self::filterTags($get, $get('vendor'), $get('type'))
        );
    }

    private static function hasDropdownOptionsForDraftField(Get $get, string $header): bool
    {
        if (self::defaultCollectionStyleForState($get) === null) {
            return false;
        }

        return !empty(self::dropdownOptionsForHeader(
            $header,
            tags: self::filterTags($get, $get('vendor'), $get('type'))
        ));
    }

    private static function shouldShowDesignField(Get $get): bool
    {
        if (self::designHeaderForDraftState($get) === null) {
            return false;
        }

        return !empty(self::designOptionsForDraftState($get, $get('product_design')))
            || !empty(self::normalizeSelectedOptionTokens($get('product_design')));
    }

    private static function designInvalidSelectionHint(Get $get): ?HtmlString
    {
        $header = self::designHeaderForDraftState($get);
        if ($header === null) {
            return null;
        }

        $invalid = self::invalidCollectionSelectionValues(
            $get('product_design'),
            self::allowedDesignOptionsForDraftState($get)
        );

        if (empty($invalid)) {
            return null;
        }

        $message = 'Invalid value(s) for selected collection: ' . implode('; ', $invalid)
            . '. Remove them or choose values available for this collection.';

        return new HtmlString('<span class="text-danger-600">' . e($message) . '</span>');
    }

    private static function defaultCollectionStyleForState(Get $get): ?string
    {
        $selected = self::nullIfEmpty($get('collection_filter'));
        if ($selected !== null) {
            return $selected;
        }

        $tags = self::normalizeTagList($get('tags'));
        if (empty($tags)) {
            return null;
        }

        return self::collectionFromTags(implode(', ', $tags));
    }

    private static function createControlledDesignDropdownOption(array $data): ?string
    {
        $header = trim((string) ($data['header'] ?? ''));
        if ($header === '') {
            $header = self::designHeaderForCollectionStyle($data['collection_style'] ?? null) ?? '';
        }

        if ($header === '') {
            return null;
        }

        return self::createControlledDropdownOption($data, $header);
    }

    private static function designHeaderForCollectionStyle(mixed $collectionStyle): ?string
    {
        $collection = self::nullIfEmpty($collectionStyle);
        if ($collection === null) {
            return null;
        }

        return HeaderStore::designHeaderForTypeAndTags(null, self::collectionTags($collection));
    }

    private static function createControlledDropdownOption(array $data, string $header): ?string
    {
        $value = trim((string) ($data['value'] ?? ''));
        if ($value === '') {
            return null;
        }

        $collectionStyle = self::nullIfEmpty($data['collection_style'] ?? null);
        $context = self::contextForCreateOption($collectionStyle);

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

        $existing = $query->first();
        if ($existing) {
            if (!$existing->active) {
                $existing->update(['active' => true]);
            }
        } else {
            DropdownOption::create([
                'header' => $header,
                'value' => $value,
                'collection_style' => $context['collection_style'],
                'collection_tag_primary' => $context['tag_primary'],
                'collection_tag_secondary' => $context['tag_secondary'],
                'active' => true,
                'sort_order' => 0,
            ]);
        }

        return $value;
    }

    /**
     * @return array{collection_style:?string,tag_primary:?string,tag_secondary:?string}
     */
    private static function contextForCreateOption(?string $collectionStyle): array
    {
        if ($collectionStyle === null) {
            return [
                'collection_style' => null,
                'tag_primary' => null,
                'tag_secondary' => null,
            ];
        }

        $row = DropdownOption::query()
            ->where('collection_style', $collectionStyle)
            ->whereNotNull('collection_tag_primary')
            ->orderBy('collection_tag_primary')
            ->orderBy('collection_tag_secondary')
            ->first(['collection_style', 'collection_tag_primary', 'collection_tag_secondary']);

        return [
            'collection_style' => $collectionStyle,
            'tag_primary' => self::nullIfEmpty($row?->collection_tag_primary),
            'tag_secondary' => self::nullIfEmpty($row?->collection_tag_secondary),
        ];
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function duplicateSkuMessageForDraft(string $sku, ?NewProductDraft $record = null): ?string
    {
        $normalizedSku = trim($sku);
        if ($normalizedSku === '') {
            return null;
        }

        $draftQuery = NewProductDraft::query()
            ->select(['id', 'title', 'handle'])
            ->where('sku', $normalizedSku);

        if ($record) {
            $draftQuery->where('id', '!=', $record->id);
        }

        $variantQuery = Variant::query()
            ->with(['product:id,title,handle'])
            ->where('sku', $normalizedSku);

        if ($record) {
            $currentProductId = self::linkedProductForDraft($record)?->id;
            if ($currentProductId) {
                $variantQuery->where('product_id', '!=', $currentProductId);
            }
        }

        $parts = [];

        $draft = $draftQuery->first();
        if ($draft instanceof NewProductDraft) {
            $draftLabel = self::nullIfEmpty($draft->title)
                ?? self::nullIfEmpty($draft->handle)
                ?? ('Draft #' . $draft->id);
            $parts[] = "draft {$draft->id} ({$draftLabel})";
        }

        $variant = $variantQuery->first();
        if ($variant instanceof Variant) {
            $product = $variant->product;
            $productLabel = self::nullIfEmpty($product?->title)
                ?? self::nullIfEmpty($product?->handle)
                ?? ('Product #' . ($product?->id ?? $variant->product_id));
            $parts[] = "product variant on {$productLabel}";
        }

        if ($parts === []) {
            return null;
        }

        return 'SKU is already used by ' . implode(' and ', $parts) . '.';
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
     */
    private static function withCurrentOptions(array $options, mixed $currentValue): array
    {
        $selected = self::normalizeSelectedOptionTokens($currentValue);
        if (empty($selected)) {
            return $options;
        }

        foreach ($selected as $current) {
            if (!array_key_exists($current, $options)) {
                $options[$current] = $current;
            }
        }

        ksort($options);

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

    private static function colorSelectionHint(Get $get): ?HtmlString
    {
        $parts = [];

        $invalid = self::invalidCollectionSelectionValues(
            $get('color_string'),
            self::dropdownOptionsForHeader(
                HeaderStore::COLOR_METAFIELD,
                vendor: $get('vendor'),
                productType: $get('type'),
                tags: self::filterTags($get, $get('vendor'), $get('type'))
            )
        );

        if (!empty($invalid)) {
            $parts[] = 'Invalid value(s) for selected collection: ' . implode('; ', $invalid)
                . '. Remove them or choose values available for this collection.';
        }

        $conflict = trim((string) ($get('color_conflict_message') ?? ''));
        if ($conflict !== '') {
            $parts[] = $conflict;
        }

        if (empty($parts)) {
            return null;
        }

        return new HtmlString('<span class="text-danger-600">' . e(implode(' ', $parts)) . '</span>');
    }

    private static function productReferenceStatusHint(Get $get, string $field): ?HtmlString
    {
        $messages = [];

        $invalid = self::invalidProductReferenceStatusLabels($get($field));

        if (!empty($invalid)) {
            $messages[] = 'Inactive products in this list: ' . implode('; ', $invalid)
                . '. Remove them before saving.';
        }

        if ($field === 'complementary_products' && self::complementaryMinimumEnabled()) {
            $messages[] = 'Minimum required: ' . self::complementaryMinimumCount() . ' complementary products.';
        }

        if (empty($messages)) {
            return null;
        }

        return new HtmlString('<span class="text-danger-600">' . e(implode(' ', $messages)) . '</span>');
    }

    /**
     * @return array<int, string>
     */
    private static function invalidProductReferenceStatusLabels(mixed $value): array
    {
        $selected = self::parseProductReferenceState($value);
        if (empty($selected)) {
            return [];
        }

        $products = Product::query()
            ->whereIn('shopify_id', $selected)
            ->get(['shopify_id', 'title', 'handle', 'status'])
            ->keyBy(fn (Product $product): string => trim((string) $product->shopify_id));

        $invalid = [];

        foreach ($selected as $gid) {
            $product = $products->get($gid);
            if (!$product instanceof Product) {
                continue;
            }

            $status = strtolower(trim((string) ($product->status ?? '')));
            if (in_array($status, ['active', 'draft'], true)) {
                continue;
            }

            $invalid[] = self::productReferenceLabel($product);
        }

        return array_values(array_unique($invalid));
    }

    /**
     * @return array<string, string>
     */
    private static function productReferenceOptions(mixed $currentValue = null): array
    {
        $selected = self::parseProductReferenceState($currentValue);

        $products = Product::query()
            ->whereNotNull('shopify_id')
            ->where('shopify_id', '!=', '')
            ->where(function (Builder $query): void {
                $query
                    ->whereRaw('LOWER(status) = ?', ['active'])
                    ->orWhereRaw('LOWER(status) = ?', ['draft']);
            })
            ->orderBy('title')
            ->orderBy('handle')
            ->get(['shopify_id', 'title', 'handle', 'status']);

        $options = [];
        foreach ($products as $product) {
            $gid = trim((string) $product->shopify_id);
            if ($gid === '') {
                continue;
            }

            $options[$gid] = self::productReferenceLabel($product);
        }

        $missingSelected = array_values(array_filter(
            $selected,
            fn (string $gid): bool => !isset($options[$gid])
        ));

        if (!empty($missingSelected)) {
            $selectedProducts = Product::query()
                ->whereIn('shopify_id', $missingSelected)
                ->get(['shopify_id', 'title', 'handle', 'status']);

            foreach ($selectedProducts as $product) {
                $gid = trim((string) $product->shopify_id);
                if ($gid === '') {
                    continue;
                }

                $options[$gid] = self::productReferenceLabel($product);
            }
        }

        foreach ($selected as $gid) {
            if (!isset($options[$gid])) {
                $options[$gid] = $gid;
            }
        }

        return $options;
    }

    private static function productReferenceLabel(Product $product): string
    {
        $gid = trim((string) $product->shopify_id);
        $title = trim((string) $product->title);
        $handle = trim((string) $product->handle);
        $status = strtolower(trim((string) ($product->status ?? '')));

        $label = $title !== '' ? $title : ($handle !== '' ? $handle : $gid);
        if ($handle !== '' && strcasecmp($label, $handle) !== 0) {
            $label .= " ({$handle})";
        }

        if ($status === 'draft') {
            return "[DRAFT] {$label}";
        }

        if ($status !== '' && $status !== 'active') {
            return '[' . strtoupper($status) . "] {$label}";
        }

        return $label;
    }

    /**
     * @return array<int, string>
     */
    private static function parseProductReferenceState(mixed $state): array
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
                return self::parseProductReferenceState($decoded);
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

    private static function dehydrateProductReferenceState(mixed $state): ?string
    {
        $tokens = self::parseProductReferenceState($state);
        if (empty($tokens)) {
            return null;
        }

        $tokens = array_values(array_unique($tokens));
        return implode('; ', $tokens);
    }

    private static function productReferencesAsLabels(?string $value): string
    {
        $tokens = self::parseProductReferenceState($value);
        if (empty($tokens)) {
            return '';
        }

        $labelsByGid = self::productReferenceOptions();
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
            $rawTags = $get('tags');
            $currentTags = self::normalizeTagList($rawTags);
            $isBundleContext = self::isBundleOrStackState($productType, $currentTags, $get('title'));
            $tags = $isBundleContext
                ? self::bundleContextCollectionTags($collection, forProductTags: false)
                : self::collectionTags($collection, forProductTags: false);
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
                'product:id,handle,shopify_id,has_errors,error_fields',
                'product.images:id,product_id,src,position',
                'product.variants:id,product_id,sku,price,compare_at_price,inventory_tracked,inventory_qty,sync_state',
                'product.latestSaleProductUpdate',
            ]))
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->square()
                    ->size(40)
                    ->state(fn (NewProductDraft $record) => self::draftDisplayImageUrl($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortDraftsByThumbnail($query, $direction))
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
                TextColumn::make('approvals_current')
                    ->label('Approvals')
                    ->state(fn (NewProductDraft $record): string => $record->approvalsForCurrentVersionCount() . '/2')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortDraftsByApprovalCount($query, $direction))
                    ->toggleable(),
                TextColumn::make('approval_state')
                    ->label('Approval State')
                    ->state(fn (NewProductDraft $record): string => self::draftApprovalStateLabel($record))
                    ->badge()
                    ->color(fn (NewProductDraft $record): string => self::draftApprovalStateColor($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortDraftsByApprovalCount($query, $direction))
                    ->toggleable(),
                TextColumn::make('shopify_missing_status_display')
                    ->label('Shopify Missing')
                    ->state(fn (NewProductDraft $record): string => match ($record->shopify_missing_status) {
                        NewProductDraft::SHOPIFY_MISSING_PENDING_REVIEW => 'Pending Review',
                        NewProductDraft::SHOPIFY_MISSING_INVESTIGATING => 'Investigating',
                        NewProductDraft::SHOPIFY_MISSING_CLEANED => 'Cleaned Local',
                        NewProductDraft::SHOPIFY_MISSING_RECOVERY_ENABLED => 'Recovery Enabled',
                        default => $record->isBlockedFromShopifyMissing() ? 'Blocked' : 'None',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Pending Review' => 'danger',
                        'Investigating' => 'warning',
                        'Cleaned Local' => 'gray',
                        'Recovery Enabled' => 'success',
                        'Blocked' => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('delete_request_status')
                    ->label('Delete Request')
                    ->state(fn (NewProductDraft $record): string => self::deletionRequestStatusLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Processing' => 'danger',
                        'Pending 1/2', 'Pending 2/2' => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shopify_sync_warning_count')
                    ->label('Sync Warnings')
                    ->state(fn (NewProductDraft $record): int => $record->shopifySyncWarningCount())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortDraftsByWarningCount($query, $direction))
                    ->toggleable(),
                TextColumn::make('variant_clash_count')
                    ->label('Variant Clash')
                    ->state(fn (NewProductDraft $record): int => count(self::draftVariantClashes($record)))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->tooltip(fn (NewProductDraft $record): string => self::draftVariantClashSummary($record))
                    ->toggleable(),
                TextColumn::make('stack_association_state')
                    ->label('Stack Links')
                    ->state(fn (NewProductDraft $record): string => self::draftStackAssociationStateLabel($record))
                    ->badge()
                    ->color(fn (NewProductDraft $record): string => self::draftStackAssociationStateColor($record))
                    ->tooltip(fn (NewProductDraft $record): string => self::draftStackAssociationTooltip($record))
                    ->toggleable(),
                TextColumn::make('sale_update_status')
                    ->label('Sale Partial Approval')
                    ->state(fn (NewProductDraft $record): string => self::draftSaleUpdateStatusLabel($record))
                    ->badge()
                    ->color(fn (NewProductDraft $record): string => self::draftSaleUpdateStatusColor($record))
                    ->tooltip(fn (NewProductDraft $record): string => self::draftSaleUpdateTooltip($record))
                    ->toggleable(),
                TextColumn::make('sale_update_preview')
                    ->label('Sale Update Preview')
                    ->state(fn (NewProductDraft $record): string => self::draftSaleUpdatePreview($record))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('approved')
                    ->label('Approved')
                    ->boolean()
                    ->state(fn (NewProductDraft $record): bool => $record->isApprovedByTwo())
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortDraftsByApprovalCount($query, $direction))
                    ->toggleable(),
                TextColumn::make('color_string')
                    ->label('Colors')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('jewelry_material')
                    ->label('Jewelry material')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('material_cost')
                    ->label('Cost per item')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('linked_product_errors')
                    ->label('Errors')
                    ->icon(fn (bool $state): string => $state ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (bool $state): string => $state ? 'danger' : 'success')
                    ->state(fn (NewProductDraft $record): bool => self::draftHasLinkedProductErrors($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortDraftsByLinkedProductColumn($query, 'has_errors', $direction))
                    ->toggleable(),
                TextColumn::make('linked_product_error_fields')
                    ->label('Error fields')
                    ->color(fn (NewProductDraft $record): string => self::draftHasLinkedProductErrors($record) ? 'danger' : 'gray')
                    ->state(fn (NewProductDraft $record): string => self::draftErrorFieldsSummary($record))
                    ->extraAttributes(['style' => 'min-width: 32rem;'])
                    ->tooltip(fn (NewProductDraft $record): string => self::draftErrorFieldsSummary($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortDraftsByLinkedProductColumn($query, 'error_fields', $direction))
                    ->toggleable(),
                TextColumn::make('type')->label('Type')->sortable()->toggleable(),
                TextColumn::make('vendor')->sortable()->toggleable(),
                IconColumn::make('published')
                    ->label('Published')
                    ->boolean()
                    ->state(fn (NewProductDraft $record): bool => filter_var($record->published, FILTER_VALIDATE_BOOLEAN))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('batch')
                    ->label('Batch')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sku')
                    ->state(fn (NewProductDraft $record): ?string => self::resolvedSkuForDraft($record))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_category')
                    ->label('Product Category')
                    ->formatStateUsing(fn ($state): string => (string) (
                        CategoryTypeMap::categoryLabelForValue(is_string($state) ? $state : null) ?? $state ?? ''
                    ))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('google_product_category')
                    ->label('Google Product Category')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tags')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('body_html')
                    ->label('Description')
                    ->limit(60)
                    ->wrap()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('styleProfiles.seo_updated_at')
                    ->label('SEO Draft Updated')
                    ->state(fn (NewProductDraft $record): ?string => $record->styleProfiles()
                        ->orderByDesc('seo_updated_at')
                        ->value('seo_updated_at'))
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variant_price')
                    ->label('Price')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variant_compare_at_price')
                    ->label('Compare-at')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variant_inventory_qty')
                    ->label('Inventory')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_materials')
                    ->label('Product Materials')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('materials_and_dimensions')
                    ->label('Materials and Dimensions')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_design')
                    ->label('Product design')
                    ->formatStateUsing(fn ($state): string => self::normalizeDesignAliasValue($state) ?? '')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('metal')
                    ->label('Metal')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('colour_style')
                    ->label('Color Style')
                    ->formatStateUsing(fn ($state): string => self::normalizeDesignAliasValue($state) ?? '')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('size')
                    ->label('Size')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('siblings')
                    ->label('Siblings')
                    ->formatStateUsing(fn (?string $state): string => self::productReferencesAsLabels($state))
                    ->wrap()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('siblings_collection_name')
                    ->label('Siblings Option Name')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sibling_collection')
                    ->label('Sibling Collection')
                    ->formatStateUsing(fn (?string $state): string => self::siblingCollectionDisplayLabel($state) ?? '')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uvp_short_paragraph')
                    ->label('UVP Short Paragraph')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('complementary_products')
                    ->label('Complementary products')
                    ->formatStateUsing(fn (?string $state): string => self::productReferencesAsLabels($state))
                    ->wrap()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('complementary_audit_status')
                    ->label('Complementary Audit')
                    ->state(fn (NewProductDraft $record): string => self::draftComplementaryAuditStatusLabel($record))
                    ->badge()
                    ->color(fn (NewProductDraft $record): string => self::draftComplementaryAuditStatusColor($record))
                    ->toggleable(),
                TextColumn::make('complementary_audit_issues')
                    ->label('Complementary Audit Issues')
                    ->state(fn (NewProductDraft $record): string => self::draftComplementaryAuditIssuesSummary($record))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('quickEdit')
                    ->label('Quick Edit')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('gray')
                    ->disabled(fn (NewProductDraft $record): bool => $record->isPendingApproval())
                    ->tooltip(fn (NewProductDraft $record): string => $record->isPendingApproval()
                        ? 'This draft is already pending approval. Finish the current approval cycle before editing it again.'
                        : (self::draftHasBlockingShopifyWarnings($record)
                            ? self::draftBlockingWarningsTooltip($record)
                            : 'Quick Edit'))
                    ->form(fn (NewProductDraft $record): array => self::draftQuickEditFormSchema($record))
                    ->fillForm(fn (NewProductDraft $record): array => self::draftQuickEditDefaults($record))
                    ->action(function (NewProductDraft $record, array $data): void {
                        self::applyQuickEditsToDraft($record, $data);
                    }),
                Tables\Actions\Action::make('seoDraft')
                    ->label('SEO Draft')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->disabled(fn (NewProductDraft $record): bool => blank(trim((string) ($record->handle ?? ''))) || $record->isPendingApproval())
                    ->tooltip(fn (NewProductDraft $record): string => filled(trim((string) ($record->handle ?? '')))
                        ? ($record->isPendingApproval()
                            ? 'This draft is already pending approval. Finish the current approval cycle before editing it again.'
                            : (self::draftHasBlockingShopifyWarnings($record)
                            ? self::draftBlockingWarningsTooltip($record)
                            : 'SEO Draft'))
                        : 'Save a handle on this draft before editing the SEO Draft.')
                    ->modalWidth('4xl')
                    ->modalHeading(fn (NewProductDraft $record): string|HtmlString => self::seoDraftModalHeading($record))
                    ->modalSubmitActionLabel('Save SEO Draft')
                    ->form(fn (NewProductDraft $record): array => self::seoDraftFormSchema($record))
                    ->fillForm(fn (NewProductDraft $record): array => self::seoDraftFormData($record))
                    ->action(function (NewProductDraft $record, array $data): void {
                        self::saveSeoDraft($record, $data);

                        self::sendNotification(Notification::make()
                            ->title('SEO draft saved')
                            ->success()
                        );
                    }),
                Tables\Actions\EditAction::make()
                    ->color('warning')
                    ->disabled(fn (NewProductDraft $record): bool => $record->isPendingApproval())
                    ->tooltip(fn (NewProductDraft $record): ?string => $record->isPendingApproval()
                        ? 'This draft is already pending approval. Finish the current approval cycle before editing it again.'
                        : (self::draftHasBlockingShopifyWarnings($record)
                            ? self::draftBlockingWarningsTooltip($record)
                            : null)),
                Tables\Actions\Action::make('withdrawFromApproval')
                    ->label('Withdraw Approval')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (NewProductDraft $record): bool => $record->isPendingApproval())
                    ->action(function (NewProductDraft $record): void {
                        $result = self::withdrawDraftFromApproval($record, (int) Auth::id());

                        self::sendNotification(Notification::make()
                            ->title('Draft withdrawn from approval')
                            ->body("Removed {$result['removed']} approval record(s).")
                            ->warning()
                        );
                    }),
                Tables\Actions\Action::make('investigateShopifyMissing')
                    ->label('Investigate')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('warning')
                    ->visible(fn (NewProductDraft $record): bool => $record->isBlockedFromShopifyMissing())
                    ->action(function (NewProductDraft $record): void {
                        app(ShopifyMissingDraftWorkflowService::class)->investigate($record, Auth::id());

                        self::sendNotification(Notification::make()
                            ->title('Draft marked for investigation')
                            ->body('This draft remains blocked from automatic re-sync until recovery is explicitly enabled.')
                            ->warning()
                        );
                    }),
                Tables\Actions\Action::make('cleanLocalProduct')
                    ->label('Clean Local')
                    ->icon('heroicon-o-trash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (NewProductDraft $record): bool => $record->isBlockedFromShopifyMissing())
                    ->action(function (NewProductDraft $record): void {
                        app(ShopifyMissingDraftWorkflowService::class)->cleanLocalProduct($record, Auth::id());

                        self::sendNotification(Notification::make()
                            ->title('Local product cleaned')
                            ->body('The local Product record was removed. The draft remains as a recovery record and is still blocked from automatic re-sync.')
                            ->success()
                        );
                    }),
                Tables\Actions\Action::make('enableRecovery')
                    ->label('Enable Recovery')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (NewProductDraft $record): bool => $record->isBlockedFromShopifyMissing())
                    ->action(function (NewProductDraft $record): void {
                        app(ShopifyMissingDraftWorkflowService::class)->enableRecovery($record, Auth::id());

                        self::sendNotification(Notification::make()
                            ->title('Recovery enabled')
                            ->body('This draft can sync back into Products again when you choose to recover it.')
                            ->success()
                        );
                    }),
                Tables\Actions\Action::make('requestDelete')
                    ->label('Request Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (NewProductDraft $record): bool => static::canDelete($record) && self::draftRequiresDeletionApproval($record))
                    ->disabled(fn (NewProductDraft $record): bool => self::currentDeletionRequest($record) !== null)
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->rows(3)
                            ->maxLength(1000),
                    ])
                    ->action(function (NewProductDraft $record, array $data): void {
                        self::requestDeletion($record, $data['reason'] ?? null);
                    }),
                Tables\Actions\Action::make('approveDelete')
                    ->label('Approve Delete')
                    ->icon('heroicon-o-check-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (NewProductDraft $record): bool => static::canDelete($record) && self::draftRequiresDeletionApproval($record))
                    ->disabled(fn (NewProductDraft $record): bool => !self::canApproveDeletion($record))
                    ->action(function (NewProductDraft $record): void {
                        self::approveDeletion($record);
                    }),
                Tables\Actions\Action::make('deleteLocal')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (NewProductDraft $record): bool => static::canDelete($record) && !self::draftRequiresDeletionApproval($record))
                    ->action(function (NewProductDraft $record): void {
                        self::deleteLocally($record);
                    }),
            ])
            ->headerActions([
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

                        self::sendNotification(Notification::make()
                            ->title('Template ready')
                            ->body("Saved to public/template/{$name}")
                            ->success()
                            ->actions([
                                \Filament\Notifications\Actions\Action::make('download')
                                    ->label('Download')
                                    ->url($url, shouldOpenInNewTab: true),
                            ])
                        );
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

                        $referenceParts = [];
                        if (($result['resolved_product_references'] ?? 0) > 0) {
                            $referenceParts[] = "Resolved links: {$result['resolved_product_references']}";
                        }
                        if (($result['unresolved_product_references'] ?? 0) > 0) {
                            $referenceParts[] = "Unresolved links: {$result['unresolved_product_references']}";
                        }

                        $pendingApprovalPart = '';
                        if (($result['skipped_pending_approval'] ?? 0) > 0) {
                            $names = array_slice($result['pending_approval_handles'] ?? [], 0, 5);
                            $pendingApprovalPart = ", Approval pending skips: {$result['skipped_pending_approval']}";
                            if ($names !== []) {
                                $pendingApprovalPart .= ' (' . implode(', ', $names) . ')';
                            }
                            $pendingApprovalPart .= '. Withdraw approval first if you still want to update those records.';
                        }

                        self::sendNotification(Notification::make()
                            ->title('Import complete')
                            ->body(
                                "Total: {$result['total']}, Created: {$result['created']}, " .
                                "Updated: {$result['updated']}, SEO Drafts: {$result['seo_drafts_upserted']}, " .
                                "Missing handle: {$result['skipped_missing_handle']}, " .
                                "Duplicate SKU: {$result['skipped_duplicate_sku']}, " .
                                "Reference rule skips: " . ($result['skipped_reference_validation'] ?? 0) .
                                ($referenceParts === [] ? '' : ', ' . implode(', ', $referenceParts)) .
                                $pendingApprovalPart
                            )
                            ->status(($result['skipped_pending_approval'] ?? 0) > 0 ? 'warning' : 'success')
                        );
                    }),
                Tables\Actions\Action::make('importSaleUpdates')
                    ->label('Import Sale Updates')
                    ->icon('heroicon-o-tag')
                    ->color('warning')
                    ->visible(fn (): bool => self::saleSchedulingTablesReady() && (Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false))
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('Sale CSV File')
                            ->required()
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->helperText('Use SKU + Sale Price/Price + Compare-at Price. If SKU is blank, Shopify ID/Product ID/Draft ID/Handle can match single-variant products. Duplicate SKUs need product context. Import stages sale updates only; Shopify is updated by the scheduled sale job.'),
                    ])
                    ->action(function (array $data, SaleProductUpdateImporter $importer): void {
                        $path = Storage::disk('local')->path($data['file']);
                        $result = $importer->importFromPath($path, Auth::id(), (string) $data['file']);

                        $unmatched = array_slice($result['unmatched_skus'] ?? [], 0, 8);
                        $failed = array_slice($result['failed_skus'] ?? [], 0, 8);
                        $details = [];
                        if ($unmatched !== []) {
                            $details[] = 'Unmatched: ' . implode(', ', $unmatched);
                        }
                        if ($failed !== []) {
                            $details[] = 'Failed: ' . implode(', ', $failed);
                        }

                        self::sendNotification(Notification::make()
                            ->title('Sale import complete')
                            ->body(
                                "Batch #{$result['batch_id']}. Rows: {$result['total']}, Matched: {$result['matched']}, " .
                                "Pending sale approval: {$result['pending']}, Unmatched: {$result['unmatched']}, Failed: {$result['failed']}." .
                                ($details === [] ? '' : ' ' . implode(' ', $details))
                            )
                            ->status(($result['unmatched'] > 0 || $result['failed'] > 0) ? 'warning' : 'success')
                        );
                    }),
                Tables\Actions\Action::make('exportLatestSaleImport')
                    ->label('Export Latest Sale Import')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => self::saleSchedulingTablesReady() && SaleImportBatch::latestId() !== null)
                    ->action(function (): void {
                        self::exportLatestSaleImportBatch();
                    }),
                Tables\Actions\Action::make('exportOnSaleProducts')
                    ->label('Export On-Sale Products')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (): void {
                        self::exportOnSaleProducts();
                    }),
                Tables\Actions\Action::make('importStackAssociations')
                    ->label('Import Stack Associations')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('Stack Association CSV')
                            ->required()
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->helperText('Use columns like Stack SKU, Stack Name, SKU 1, SKU 2, SKU 3, SKU 4. Rows without a matching stack SKU are skipped; unmatched bracelet SKUs are ignored.'),
                    ])
                    ->action(function (array $data, NewProductDraftStackAssociationImporter $importer): void {
                        $path = Storage::disk('local')->path($data['file']);
                        $result = $importer->importFromPath($path);

                        $skippedRows = $result['skipped_missing_stack_sku']
                            + $result['skipped_stack_not_found']
                            + $result['skipped_without_resolved_products'];

                        $body = "Rows: {$result['total']}, Updated: {$result['updated']}, Unchanged: {$result['unchanged']}, Skipped rows: {$skippedRows}. " .
                            "Resolved product SKUs: {$result['component_skus_resolved']}, Missing product SKUs: {$result['component_skus_not_found']}, Ambiguous product SKUs: {$result['component_skus_ambiguous']}.";

                        if (($result['warnings'] ?? []) !== []) {
                            $body .= "\n" . implode("\n", $result['warnings']);
                        }

                        self::sendNotification(Notification::make()
                            ->title('Stack associations imported')
                            ->body($body)
                            ->status($skippedRows > 0 || $result['component_skus_not_found'] > 0 || $result['component_skus_ambiguous'] > 0 ? 'warning' : 'success')
                        );
                    }),
                Tables\Actions\Action::make('configureComplementaryRule')
                    ->label(fn (): string => self::complementaryMinimumEnabled()
                        ? 'Complementary Rule: On'
                        : 'Complementary Rule: Off')
                    ->color(fn (): string => self::complementaryMinimumEnabled() ? 'success' : 'gray')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->visible(fn (): bool => Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false)
                    ->form([
                        Forms\Components\Toggle::make('enabled')
                            ->label('Require a minimum number of complementary products')
                            ->default(fn (): bool => self::complementaryMinimumEnabled()),
                        TextInput::make('minimum_count')
                            ->label('Minimum complementary products')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(fn (): int => self::complementaryMinimumCount())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $enabled = (bool) ($data['enabled'] ?? false);
                        $minimumCount = max(1, (int) ($data['minimum_count'] ?? self::complementaryMinimumCount()));

                        Setting::putBool('new_product_drafts.complementary_minimum.enabled', $enabled);
                        Setting::putValue('new_product_drafts.complementary_minimum.count', $minimumCount);

                        self::sendNotification(Notification::make()
                            ->title('Complementary rule updated')
                            ->body($enabled
                                ? "Minimum complementary products enabled at {$minimumCount}."
                                : 'Minimum complementary products disabled.')
                            ->success()
                        );
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkEdit')
                        ->label('Bulk Edit')
                        ->icon('heroicon-o-pencil-square')
                        ->color('gray')
                        ->form(self::draftBulkEditFormSchema())
                        ->action(function ($records, array $data): void {
                            self::applyBulkEditsToDrafts($records, $data);
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('requestDeleteSelected')
                        ->label('Request Delete')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            Textarea::make('reason')
                                ->label('Reason')
                                ->rows(3)
                                ->maxLength(1000),
                        ])
                        ->action(function ($records, array $data): void {
                            $requested = 0;
                            $skippedNoHandle = 0;
                            $skippedExisting = 0;

                            foreach ($records as $record) {
                                if (!$record instanceof NewProductDraft) {
                                    continue;
                                }

                                if (!self::draftRequiresDeletionApproval($record)) {
                                    $skippedNoHandle++;
                                    continue;
                                }

                                if (self::currentDeletionRequest($record) !== null) {
                                    $skippedExisting++;
                                    continue;
                                }

                                try {
                                    app(DeletionRequestWorkflowService::class)->submit($record, (int) Auth::id(), $data['reason'] ?? null);
                                    $requested++;
                                } catch (\Throwable) {
                                    $skippedExisting++;
                                }
                            }

                            self::sendNotification(Notification::make()
                                ->title('Draft delete requests processed')
                                ->body("Requested: {$requested}. Skipped without handle: {$skippedNoHandle}. Skipped with open request: {$skippedExisting}.")
                                ->warning()
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('approveDeleteSelected')
                        ->label('Approve Delete')
                        ->icon('heroicon-o-check-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $approved = 0;
                            $queued = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (!$record instanceof NewProductDraft || !self::canApproveDeletion($record)) {
                                    $skipped++;
                                    continue;
                                }

                                try {
                                    $result = app(DeletionRequestWorkflowService::class)->approve($record, (int) Auth::id());
                                    $approved++;
                                    if (($result['queued'] ?? false) === true) {
                                        $queued++;
                                    }
                                } catch (\Throwable) {
                                    $skipped++;
                                }
                            }

                            self::sendNotification(Notification::make()
                                ->title('Draft delete approvals processed')
                                ->body("Approved: {$approved}. Queued: {$queued}. Skipped: {$skipped}.")
                                ->warning()
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deleteLocalSelected')
                        ->label('Delete Local')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $deleted = 0;
                            $skippedWithHandle = 0;

                            foreach ($records as $record) {
                                if (!$record instanceof NewProductDraft) {
                                    continue;
                                }

                                if (self::draftRequiresDeletionApproval($record)) {
                                    $skippedWithHandle++;
                                    continue;
                                }

                                try {
                                    self::deleteLocally($record, sendNotification: false);
                                    $deleted++;
                                } catch (\Throwable) {
                                    continue;
                                }
                            }

                            self::sendNotification(Notification::make()
                                ->title('Local draft deletion processed')
                                ->body("Deleted locally: {$deleted}. Skipped with handle: {$skippedWithHandle}.")
                                ->success()
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('approveSaleUpdates')
                        ->label('Approve Sale Updates')
                        ->icon('heroicon-o-tag')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Approve selected sale updates?')
                        ->modalDescription('This marks pending sale updates as sale-approved for scheduling. It does not update Shopify.')
                        ->visible(fn (): bool => self::saleSchedulingTablesReady() && self::canApproveSaleUpdates())
                        ->action(function ($records): void {
                            $summary = self::approveSaleUpdatesForDrafts($records, (int) Auth::id());

                            self::sendNotification(Notification::make()
                                ->title('Sale updates approval processed')
                                ->body("Approved: {$summary['approved']}. Skipped without pending sale update: {$summary['skipped']}.")
                                ->status($summary['approved'] > 0 ? 'success' : 'warning')
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkApproveForShopify')
                        ->label('Bulk Approve for Shopify')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $approvedCount = 0;
                            $skippedHasHandleCount = 0;
                            $skippedFullyApprovedCount = 0;
                            $skippedAlreadyApprovedCount = 0;
                            $skippedErrorCount = 0;

                            foreach ($records as $record) {
                                if (!$record instanceof NewProductDraft) {
                                    continue;
                                }

                                if (filled(trim((string) ($record->handle ?? '')))) {
                                    $skippedHasHandleCount++;
                                    continue;
                                }

                                if ($record->isApprovedByTwo()) {
                                    $skippedFullyApprovedCount++;
                                    continue;
                                }

                                if (self::draftHasLinkedProductErrors($record)) {
                                    $skippedErrorCount++;
                                    continue;
                                }

                                $exists = NewProductDraftApproval::query()
                                    ->where('new_product_draft_id', $record->id)
                                    ->where('user_id', Auth::id())
                                    ->where('approval_version', $record->approval_version)
                                    ->exists();

                                if ($exists) {
                                    $skippedAlreadyApprovedCount++;
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
                            if ($skippedHasHandleCount > 0) {
                                $parts[] = "Skipped {$skippedHasHandleCount} already has a handle.";
                            }
                            if ($skippedFullyApprovedCount > 0) {
                                $parts[] = "Skipped {$skippedFullyApprovedCount} already at 2 approvals.";
                            }
                            if ($skippedAlreadyApprovedCount > 0) {
                                $parts[] = "Skipped {$skippedAlreadyApprovedCount} already approved by you.";
                            }
                            if ($skippedErrorCount > 0) {
                                $parts[] = "Skipped {$skippedErrorCount} with unresolved product errors.";
                            }

                            self::sendNotification(Notification::make()
                                ->title('Bulk approval complete')
                                ->body($parts ? implode(' ', $parts) : 'No drafts were approved.')
                                ->success()
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('mergeToProducts')
                        ->label('Create In Shopify')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $draftIds = $records->pluck('id')->all();
                            if (empty($draftIds)) {
                                self::sendNotification(Notification::make()
                                    ->title('Nothing to queue')
                                    ->body('No drafts selected.')
                                    ->warning()
                                );
                                return;
                            }

                            app(AsyncJobStateService::class)->markQueued(AsyncJobStateService::NEW_PRODUCT_SHOPIFY_CREATE);
                            NewProductDraftShopifyCreateJob::dispatch($draftIds, Auth::id());

                            self::sendNotification(Notification::make()
                                ->title('Shopify create queued')
                                ->body('The background job has been queued. Only handle-less drafts with 2 approvals will be created. You will be notified when it finishes.')
                                ->success()
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('useShopifyValues')
                        ->label('Use Shopify Values')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Use Shopify values for warnings')
                        ->modalDescription('For each selected draft, replace warning fields with the latest imported Shopify values and clear the warnings.')
                        ->action(function ($records): void {
                            $result = self::applyShopifyWarningValuesToDrafts($records);

                            self::sendNotification(Notification::make()
                                ->title('Shopify values applied')
                                ->body(self::warningResolutionSummary(
                                    resolved: $result['updated'],
                                    skipped: $result['skipped'],
                                    extra: []
                                ))
                                ->success()
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('keepDraftValues')
                        ->label('Keep Draft Values')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Keep draft values for warnings')
                        ->modalDescription('For each selected draft, clear the warnings, keep the draft values as source of truth, and sync those values back into Products.')
                        ->action(function ($records): void {
                            $result = self::keepDraftWarningValues($records);

                            self::sendNotification(Notification::make()
                                ->title('Draft values kept')
                                ->body(self::warningResolutionSummary(
                                    resolved: $result['cleared'],
                                    skipped: $result['skipped'],
                                    extra: $result['synced'] > 0 ? ["Synced {$result['synced']} back to Products."] : []
                                ))
                                ->success()
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('exportEditableCsv')
                        ->label('Export Editable CSV')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->form([
                            CheckboxList::make('columns')
                                ->label('Columns to export')
                                ->options(fn (): array => app(NewProductDraftRoundtripCsvService::class)->exportColumnOptions())
                                ->default(fn (): array => app(NewProductDraftRoundtripCsvService::class)->defaultExportColumns())
                                ->columns(2)
                                ->required()
                                ->helperText('Draft ID, Handle, and Shopify ID are always included so the file can be imported back safely. Only drafts that already have handles are exported. Siblings and Complementary Products export as handles.'),
                        ])
                        ->action(function ($records, array $data, NewProductDraftRoundtripCsvService $service): void {
                            try {
                                $columns = array_values(array_filter($data['columns'] ?? [], 'is_string'));
                                $export = $service->exportDrafts($records, $columns);
                                $url = Storage::disk($export['disk'])->url($export['path']);

                                $skipMessage = ($export['skipped_without_handle'] ?? 0) > 0
                                    ? " Skipped {$export['skipped_without_handle']} draft(s) with no handle."
                                    : '';

                                self::sendNotification(Notification::make()
                                    ->title('Editable CSV created')
                                    ->body(
                                        "Saved {$export['row_count']} draft(s) with {$export['column_count']} editable column(s) to {$export['path']}. " .
                                        'Any exported draft and SEO columns can be edited and imported back together.' .
                                        $skipMessage
                                    )
                                    ->success()
                                    ->actions([
                                        \Filament\Notifications\Actions\Action::make('download')
                                            ->label('Download')
                                            ->url($url, shouldOpenInNewTab: true),
                                    ])
                                );
                            } catch (\Throwable $e) {
                                self::sendNotification(Notification::make()
                                    ->title('Export failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                );
                            }
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

                                self::sendNotification(Notification::make()
                                    ->title('Assignment queued')
                                    ->body("Assignment #{$assignment->id} was recorded and the email has been queued.")
                                    ->success()
                                );
                            } catch (\Throwable $e) {
                                self::sendNotification(Notification::make()
                                    ->title('Assignment failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                );
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('sendAssignmentSlack')
                        ->label('Assign in Slack')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->color('info')
                        ->form([
                            Select::make('assigned_user_ids')
                                ->label('Assigned Users')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->required()
                                ->options(fn (): array => User::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->get(['id', 'name', 'email'])
                                    ->mapWithKeys(fn (User $user): array => [
                                        $user->id => trim(($user->name ?: $user->email) . ' <' . $user->email . '>'),
                                    ])
                                    ->all())
                                ->helperText('These users will be mentioned in the Slack channel. Add their Slack Member ID in User Management.'),
                            Textarea::make('to_emails')
                                ->label('Fallback Emails')
                                ->rows(2)
                                ->helperText('Optional. Used only when a selected user has no Slack ID or when email lookup is enabled.'),
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
                                ->helperText('Optional note shown in Slack.'),
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
                                ->helperText('Choose the columns the assignee should work on.'),
                        ])
                        ->action(function ($records, array $data, NewProductDraftAssignmentService $service): void {
                            try {
                                $data['notification_channel'] = config('services.slack.channels.assignments');

                                $assignment = $service->createAssignment($records, $data, Auth::user());
                                SendNewProductDraftAssignmentSlackJob::dispatch($assignment->id);

                                self::sendNotification(Notification::make()
                                    ->title('Slack assignment queued')
                                    ->body("Assignment #{$assignment->id} was recorded and the Slack notification has been queued.")
                                    ->success()
                                );
                            } catch (\Throwable $e) {
                                self::sendNotification(Notification::make()
                                    ->title('Assignment failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                );
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->filters([
                Filter::make('recently_edited_today')
                    ->label('Recently Edited Today')
                    ->indicator('Recently Edited Today')
                    ->query(fn (Builder $query): Builder => $query->whereDate('updated_at', today())),
                Filter::make('edited_last_7_days')
                    ->label('Edited in Last 7 Days')
                    ->indicator('Edited in Last 7 Days')
                    ->query(fn (Builder $query): Builder => $query->where('updated_at', '>=', now()->subDays(7))),
                Filter::make('pending_sale_updates')
                    ->label('Pending Sale Updates')
                    ->indicator('Pending Sale Updates')
                    ->visible(fn (): bool => self::saleSchedulingTablesReady() && SaleProductUpdate::query()
                        ->where('status', SaleProductUpdate::STATUS_PENDING)
                        ->exists())
                    ->query(fn (Builder $query): Builder => self::applyDraftSaleUpdateStatusFilter($query, SaleProductUpdate::STATUS_PENDING)),
                Filter::make('sale_approved_updates')
                    ->label('Sale Approved Updates')
                    ->indicator('Sale Approved Updates')
                    ->visible(fn (): bool => self::saleSchedulingTablesReady() && SaleProductUpdate::query()
                        ->where('status', SaleProductUpdate::STATUS_APPROVED)
                        ->exists())
                    ->query(fn (Builder $query): Builder => self::applyDraftSaleUpdateStatusFilter($query, SaleProductUpdate::STATUS_APPROVED)),
                Filter::make('latest_sale_import')
                    ->label('Latest Sale Import')
                    ->indicator('Latest Sale Import')
                    ->visible(fn (): bool => self::saleSchedulingTablesReady() && SaleImportBatch::latestId() !== null)
                    ->query(function (Builder $query): Builder {
                        $batchId = SaleImportBatch::latestId();
                        if ($batchId === null) {
                            return $query;
                        }

                        return $query->whereHas('product.saleProductUpdates', fn (Builder $saleQuery): Builder => $saleQuery
                            ->where('sale_import_batch_id', $batchId));
                    }),
                SelectFilter::make('sale_update_status')
                    ->label('Sale Update Status')
                    ->visible(fn (): bool => self::saleSchedulingTablesReady())
                    ->options([
                        SaleProductUpdate::STATUS_PENDING => 'Pending sale approval',
                        SaleProductUpdate::STATUS_APPROVED => 'Sale approved',
                        SaleProductUpdate::STATUS_SCHEDULED => 'Scheduled',
                        SaleProductUpdate::STATUS_RUNNING => 'Running',
                        SaleProductUpdate::STATUS_COMPLETED => 'Completed',
                        SaleProductUpdate::STATUS_FAILED => 'Failed',
                    ])
                    ->indicateUsing(fn (array $data): array => self::singleValueIndicators(
                        $data,
                        'Sale Update',
                        [
                            SaleProductUpdate::STATUS_PENDING => 'Pending sale approval',
                            SaleProductUpdate::STATUS_APPROVED => 'Sale approved',
                            SaleProductUpdate::STATUS_SCHEDULED => 'Scheduled',
                            SaleProductUpdate::STATUS_RUNNING => 'Running',
                            SaleProductUpdate::STATUS_COMPLETED => 'Completed',
                            SaleProductUpdate::STATUS_FAILED => 'Failed',
                        ],
                    ))
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));
                        if ($value === '') {
                            return $query;
                        }

                        return self::applyDraftSaleUpdateStatusFilter($query, $value);
                    }),
                SelectFilter::make('stack_associations')
                    ->label('Stack Associations')
                    ->options([
                        'stacks_with_components' => 'Stacks with associated products',
                        'stacks_without_components' => 'Stacks without associated products',
                        'any_with_components' => 'Any draft with associated products',
                    ])
                    ->indicateUsing(fn (array $data): array => self::singleValueIndicators(
                        $data,
                        'Stack Associations',
                        [
                            'stacks_with_components' => 'Stacks with associated products',
                            'stacks_without_components' => 'Stacks without associated products',
                            'any_with_components' => 'Any draft with associated products',
                        ],
                    ))
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));

                        return self::applyDraftStackAssociationFilter($query, $value);
                    }),
                SelectFilter::make('local_complementary_status')
                    ->label('Local Complementary')
                    ->options([
                        'good' => 'Good Local (4+ saved)',
                        'bad' => 'Bad Local (below 4)',
                    ])
                    ->indicateUsing(fn (array $data): array => self::singleValueIndicators(
                        $data,
                        'Local Complementary',
                        [
                            'good' => 'Good Local (4+ saved)',
                            'bad' => 'Bad Local (below 4)',
                        ]
                    ))
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));
                        if ($value === '') {
                            return $query;
                        }

                        $ids = app(ComplementaryProductAuditService::class)->draftIdsMatchingLocalStatus($value);

                        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereKey($ids);
                    }),
                SelectFilter::make('shopify_complementary_status')
                    ->label('Shopify Complementary')
                    ->options([
                        'healthy' => 'Healthy on Shopify (valid refs already in local list)',
                        'flagged' => 'Flagged on Shopify (invalid or missing from local list)',
                    ])
                    ->indicateUsing(fn (array $data): array => self::singleValueIndicators(
                        $data,
                        'Shopify Complementary',
                        [
                            'healthy' => 'Healthy on Shopify (valid refs already in local list)',
                            'flagged' => 'Flagged on Shopify (invalid or missing from local list)',
                        ]
                    ))
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));
                        if ($value === '') {
                            return $query;
                        }

                        return self::applyDraftLatestAuditShopifyStatusFilter($query, $value);
                    }),
                // SelectFilter::make('complementary_audit_status')
                //     ->label('Complementary Audit')
                //     ->options([
                //         'flagged' => 'Needs Audit',
                //         'healthy' => 'Healthy',
                //         'missing' => 'Not Checked',
                //     ])
                //     ->indicateUsing(fn (array $data): array => self::singleValueIndicators(
                //         $data,
                //         'Complementary Audit',
                //         [
                //             'flagged' => 'Needs Audit',
                //             'healthy' => 'Healthy',
                //             'missing' => 'Not Checked',
                //         ]
                //     ))
                //     ->query(function (Builder $query, array $data): Builder {
                //         $value = trim((string) ($data['value'] ?? ''));

                //         return match ($value) {
                //             'flagged' => $query->whereExists(function (Builder $sub): void {
                //                 $sub->selectRaw('1')
                //                     ->from('products')
                //                     ->join('shopify_audits', function ($join): void {
                //                         $join->on('shopify_audits.product_id', '=', 'products.id')
                //                             ->where('shopify_audits.audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
                //                             ->where('shopify_audits.status', ShopifyAudit::STATUS_FLAGGED);
                //                     })
                //                     ->where(function (Builder $match): void {
                //                         $match->whereColumn('products.shopify_id', 'new_product_drafts.shopify_id')
                //                             ->orWhereColumn('products.handle', 'new_product_drafts.handle');
                //                     });
                //             }),
                //             'healthy' => $query->whereExists(function (Builder $sub): void {
                //                 $sub->selectRaw('1')
                //                     ->from('products')
                //                     ->join('shopify_audits', function ($join): void {
                //                         $join->on('shopify_audits.product_id', '=', 'products.id')
                //                             ->where('shopify_audits.audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
                //                             ->where('shopify_audits.status', ShopifyAudit::STATUS_HEALTHY);
                //                     })
                //                     ->where(function (Builder $match): void {
                //                         $match->whereColumn('products.shopify_id', 'new_product_drafts.shopify_id')
                //                             ->orWhereColumn('products.handle', 'new_product_drafts.handle');
                //                     });
                //             }),
                //             'missing' => $query->whereNotExists(function (Builder $sub): void {
                //                 $sub->selectRaw('1')
                //                     ->from('products')
                //                     ->join('shopify_audits', function ($join): void {
                //                         $join->on('shopify_audits.product_id', '=', 'products.id')
                //                             ->where('shopify_audits.audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS);
                //                     })
                //                     ->where(function (Builder $match): void {
                //                         $match->whereColumn('products.shopify_id', 'new_product_drafts.shopify_id')
                //                             ->orWhereColumn('products.handle', 'new_product_drafts.handle');
                //                     });
                //             }),
                //             default => $query,
                //         };
                //     }),
                SelectFilter::make('complementary_audit_status')
                ->label('Complementary Audit')
                ->options([
                    'flagged' => 'Needs Audit',
                    'healthy' => 'Healthy',
                    'missing' => 'Not Checked',
                ])
                ->indicateUsing(fn (array $data): array => self::singleValueIndicators(
                    $data,
                    'Complementary Audit',
                    [
                        'flagged' => 'Needs Audit',
                        'healthy' => 'Healthy',
                        'missing' => 'Not Checked',
                    ]
                ))
                ->query(function (Builder $query, array $data): Builder {
                    $value = trim((string) ($data['value'] ?? ''));

                    return match ($value) {
                        'flagged' => self::applyDraftComplementaryAuditStatusFilter(
                            $query,
                            ShopifyAudit::STATUS_FLAGGED
                        ),

                        'healthy' => self::applyDraftComplementaryAuditStatusFilter(
                            $query,
                            ShopifyAudit::STATUS_HEALTHY
                        ),

                        'missing' => $query->whereNotExists(function ($sub): void {
                            $sub->selectRaw('1')
                                ->from('products')
                                ->join('shopify_audits', 'shopify_audits.product_id', '=', 'products.id')
                                ->where('shopify_audits.audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
                                ->where(function ($match): void {
                                    $match->whereColumn('products.shopify_id', 'new_product_drafts.shopify_id')
                                        ->orWhereColumn('products.handle', 'new_product_drafts.handle');
                                });
                        }),

                        default => $query,
                    };
                }),
                Filter::make('shopify_missing_complementary_products')
                    ->label('Shopify Missing Complementary')
                    ->indicator('Shopify Missing Complementary')
                    ->query(fn (Builder $query): Builder => self::applyDraftShopifyComplementaryShortageFilter($query)),
                Filter::make('pending_changes')
                    ->label('Pending Changes')
                    ->indicator('Pending Changes')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        '(select count(distinct user_id) from new_product_draft_approvals where new_product_draft_approvals.new_product_draft_id = new_product_drafts.id and new_product_draft_approvals.approval_version = new_product_drafts.approval_version) < 2'
                    )),
                Filter::make('awaiting_approval')
                    ->label('Awaiting Approval')
                    ->indicator('Awaiting Approval')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        '(select count(distinct user_id) from new_product_draft_approvals where new_product_draft_approvals.new_product_draft_id = new_product_drafts.id and new_product_draft_approvals.approval_version = new_product_drafts.approval_version) < 2'
                    )),
                Filter::make('awaiting_delete_approval')
                    ->label('Awaiting Delete Approval')
                    ->indicator('Awaiting Delete Approval')
                    ->query(fn (Builder $query): Builder => $query->whereHas('deletionRequests', function (Builder $deletionQuery): void {
                        $deletionQuery->whereIn('status', ['pending', 'processing']);
                    })),
                Filter::make('updated_at')
                    ->label('Updated')
                    ->form([
                        DateTimePicker::make('updated_from')->label('Updated from'),
                        DateTimePicker::make('updated_until')->label('Updated until'),
                    ])
                    ->indicateUsing(fn (array $data): array => self::dateTimeRangeIndicators(
                        $data,
                        'updated_from',
                        'updated_until',
                        'Updated From',
                        'Updated Until'
                    ))
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['updated_from'] ?? null,
                                fn (Builder $sub, $from): Builder => $sub->where('updated_at', '>=', $from),
                            )
                            ->when(
                                $data['updated_until'] ?? null,
                                fn (Builder $sub, $to): Builder => $sub->where('updated_at', '<=', $to),
                            );
                    }),
                Filter::make('seo_updated_at')
                    ->label('SEO Updated')
                    ->form([
                        DateTimePicker::make('from')->label('SEO Update From'),
                        DateTimePicker::make('to')->label('SEO Update To'),
                    ])
                    ->indicateUsing(fn (array $data): array => self::dateTimeRangeIndicators(
                        $data,
                        'from',
                        'to',
                        'SEO Updated From',
                        'SEO Updated To'
                    ))
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['from'] ?? null) && blank($data['to'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas('styleProfiles', function (Builder $styleProfileQuery) use ($data): void {
                            $styleProfileQuery
                                ->when($data['from'] ?? null, fn (Builder $sub, $from): Builder => $sub->where('seo_updated_at', '>=', $from))
                                ->when($data['to'] ?? null, fn (Builder $sub, $to): Builder => $sub->where('seo_updated_at', '<=', $to));
                        });
                    }),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(function (): array {
                        $configured = Status::query()
                            ->whereNotNull('name')
                            ->where('name', '!=', '')
                            ->orderBy('name')
                            ->pluck('name', 'name')
                            ->all();

                        $present = NewProductDraft::query()
                            ->whereNotNull('status')
                            ->where('status', '!=', '')
                            ->distinct()
                            ->orderBy('status')
                            ->pluck('status', 'status')
                            ->all();

                        return $configured + array_diff_key($present, $configured);
                    })
                    ->indicateUsing(fn (array $data): array => self::singleValueIndicators($data, 'Status'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->label('Type')
                    ->multiple()
                    ->options(fn () => ['__none__' => 'No type'] + NewProductDraft::query()
                        ->whereNotNull('type')
                        ->where('type', '!=', '')
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->all())
                    ->indicateUsing(fn (array $data): array => self::multiValueIndicators($data, 'Type'))
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
                    ->options(fn () => NewProductDraft::query()
                        ->whereNotNull('vendor')
                        ->where('vendor', '!=', '')
                        ->distinct()
                        ->orderBy('vendor')
                        ->pluck('vendor', 'vendor')
                        ->all())
                    ->indicateUsing(fn (array $data): array => self::singleValueIndicators($data, 'Vendor'))
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
                    ->indicateUsing(fn (array $data): array => self::multiValueIndicators($data, 'Tags'))
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
                    ->indicateUsing(fn (array $data): array => self::multiValueIndicators($data, 'Collection'))
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

                        $query->where(function (Builder $sub) use ($collections, $collectionTags, $includeNone, $allCollectionTags): void {
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
                SelectFilter::make('batch')
                    ->label('Batch')
                    ->options(fn () => NewProductDraft::query()
                        ->whereNotNull('batch')
                        ->where('batch', '!=', '')
                        ->distinct()
                        ->orderByDesc('batch')
                        ->pluck('batch', 'batch')
                        ->all())
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('sent_to_shopify')
                    ->label('Sent to Shopify')
                    ->placeholder('All')
                    ->trueLabel('Sent')
                    ->falseLabel('Not sent')
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->whereNotNull('handle')
                            ->where('handle', '!=', ''),
                        false: fn (Builder $query): Builder => $query->where(function (Builder $subQuery): void {
                            $subQuery
                                ->whereNull('handle')
                                ->orWhere('handle', '');
                        }),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TernaryFilter::make('is_bundle')
                    ->label('Bundles')
                    ->placeholder('All')
                    ->trueLabel('Bundles')
                    ->falseLabel('Non-bundles')
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->where('is_bundle', true)),
                        false: fn (Builder $query): Builder => $query->where(function (Builder $subQuery): void {
                            $subQuery
                                ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->where('is_bundle', false))
                                ->orWhereDoesntHave('product');
                        }),
                        blank: fn (Builder $query): Builder => $query,
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
                TernaryFilter::make('has_errors')
                    ->label('Errors')
                    ->queries(
                        true: fn ($query) => $query->whereHas('product', fn ($productQuery) => $productQuery->where('has_errors', true)),
                        false: fn ($query) => $query->where(function ($subQuery) {
                            $subQuery->whereHas('product', fn ($productQuery) => $productQuery->where('has_errors', false))
                                ->orWhereDoesntHave('product');
                        }),
                    ),
                TernaryFilter::make('variant_clash')
                    ->label('Variant Clash')
                    ->placeholder('All')
                    ->trueLabel('Has Clash')
                    ->falseLabel('No Clash')
                    ->indicateUsing(fn (array $data): array => self::ternaryValueIndicators(
                        $data,
                        'Variant Clash',
                        'No Variant Clash'
                    ))
                    ->queries(
                        true: fn (Builder $query): Builder => self::applyDraftVariantClashFilter($query, true),
                        false: fn (Builder $query): Builder => self::applyDraftVariantClashFilter($query, false),
                        blank: fn (Builder $query): Builder => $query,
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

    public static function requestDeletion(NewProductDraft $record, ?string $reason = null): void
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

    public static function approveDeletion(NewProductDraft $record): void
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

    public static function deleteLocally(NewProductDraft $record, bool $sendNotification = true): void
    {
        if (self::draftRequiresDeletionApproval($record)) {
            throw new \RuntimeException('Drafts with a handle must go through the delete approval workflow.');
        }

        self::logLocalDeletion($record, Auth::id());
        $record->delete();

        if (!$sendNotification) {
            return;
        }

        self::sendNotification(Notification::make()
            ->title('Draft deleted locally')
            ->body('The draft had no handle, so only the local record was removed.')
            ->success()
        );
    }

    private static function currentDeletionRequest(NewProductDraft $record): ?DeletionRequest
    {
        return app(DeletionRequestWorkflowService::class)->openRequestFor($record);
    }

    private static function canApproveDeletion(NewProductDraft $record): bool
    {
        $request = self::currentDeletionRequest($record);

        return $request !== null
            && $request->status === DeletionRequest::STATUS_PENDING
            && !$request->userHasApproved(Auth::id());
    }

    private static function deletionRequestStatusLabel(NewProductDraft $record): string
    {
        if (!self::draftRequiresDeletionApproval($record)) {
            return 'Local Only';
        }

        $request = self::currentDeletionRequest($record);
        if (!$request) {
            return 'None';
        }

        if ($request->status === DeletionRequest::STATUS_PROCESSING) {
            return 'Processing';
        }

        return 'Pending ' . $request->approvalCount() . '/2';
    }

    private static function draftRequiresDeletionApproval(NewProductDraft $record): bool
    {
        return filled(trim((string) ($record->handle ?? '')));
    }

    private static function logLocalDeletion(NewProductDraft $record, ?int $userId): void
    {
        ChangeLog::create([
            'import_id' => $record->product?->import_id,
            'product_id' => $record->product?->id,
            'changed_by' => $userId,
            'model_type' => NewProductDraft::class,
            'model_id' => $record->id,
            'field' => 'deletion_completed',
            'old_value' => null,
            'new_value' => json_encode([
                'status' => 'completed',
                'mode' => 'local_only',
                'title' => $record->title,
                'handle' => $record->handle,
                'shopify_id' => $record->shopify_id,
                'deleted_at' => now()->toDateTimeString(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @return array{removed:int}
     */
    public static function withdrawDraftFromApproval(NewProductDraft $record, int $userId): array
    {
        $product = self::linkedProductForDraft($record);
        if (filled(trim((string) ($record->handle ?? ''))) && $product instanceof Product) {
            $requests = $product->partialApprovalRequests()
                ->where('approval_version', $product->approval_version)
                ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
                ->get();

            $summary = app(ProductPartialApprovalService::class)->deletePendingRequests($requests, $userId);

            return ['removed' => (int) ($summary['deleted'] ?? 0)];
        }

        $approvalRows = [];

        $removed = DB::transaction(function () use ($record, $userId, &$approvalRows): int {
            $approvals = NewProductDraftApproval::query()
                ->where('new_product_draft_id', $record->id)
                ->where('approval_version', $record->approval_version)
                ->get(['id', 'user_id', 'approval_version']);

            $approvalRows = $approvals
                ->map(fn (NewProductDraftApproval $approval): array => [
                    'id' => (int) $approval->id,
                    'user_id' => (int) $approval->user_id,
                    'approval_version' => (int) $approval->approval_version,
                ])
                ->all();

            $count = $approvals->count();

            if ($count > 0) {
                NewProductDraftApproval::query()
                    ->where('new_product_draft_id', $record->id)
                    ->where('approval_version', $record->approval_version)
                    ->delete();
            }

            return $count;
        });

        ChangeLog::create([
            'import_id' => $record->product?->import_id,
            'product_id' => $record->product?->id,
            'changed_by' => $userId,
            'model_type' => NewProductDraft::class,
            'model_id' => $record->id,
            'field' => 'approval_withdrawn',
            'old_value' => null,
            'new_value' => json_encode([
                'status' => 'withdrawn',
                'approval_version' => (int) $record->approval_version,
                'title' => $record->title,
                'handle' => $record->handle,
                'shopify_id' => $record->shopify_id,
                'withdrawn_by' => $userId,
                'withdrawn_at' => now()->toDateTimeString(),
                'removed_approvals' => $approvalRows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return ['removed' => $removed];
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

    private static function applyDraftStackAssociationFilter(Builder $query, string $value): Builder
    {
        return match ($value) {
            'stacks_with_components' => self::applyDraftHasBundleProductFilter(
                self::applyDraftStackFilter($query),
                true,
            ),
            'stacks_without_components' => self::applyDraftHasBundleProductFilter(
                self::applyDraftStackFilter($query),
                false,
            ),
            'any_with_components' => self::applyDraftHasBundleProductFilter($query, true),
            default => $query,
        };
    }

    private static function applyDraftSaleUpdateStatusFilter(Builder $query, string $status): Builder
    {
        if (!self::saleSchedulingTablesReady()) {
            return $query;
        }

        return $query->whereHas('product.saleProductUpdates', fn (Builder $saleQuery): Builder => $saleQuery
            ->where('status', $status));
    }

    private static function canApproveSaleUpdates(): bool
    {
        return Auth::user()?->hasAnyRole([
            RolesEnum::SuperAdmin->value,
            RolesEnum::Admin->value,
        ]) ?? false;
    }

    /**
     * @return array{approved:int, skipped:int}
     */
    private static function approveSaleUpdatesForDrafts($records, int $userId): array
    {
        if (!self::saleSchedulingTablesReady()) {
            return [
                'approved' => 0,
                'skipped' => 0,
            ];
        }

        $approved = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (!$record instanceof NewProductDraft) {
                continue;
            }

            $product = self::linkedProductForDraft($record);
            if (!$product instanceof Product) {
                $skipped++;
                continue;
            }

            $updates = $product->saleProductUpdates()
                ->where('status', SaleProductUpdate::STATUS_PENDING)
                ->get();

            if ($updates->isEmpty()) {
                $skipped++;
                continue;
            }

            foreach ($updates as $update) {
                $update->update([
                    'status' => SaleProductUpdate::STATUS_APPROVED,
                    'approved_at' => now(),
                    'approved_by' => $userId,
                    'error_message' => null,
                ]);
                $approved++;

                logger()->info('Sale product update approved', [
                    'sale_product_update_id' => $update->id,
                    'product_id' => $update->product_id,
                    'variant_id' => $update->variant_id,
                    'sku' => $update->sku,
                    'approved_by' => $userId,
                ]);
            }
        }

        return [
            'approved' => $approved,
            'skipped' => $skipped,
        ];
    }

    private static function exportLatestSaleImportBatch(): void
    {
        if (!self::saleSchedulingTablesReady()) {
            self::sendNotification(Notification::make()
                ->title('Sale export unavailable')
                ->body('Run migrations before exporting sale imports.')
                ->warning());
            return;
        }

        $batch = SaleImportBatch::query()
            ->latest('created_at')
            ->latest('id')
            ->first();

        if (!$batch instanceof SaleImportBatch) {
            self::sendNotification(Notification::make()
                ->title('No sale import to export')
                ->warning());
            return;
        }

        $updatesByProductVariant = SaleProductUpdate::query()
            ->where('sale_import_batch_id', $batch->id)
            ->get()
            ->keyBy(fn (SaleProductUpdate $update): string => (int) $update->product_id . ':' . (int) $update->variant_id);

        $writer = Writer::createFromFileObject(new SplTempFileObject());
        $writer->insertOne([
            'Batch ID',
            'Import Item Status',
            'Sale Update Status',
            'Product ID',
            'Handle',
            'Shopify ID',
            'Variant ID',
            'Shopify Variant ID',
            'SKU',
            'Current Price',
            'Sale Price',
            'Compare-at Price',
            'Prepared Tags',
            'Message',
        ]);

        SaleImportItem::query()
            ->with(['product:id,handle,shopify_id', 'variant:id,product_id,shopify_id,sku,price,compare_at_price'])
            ->where('sale_import_batch_id', $batch->id)
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($writer, $batch, $updatesByProductVariant): void {
                foreach ($items as $item) {
                    if (!$item instanceof SaleImportItem) {
                        continue;
                    }

                    $key = (int) $item->product_id . ':' . (int) $item->variant_id;
                    $update = $updatesByProductVariant->get($key);

                    $writer->insertOne([
                        $batch->id,
                        $item->status,
                        $update instanceof SaleProductUpdate ? $update->status : '',
                        $item->product_id,
                        $item->product?->handle,
                        $item->product?->shopify_id,
                        $item->variant_id,
                        $item->variant?->shopify_id,
                        $item->sku ?: $item->variant?->sku,
                        $update instanceof SaleProductUpdate ? $update->current_price : $item->variant?->price,
                        $update instanceof SaleProductUpdate ? $update->sale_price : $item->sale_price,
                        $update instanceof SaleProductUpdate ? $update->compare_at_price : $item->compare_at_price,
                        $update instanceof SaleProductUpdate ? $update->prepared_tags : '',
                        $update instanceof SaleProductUpdate && $update->error_message
                            ? $update->error_message
                            : $item->message,
                    ]);
                }
            });

        $timestamp = now()->format('Ymd_His');
        $name = "sale_import_batch_{$batch->id}_{$timestamp}.csv";
        $path = "exports/{$name}";
        $disk = Storage::disk('public');
        $disk->put($path, $writer->toString());

        self::sendNotification(Notification::make()
            ->title('Sale import export ready')
            ->body("Exported latest sale import batch #{$batch->id}.")
            ->success()
            ->actions([
                \Filament\Notifications\Actions\Action::make('download')
                    ->label('Download')
                    ->url($disk->url($path), shouldOpenInNewTab: true),
            ]));
    }

    private static function exportOnSaleProducts(): void
    {
        $query = self::applyOnSaleDraftExportFilter(
            NewProductDraft::query()
                ->with([
                    'product:id,handle,title,shopify_id,tags',
                    'product.variants:id,product_id,shopify_id,sku,price,compare_at_price,position,sync_state',
                    'product.latestSaleProductUpdate',
                ])
                ->orderBy('handle')
                ->orderBy('id')
        );

        if (!$query->exists()) {
            self::sendNotification(Notification::make()
                ->title('No on-sale products to export')
                ->warning());
            return;
        }

        $writer = Writer::createFromFileObject(new SplTempFileObject());
        $writer->insertOne([
            'Draft ID',
            'Product ID',
            'Handle',
            'Title',
            'Shopify ID',
            'Variant ID',
            'Shopify Variant ID',
            'SKU',
            'Price',
            'Compare-at Price',
            'Draft On Sale',
            'Sale Update Status',
            'Tags',
        ]);

        $count = 0;
        $query->chunkById(500, function ($drafts) use ($writer, &$count): void {
            foreach ($drafts as $draft) {
                if (!$draft instanceof NewProductDraft) {
                    continue;
                }

                $product = self::linkedProductForDraft($draft);
                $variant = $product?->variants
                    ? $product->variants->sortBy([
                        ['position', 'asc'],
                        ['id', 'asc'],
                    ])->first()
                    : null;
                $saleUpdate = self::latestSaleUpdateForDraft($draft);

                $writer->insertOne([
                    $draft->id,
                    $product?->id,
                    $draft->handle ?: $product?->handle,
                    $draft->title ?: $product?->title,
                    $draft->shopify_id ?: $product?->shopify_id,
                    $saleUpdate?->variant_id ?: $variant?->id,
                    $saleUpdate?->variant?->shopify_id ?: $variant?->shopify_id,
                    $saleUpdate?->sku ?: $draft->sku ?: $variant?->sku,
                    $saleUpdate?->sale_price ?: $draft->variant_price ?: $variant?->price,
                    $saleUpdate?->compare_at_price ?: $draft->variant_compare_at_price ?: $variant?->compare_at_price,
                    (bool) $draft->is_on_sale ? 'yes' : 'no',
                    $saleUpdate?->status ?? '',
                    $saleUpdate?->prepared_tags ?: $draft->tags ?: $product?->tags,
                ]);

                $count++;
            }
        });

        $timestamp = now()->format('Ymd_His');
        $name = "on_sale_products_{$timestamp}.csv";
        $path = "exports/{$name}";
        $disk = Storage::disk('public');
        $disk->put($path, $writer->toString());

        self::sendNotification(Notification::make()
            ->title('On-sale products export ready')
            ->body("Exported {$count} on-sale product draft(s).")
            ->success()
            ->actions([
                \Filament\Notifications\Actions\Action::make('download')
                    ->label('Download')
                    ->url($disk->url($path), shouldOpenInNewTab: true),
            ]));
    }

    private static function applyOnSaleDraftExportFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $saleQuery): void {
            $saleQuery
                ->where('is_on_sale', true)
                ->orWhereRaw(self::saleTagSql('new_product_drafts.tags'));

            $saleQuery->orWhereHas('product', function (Builder $productQuery): void {
                $productQuery->whereRaw(self::saleTagSql('products.tags'));

                if (self::saleSchedulingTablesReady()) {
                    $productQuery->orWhereHas('saleProductUpdates');
                }
            });
        });
    }

    private static function saleTagSql(string $column): string
    {
        return "LOWER(CONCAT(',', REPLACE(COALESCE({$column}, ''), ' ', ''), ',')) LIKE '%,sale,%'";
    }

    private static function applyDraftStackFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $stackQuery): void {
            foreach (['tags', 'type', 'title'] as $column) {
                $stackQuery
                    ->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", ['%bundle%'])
                    ->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", ['%stack%']);
            }
        });
    }

    private static function applyDraftHasBundleProductFilter(Builder $query, bool $hasAssociatedProducts): Builder
    {
        $lengthExpression = self::bundleProductIdsLengthExpression();

        if ($hasAssociatedProducts) {
            return $query
                ->whereNotNull('bundle_product_ids')
                ->whereRaw("COALESCE({$lengthExpression}, 0) > 0");
        }

        return $query->where(function (Builder $associationQuery) use ($lengthExpression): void {
            $associationQuery
                ->whereNull('bundle_product_ids')
                ->orWhereRaw("COALESCE({$lengthExpression}, 0) = 0");
        });
    }

    private static function bundleProductIdsLengthExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'json_array_length(bundle_product_ids)'
            : 'JSON_LENGTH(bundle_product_ids)';
    }

    private static function applyDraftComplementaryAuditStatusFilter(
    Builder $query,
    string $status
    ): Builder {
        $matchingDraftIds = [];

        NewProductDraft::query()
            ->select(['id', 'handle', 'shopify_id', 'complementary_products'])
            ->chunkById(200, function ($drafts) use (&$matchingDraftIds, $status): void {
                foreach ($drafts as $draft) {
                    if (!$draft instanceof NewProductDraft) {
                        continue;
                    }

                    $snapshot = self::draftComplementaryAuditSnapshot($draft);
                    if ($snapshot === null) {
                        continue;
                    }

                    if (($snapshot['status'] ?? null) === $status) {
                        $matchingDraftIds[] = (int) $draft->id;
                    }
                }
            });

        if ($matchingDraftIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereKey(array_values(array_unique($matchingDraftIds)));
    }

    private static function applyDraftShopifyComplementaryShortageFilter(Builder $query): Builder
    {
        return $query->whereExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('products')
                ->join('shopify_audits', 'shopify_audits.product_id', '=', 'products.id')
                ->where('shopify_audits.audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
                ->whereRaw('LOWER(COALESCE(products.title, "")) NOT LIKE ?', ['%tes%'])
                ->whereRaw(
                    "COALESCE(JSON_LENGTH(JSON_EXTRACT(shopify_audits.details, '$.shopify_ids')), 0) < ?",
                    [ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT]
                )
                ->where(function ($match): void {
                    $match->whereColumn('products.shopify_id', 'new_product_drafts.shopify_id')
                        ->orWhereColumn('products.handle', 'new_product_drafts.handle');
                });
        });
    }

    private static function applyDraftLatestAuditShopifyStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'healthy' => self::applyDraftComplementaryAuditStatusFilter($query, ShopifyAudit::STATUS_HEALTHY),
            'flagged' => self::applyDraftComplementaryAuditStatusFilter($query, ShopifyAudit::STATUS_FLAGGED),
            default => $query,
        };
    }

    private static function applyDraftVariantClashFilter(Builder $query, bool $hasClash): Builder
    {
        $ids = self::draftIdsWithVariantClash();

        if ($hasClash) {
            return $ids === []
                ? $query->whereRaw('1 = 0')
                : $query->whereKey($ids);
        }

        return $ids === []
            ? $query
            : $query->whereNotIn($query->getModel()->getQualifiedKeyName(), $ids);
    }

    /**
     * @return array<int, int>
     */
    private static function draftIdsWithVariantClash(): array
    {
        $ids = [];
        $columns = [
            'id',
            'handle',
            'shopify_id',
            'sku',
            'variant_price',
            'variant_compare_at_price',
            'variant_inventory_qty',
        ];

        if (NewProductDraft::supportsShopifySyncWarningsColumn()) {
            $columns[] = 'shopify_sync_warnings';
        }

        NewProductDraft::query()
            ->select($columns)
            ->with([
                'product:id,handle,shopify_id',
                'product.variants:id,product_id,sku,price,compare_at_price,inventory_tracked,inventory_qty,sync_state',
            ])
            ->chunkById(200, function ($drafts) use (&$ids): void {
                foreach ($drafts as $draft) {
                    if ($draft instanceof NewProductDraft && self::draftHasVariantClash($draft)) {
                        $ids[] = (int) $draft->id;
                    }
                }
            });

        return array_values(array_unique($ids));
    }

    private static function draftHasLinkedProductErrors(NewProductDraft $record): bool
    {
        return (bool) ($record->product?->has_errors ?? false);
    }

    private static function draftHasVariantClash(?NewProductDraft $record): bool
    {
        return $record instanceof NewProductDraft && self::draftVariantClashes($record) !== [];
    }

    /**
     * @return array<int, array{field:string,label:string,draft_value:string,existing_value:string,existing_label:string}>
     */
    private static function draftVariantClashes(NewProductDraft $record): array
    {
        $clashes = [];
        $seen = [];

        foreach ($record->shopifySyncWarnings() as $warning) {
            $field = trim((string) ($warning['field'] ?? ''));
            if (!isset(self::DRAFT_VARIANT_CLASH_FIELDS[$field])) {
                continue;
            }

            $seen[$field] = true;
            $definition = self::DRAFT_VARIANT_CLASH_FIELDS[$field];
            $clashes[] = [
                'field' => $field,
                'label' => (string) ($warning['label'] ?? $definition['label']),
                'draft_value' => self::formatShopifyWarningDisplayValue($field, (string) ($warning['draft_value'] ?? '')),
                'existing_value' => self::formatShopifyWarningDisplayValue($field, (string) ($warning['shopify_value'] ?? '')),
                'existing_label' => 'Shopify import',
            ];
        }

        $variant = self::linkedVariantForDraft($record);
        if (!$variant instanceof Variant) {
            return $clashes;
        }

        foreach (self::DRAFT_VARIANT_CLASH_FIELDS as $field => $definition) {
            if (isset($seen[$field])) {
                continue;
            }

            $draftValue = $record->getAttribute($field);
            if (self::isBlankDraftVariantValue($draftValue)) {
                continue;
            }

            $variantAttribute = (string) $definition['variant_attribute'];
            $variantValue = $variantAttribute === 'inventory_qty' && $variant->inventory_tracked === false
                ? null
                : $variant->getAttribute($variantAttribute);
            $type = (string) $definition['type'];

            if (self::normalizeDraftVariantComparableValue($type, $draftValue) === self::normalizeDraftVariantComparableValue($type, $variantValue)) {
                continue;
            }

            $clashes[] = [
                'field' => $field,
                'label' => (string) $definition['label'],
                'draft_value' => self::formatDraftVariantClashValue($type, $draftValue),
                'existing_value' => self::formatDraftVariantClashValue($type, $variantValue),
                'existing_label' => 'Existing variant',
            ];
        }

        if ($variant->sync_state === Variant::SYNC_STATE_CONFLICT) {
            $clashes[] = [
                'field' => 'variant_sync_state',
                'label' => 'Variant sync state',
                'draft_value' => 'Draft is linked to this variant',
                'existing_value' => 'Conflict in Products > Variants',
                'existing_label' => 'Existing variant',
            ];
        }

        return $clashes;
    }

    private static function draftVariantClashHtml(?NewProductDraft $record): ?HtmlString
    {
        if (!$record instanceof NewProductDraft) {
            return null;
        }

        $clashes = self::draftVariantClashes($record);
        if ($clashes === []) {
            return null;
        }

        $items = array_map(function (array $clash): string {
            $label = e($clash['label']);
            $draftValue = e($clash['draft_value']);
            $existingValue = e($clash['existing_value']);
            $existingLabel = e($clash['existing_label']);

            return "<li><strong>{$label}</strong>: draft has <code>{$draftValue}</code> but <strong>{$existingLabel}</strong> has <code>{$existingValue}</code>.</li>";
        }, $clashes);

        return new HtmlString(
            "<div class='rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-900'>"
            . "<p class='font-semibold mb-2'>Variant defaults clash with the linked product variant.</p>"
            . "<p class='mb-3'>Review these values before approving or syncing this draft. Use the Shopify sync warning actions above when a field is listed there, or edit the draft/product intentionally when the existing variant value is the source of truth.</p>"
            . "<ul class='list-disc pl-5 space-y-1'>"
            . implode('', $items)
            . '</ul>'
            . '</div>'
        );
    }

    private static function draftVariantClashSummary(NewProductDraft $record): string
    {
        $clashes = self::draftVariantClashes($record);
        if ($clashes === []) {
            return 'No variant clash detected.';
        }

        return implode('; ', array_map(
            fn (array $clash): string => "{$clash['label']}: draft {$clash['draft_value']} / {$clash['existing_label']} {$clash['existing_value']}",
            $clashes
        ));
    }

    private static function linkedVariantForDraft(NewProductDraft $record): ?Variant
    {
        $product = self::linkedProductForDraft($record);
        if (!$product instanceof Product) {
            return null;
        }

        if ($product->relationLoaded('variants')) {
            $variant = $product->variants
                ->sortBy('id')
                ->first();

            return $variant instanceof Variant ? $variant : null;
        }

        return $product->variants()
            ->orderBy('id')
            ->first();
    }

    private static function isBlankDraftVariantValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function normalizeDraftVariantComparableValue(string $type, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if ($type === 'integer') {
            return is_numeric($string) ? (string) (int) $string : $string;
        }

        if ($type === 'decimal2') {
            $normalized = str_replace(' ', '', $string);
            if (str_contains($normalized, ',') && !str_contains($normalized, '.')) {
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }

            return is_numeric($normalized)
                ? number_format((float) $normalized, 2, '.', '')
                : $string;
        }

        return $string;
    }

    private static function formatDraftVariantClashValue(string $type, mixed $value): string
    {
        $normalized = self::normalizeDraftVariantComparableValue($type, $value);

        return $normalized === null ? 'blank' : $normalized;
    }

    private static function draftApprovalStateLabel(NewProductDraft $record): string
    {
        if ($record->isApprovedByTwo()) {
            return 'Approved';
        }

        if ($record->isPendingApproval()) {
            return 'Pending Approval';
        }

        return 'Editing';
    }

    private static function draftApprovalStateColor(NewProductDraft $record): string
    {
        if ($record->isApprovedByTwo()) {
            return 'success';
        }

        if ($record->isPendingApproval()) {
            return 'warning';
        }

        return 'gray';
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

    private static function complementaryMinimumEnabled(): bool
    {
        return Setting::getBool('new_product_drafts.complementary_minimum.enabled', false);
    }

    private static function complementaryMinimumCount(): int
    {
        $configured = (int) Setting::getValue('new_product_drafts.complementary_minimum.count', 3);

        return max(1, $configured);
    }

    private static function sortDraftsByApprovalCount(Builder $query, string $direction): Builder
    {
        return $query->orderBy(
            NewProductDraftApproval::query()
                ->selectRaw('COUNT(DISTINCT user_id)')
                ->whereColumn('new_product_draft_approvals.new_product_draft_id', 'new_product_drafts.id')
                ->whereColumn('new_product_draft_approvals.approval_version', 'new_product_drafts.approval_version'),
            $direction
        );
    }

    private static function sortDraftsByThumbnail(Builder $query, string $direction): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        $productImageSubquery = Image::query()
            ->select('images.src')
            ->join('products as sortable_products', 'sortable_products.id', '=', 'images.product_id')
            ->whereColumn('sortable_products.handle', 'new_product_drafts.handle')
            ->whereNotIn('images.sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function (Builder $imageQuery): void {
                $imageQuery->whereNull('images.is_duplicate_hidden')
                    ->orWhere('images.is_duplicate_hidden', false);
            })
            ->orderByRaw('COALESCE(images.position, 2147483647)')
            ->limit(1);

        return $query->orderByRaw(
            "COALESCE((" . $productImageSubquery->toSql() . "), NULLIF(image_url, ''), NULLIF(image_path, '')) {$direction}",
            $productImageSubquery->getBindings()
        );
    }

    private static function sortDraftsByWarningCount(Builder $query, string $direction): Builder
    {
        if (!NewProductDraft::supportsShopifySyncWarningsColumn()) {
            return $query;
        }

        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query->orderByRaw("COALESCE(JSON_LENGTH(shopify_sync_warnings), 0) {$direction}");
    }

    private static function sortDraftsByLinkedProductColumn(Builder $query, string $column, string $direction): Builder
    {
        return $query->orderBy(
            Product::query()
                ->select($column)
                ->whereColumn('products.handle', 'new_product_drafts.handle')
                ->limit(1),
            $direction
        );
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
        return self::linkedProductFromState($get, $record) !== null;
    }

    private static function linkedProductFirstImageUrl(Get $get, ?NewProductDraft $record): ?string
    {
        $product = self::linkedProductFromState($get, $record, withImages: true);

        $src = $product?->images->first()?->src;
        $src = is_string($src) ? trim($src) : '';

        return $src !== '' ? $src : null;
    }

    private static function linkedProductForDraft(NewProductDraft $record): ?Product
    {
        if ($record->relationLoaded('product') && $record->product instanceof Product) {
            $shopifyId = trim((string) ($record->shopify_id ?? ''));
            $loadedShopifyId = trim((string) ($record->product->shopify_id ?? ''));

            if ($shopifyId === '' || $loadedShopifyId === $shopifyId) {
                return $record->product;
            }
        }

        $product = self::findLinkedProduct(
            is_string($record->shopify_id ?? null) ? $record->shopify_id : null,
            is_string($record->handle ?? null) ? $record->handle : null
        );

        return $product instanceof Product ? $product : null;
    }

    private static function linkedProductFromState(Get $get, ?NewProductDraft $record, bool $withImages = false): ?Product
    {
        $shopifyId = trim((string) ($get('shopify_id') ?? $record?->shopify_id ?? ''));
        $handle = trim((string) ($get('handle') ?? $record?->handle ?? ''));

        return self::findLinkedProduct($shopifyId !== '' ? $shopifyId : null, $handle !== '' ? $handle : null, $withImages);
    }

    private static function findLinkedProduct(?string $shopifyId, ?string $handle, bool $withImages = false): ?Product
    {
        $query = Product::query();

        if ($withImages) {
            $query->with(['images' => fn ($imageQuery) => $imageQuery->orderBy('position')]);
        }

        if ($shopifyId !== null && trim($shopifyId) !== '') {
            $product = (clone $query)
                ->where('shopify_id', trim($shopifyId))
                ->first();

            if ($product instanceof Product) {
                return $product;
            }
        }

        if ($handle !== null && trim($handle) !== '') {
            return $query
                ->where('handle', trim($handle))
                ->first();
        }

        return null;
    }

    private static function linkedProductEditUrl(NewProductDraft $record): ?string
    {
        $product = self::linkedProductForDraft($record);
        if (!$product) {
            return null;
        }

        return ProductResource::getUrl('edit', ['record' => $product]);
    }

    /**
     * @return array<int, array{key:string,value:string}>
     */
    private static function extraShopifyFieldsForDraft(?NewProductDraft $record): array
    {
        $headers = self::extraShopifyHeadersForDraft($record);
        $payload = is_array($record?->payload) ? $record->payload : [];

        return array_map(function (string $header) use ($payload, $record): array {
            $value = $payload[$header] ?? self::linkedDraftRowValue($record, $header);

            return [
                'key' => $header,
                'value' => is_scalar($value) ? (string) $value : '',
            ];
        }, $headers);
    }

    /**
     * @return array<int, string>
     */
    private static function extraShopifyHeadersForDraft(?NewProductDraft $record): array
    {
        $headers = [];

        $product = $record ? self::linkedProductForDraft($record) : null;
        if ($product) {
            $headers = $product->import?->headers ?? [];
        } else {
            $currentImport = Import::query()->where('is_current', true)->first();
            $headers = $currentImport?->headers ?? [];
        }

        if (empty($headers)) {
            $headers = self::templateHeaders();
        }

        return HeaderStore::extraProductHeadersForDraftWorkflow($headers);
    }

    private static function linkedDraftRowValue(?NewProductDraft $record, string $header): string
    {
        if (!$record || blank($record->handle)) {
            return '';
        }

        $product = self::linkedProductForDraft($record);
        if (!$product) {
            return '';
        }

        $row = ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->first();

        return is_string($row?->get($header, null)) ? (string) $row->get($header, '') : '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function mutateDraftFormData(array $data, ?NewProductDraft $record = null): array
    {
        $title = is_string($data['title'] ?? null)
            ? trim($data['title'])
            : trim((string) ($data['title'] ?? ''));

        $data['siblings_collection_name'] = $title !== ''
            ? $title
            : null;

        $data['payload'] = self::payloadFromExtraShopifyFields($data['extra_shopify_fields'] ?? null);
        unset($data['extra_shopify_fields']);

        $data = self::applyDraftSaleAndBundleData($data);

        if ($record) {
            $data = self::removeConflictingDraftInputs($data, $record);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function applyDraftSaleAndBundleData(array $data): array
    {
        $tags = self::normalizeTagList($data['tags'] ?? null);
        $isOnSale = array_key_exists('is_on_sale', $data)
            ? self::saleStateFromForm($data['is_on_sale'], null)
            : self::tagListContains($tags, self::SALE_TAG);

        if ($isOnSale) {
            $currentPrice = self::decimalStringFromState($data['variant_price'] ?? null);
            $compareAtPrice = self::decimalStringFromState($data['variant_compare_at_price'] ?? null);

            if ($compareAtPrice === null && $currentPrice !== null) {
                $data['variant_compare_at_price'] = $currentPrice;
                $data['variant_price'] = null;
            }
        }

        $data['is_on_sale'] = $isOnSale;
        $data['tags'] = TagNormalizer::normalizeFromArray(
            self::defaultedDraftTags($tags, $data['type'] ?? null, $isOnSale)
        );

        self::validateDraftSalePricing($data);

        $isBundleOrStack = self::isBundleOrStackState(
            $data['type'] ?? null,
            self::normalizeTagList($data['tags'] ?? null),
            $data['title'] ?? null
        );

        if (!$isBundleOrStack) {
            $data['bundle_product_ids'] = null;
            $data['bundle_image_urls'] = null;

            return $data;
        }

        $bundleImagesSubmitted = array_key_exists('bundle_image_urls', $data);

        $data['bundle_product_ids'] = self::nullableArray(
            self::normalizeBundleProductIds($data['bundle_product_ids'] ?? null)
        );
        $data['bundle_image_urls'] = self::nullableArray(
            self::normalizeBundleImageUrls($data['bundle_image_urls'] ?? null)
        );

        if (
            $bundleImagesSubmitted
            &&
            is_array($data['bundle_image_urls'] ?? null)
            && $data['bundle_image_urls'] !== []
            && blank($data['image_path'] ?? null)
        ) {
            $data['image_url'] = $data['bundle_image_urls'][0];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function validateDraftSalePricing(array $data): void
    {
        if (!self::saleStateFromForm($data['is_on_sale'] ?? false, $data['tags'] ?? null)) {
            return;
        }

        $price = self::decimalFloatFromState($data['variant_price'] ?? null);
        $compareAt = self::decimalFloatFromState($data['variant_compare_at_price'] ?? null);
        $messages = [];

        if ($compareAt === null) {
            $messages['variant_compare_at_price'] = 'Compare-at price is required before a product can be put on sale.';
        }

        if ($price === null) {
            $messages['variant_price'] = 'Enter the new sale price before saving.';
        } elseif ($compareAt !== null && $price >= $compareAt) {
            $messages['variant_price'] = 'Sale price must be lower than the compare-at price.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @return array<string, string>|null
     */
    private static function payloadFromExtraShopifyFields(mixed $state): ?array
    {
        if (!is_array($state)) {
            return null;
        }

        $payload = [];
        foreach ($state as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $value = trim((string) ($item['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $payload[$key] = $value;
        }

        return $payload === [] ? null : $payload;
    }

    /**
     * @return array<int, array{key:string,safe_key:string,source:string,attribute:string,label:string,type:string}>
     */
    private static function draftQuickEditableFields(): array
    {
        $labelOverrides = [
            'product|color_string' => 'Colors',
            'product|product_category' => 'Category',
            'product|google_product_category' => 'Google product category',
            'row|' . HeaderStore::JEWELRY_MATERIAL => 'Jewelry material',
            'row|' . HeaderStore::BRACELET_DESIGN => 'Bracelet design',
        ];

        $fields = [];

        foreach (
            RequiredField::query()
                ->where('quick_edit', true)
                ->whereIn('source', ['product', 'row', 'variant'])
                ->orderBy('label')
                ->get() as $field
        ) {
            if (!self::supportsDraftQuickField($field->source, $field->attribute)) {
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
                'type' => self::draftQuickFieldType($field->source, $field->attribute),
            ];
        }

        if ($fields !== []) {
            return $fields;
        }

        $fallback = [
            ['source' => 'product', 'attribute' => 'title', 'label' => 'Title', 'type' => 'text'],
            ['source' => 'row', 'attribute' => HeaderStore::SIBLINGS, 'label' => 'Siblings', 'type' => 'product_references'],
            ['source' => 'row', 'attribute' => HeaderStore::COMPLEMENTARY_PRODUCTS, 'label' => 'Complementary products', 'type' => 'product_references'],
        ];

        return array_map(function (array $field): array {
            $field['key'] = "{$field['source']}__{$field['attribute']}";
            $field['safe_key'] = 'f_' . md5($field['key']);

            return $field;
        }, $fallback);
    }

    private static function draftBulkEditFormSchema(): array
    {
        $fields = self::draftBulkEditableFields();
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

        $schema[] = Forms\Components\Grid::make(1)
            ->schema(array_map(function (array $field) {
                $safeKey = $field['safe_key'];
                $label = $field['label'];

                return Forms\Components\Grid::make(12)
                    ->schema([
                        Forms\Components\Checkbox::make("fields.{$safeKey}")
                            ->label($label)
                            ->live()
                            ->columnSpan(4),
                        self::draftBulkEditComponent($field)
                            ->label('')
                            ->statePath("values.{$safeKey}")
                            ->columnSpan(8)
                            ->disabled(fn (Get $get): bool => !($get("fields.{$safeKey}") ?? false))
                            ->dehydrated(fn (Get $get): bool => (bool) ($get("fields.{$safeKey}") ?? false)),
                    ]);
            }, $fields));

        return $schema;
    }

    private static function draftQuickEditFormSchema(?NewProductDraft $record = null): array
    {
        return self::draftEditSelectionSchema(
            'Quick edit hint - (Tick the fields to edit for this draft, then enter the new values.)',
            $record
        );
    }

    private static function draftEditSelectionSchema(string $hint, ?NewProductDraft $record = null): array
    {
        $fields = self::draftQuickEditableFields();
        if (empty($fields)) {
            return [
                Placeholder::make('no_draft_edit_fields')
                    ->content('No draft edit fields are configured.'),
            ];
        }

        $schema = [
            Placeholder::make('draft_edit_hint')
                ->label($hint)
                ->content(''),
        ];

        if (self::draftHasBlockingShopifyWarnings($record)) {
            $schema[] = Placeholder::make('draft_edit_conflict_notice')
                ->label('')
                ->content(fn (): HtmlString => self::draftConflictEditingNoticeHtml());
        }

        $schema[] = Forms\Components\Grid::make(1)
            ->schema(array_map(function (array $field) use ($record) {
                $safeKey = $field['safe_key'];
                $hasConflict = self::draftQuickFieldHasShopifyConflict($record, $field);
                $rowSchema = [
                    Forms\Components\Checkbox::make("fields.{$safeKey}")
                        ->label($field['label'])
                        ->live()
                        ->disabled($hasConflict)
                        ->columnSpan(4),
                    self::draftBulkEditComponent($field)
                        ->label('')
                        ->statePath("values.{$safeKey}")
                        ->columnSpan(8)
                        ->disabled(fn (Get $get): bool => $hasConflict || !($get("fields.{$safeKey}") ?? false))
                        ->dehydrated(fn (Get $get): bool => !$hasConflict && (bool) ($get("fields.{$safeKey}") ?? false)),
                ];

                if ($hasConflict) {
                    $rowSchema[] = Placeholder::make("conflict_notice_{$safeKey}")
                        ->label('')
                        ->content(fn (): HtmlString => self::draftConflictFieldNoticeHtml($field['label']))
                        ->columnSpan(12);
                }

                return Forms\Components\Grid::make(12)
                    ->schema($rowSchema);
            }, $fields));

        return $schema;
    }

    private static function draftBulkEditComponent(array $field): Forms\Components\Component
    {
        $name = $field['safe_key'];

        if (($field['source'] ?? 'product') === 'row' && in_array($field['attribute'], [
            HeaderStore::SIBLINGS,
            HeaderStore::COMPLEMENTARY_PRODUCTS,
        ], true)) {
            $isComplementary = $field['attribute'] === HeaderStore::COMPLEMENTARY_PRODUCTS;

            return Select::make($name)
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn (Get $get): array => self::productReferenceOptions(
                    $get("values.{$name}")
                ))
                ->helperText($isComplementary
                    ? fn (): ?HtmlString => self::complementaryMinimumEnabled()
                        ? new HtmlString('<span class="text-gray-600">Minimum required: ' . e((string) self::complementaryMinimumCount()) . ' complementary products.</span>')
                        : null
                    : null)
                ->rules([
                    function () use ($isComplementary): \Closure {
                        return function (string $attribute, $value, $fail) use ($isComplementary): void {
                            $invalid = self::invalidProductReferenceStatusLabels($value);
                            if (!empty($invalid)) {
                                $fail('Inactive products selected: ' . implode('; ', $invalid));
                            }

                            if ($isComplementary && self::complementaryMinimumEnabled()) {
                                $selected = self::parseProductReferenceState($value);
                                $minimum = self::complementaryMinimumCount();
                                if (count($selected) < $minimum) {
                                    $fail("Select at least {$minimum} complementary products.");
                                }
                            }
                        };
                    },
                ]);
        }

        if (($field['source'] ?? 'product') === 'row' && $field['attribute'] === HeaderStore::SIBLING_COLLECTION) {
            return Select::make($name)
                ->options(fn (): array => self::siblingCollectionOptions())
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::siblingCollectionSearchResults($search))
                ->getOptionLabelUsing(fn ($value): ?string => self::siblingCollectionDisplayLabel(
                    is_string($value) ? $value : null
                ));
        }

        if (($field['source'] ?? 'product') === 'product' && $field['attribute'] === 'tags') {
            return Select::make($name)
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn () => Tag::query()->orderBy('name')->pluck('name', 'name')->all());
        }

        if (($field['source'] ?? 'product') === 'product' && $field['attribute'] === 'color_string') {
            return Select::make($name)
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn (): array => self::dropdownOptionsForHeader(HeaderStore::COLOR_METAFIELD));
        }

        if (($field['source'] ?? 'product') === 'product' && $field['attribute'] === 'type') {
            return Select::make($name)
                ->options(array_combine(CategoryTypeMap::types(), CategoryTypeMap::types()))
                ->searchable();
        }

        if (($field['source'] ?? 'product') === 'product' && $field['attribute'] === 'product_category') {
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

        if (($field['source'] ?? 'product') === 'product' && $field['attribute'] === 'status') {
            return Select::make($name)
                ->options(fn () => Status::query()->orderBy('name')->pluck('name', 'name')->all())
                ->searchable();
        }

        if (($field['source'] ?? 'product') === 'product' && $field['attribute'] === 'published') {
            return Forms\Components\Toggle::make($name);
        }

        if (($field['source'] ?? 'row') === 'row' && $field['attribute'] === HeaderStore::SEO_DEINDEX) {
            return Forms\Components\Toggle::make($name);
        }

        if (($field['source'] ?? 'product') === 'product' && $field['attribute'] === 'body_html') {
            return Textarea::make($name)->rows(4);
        }

        return match ($field['type'] ?? 'text') {
            'textarea' => Textarea::make($name)->rows(4),
            'numeric' => TextInput::make($name)->numeric(),
            default => TextInput::make($name),
        };
    }

    /**
     * @return array<int, array{key:string,safe_key:string,source:string,attribute:string,label:string}>
     */
    private static function draftBulkEditableFields(): array
    {
        $labelOverrides = [
            'product|color_string' => 'Colors',
            'product|product_category' => 'Category',
            'product|google_product_category' => 'Google product category',
            'product|seo_title' => 'SEO title',
            'product|seo_description' => 'SEO description',
            'row|' . HeaderStore::JEWELRY_MATERIAL => 'Jewelry material',
            'row|' . HeaderStore::BRACELET_DESIGN => 'Bracelet design',
        ];

        $lockedProductFields = [
            'handle',
            'title',
            'body_html',
            'seo_title',
            'seo_description',
        ];

        $lockedRowFields = [
            HeaderStore::IMAGE_SRC,
            HeaderStore::IMAGE_ALT_TEXT,
            HeaderStore::IMAGE_POSITION,
        ];

        $fields = [];

        foreach (
            RequiredField::query()
                ->where('bulk_editable', true)
                ->whereIn('source', ['product', 'row'])
                ->orderBy('label')
                ->get() as $field
        ) {
            if ($field->source === 'product' && in_array($field->attribute, $lockedProductFields, true)) {
                continue;
            }

            if ($field->source === 'row' && in_array($field->attribute, $lockedRowFields, true)) {
                continue;
            }

            if (!self::supportsDraftBulkField($field->source, $field->attribute)) {
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

    private static function supportsDraftBulkField(string $source, string $attribute): bool
    {
        if ($source === 'product') {
            return in_array($attribute, [
                'vendor',
                'tags',
                'type',
                'published',
                'product_category',
                'google_product_category',
                'status',
                'batch',
                'color_string',
            ], true);
        }

        if ($source === 'row') {
            return $attribute !== HeaderStore::SIBLINGS_COLLECTION_NAME
                && self::draftAttributeForBulkRowHeader($attribute) !== null;
        }

        return false;
    }

    private static function draftAttributeForBulkRowHeader(string $header): ?string
    {
        return match ($header) {
            HeaderStore::COST_PER_ITEM,
            HeaderStore::MATERIAL_COST => 'material_cost',
            HeaderStore::JEWELRY_MATERIAL => 'jewelry_material',
            HeaderStore::PRODUCT_MATERIALS => 'product_materials',
            HeaderStore::MATERIALS_AND_DIMENSIONS => 'materials_and_dimensions',
            HeaderStore::BRACELET_DESIGN,
            HeaderStore::NECKLACE_DESIGN,
            HeaderStore::EARRING_DESIGN => 'product_design',
            HeaderStore::PRODUCT_METALS => 'metal',
            HeaderStore::PATTERN_CATEGORY => 'colour_style',
            HeaderStore::SIZE => 'size',
            HeaderStore::SIBLINGS => 'siblings',
            HeaderStore::SIBLINGS_COLLECTION_NAME => 'siblings_collection_name',
            HeaderStore::SIBLING_COLLECTION => 'sibling_collection',
            HeaderStore::UVP_SHORT_PARAGRAPH => 'uvp_short_paragraph',
            HeaderStore::COMPLEMENTARY_PRODUCTS => 'complementary_products',
            HeaderStore::SEO_DEINDEX => 'seo_deindex',
            default => null,
        };
    }

    /**
     * @return array{fields:array<string,bool>,values:array<string,mixed>}
     */
    private static function draftQuickEditDefaults(NewProductDraft $record): array
    {
        $values = [];
        foreach (self::draftQuickEditableFields() as $field) {
            $styleProfileAttribute = self::draftStyleProfileAttribute($field['source'], $field['attribute']);
            if ($styleProfileAttribute !== null) {
                $values[$field['safe_key']] = self::draftStyleProfileDefaultValue($record, $styleProfileAttribute);
                continue;
            }

            $attribute = self::draftAttributeForQuickField($field['source'], $field['attribute']);

            if ($field['source'] === 'row' && $attribute === null) {
                $payload = is_array($record->payload) ? $record->payload : [];
                $values[$field['safe_key']] = array_key_exists($field['attribute'], $payload)
                    ? (string) ($payload[$field['attribute']] ?? '')
                    : self::linkedDraftRowValue($record, $field['attribute']);
                continue;
            }

            if ($attribute === null) {
                continue;
            }

            $value = $record->getAttribute($attribute);

            if ($attribute === 'tags') {
                $value = self::normalizeTagList($value);
            } elseif ($attribute === 'color_string') {
                $value = is_string($value)
                    ? array_values(array_unique(array_filter(array_map(
                        static fn (string $token): string => trim($token),
                        preg_split('/\s*;\s*/', $value) ?: []
                    ))))
                    : [];
            } elseif ($attribute === 'published') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($attribute === 'seo_deindex') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (in_array($attribute, ['siblings', 'complementary_products'], true)) {
                $value = self::parseProductReferenceState($value);
            }

            $values[$field['safe_key']] = $value;
        }

        return [
            'fields' => [],
            'values' => $values,
        ];
    }

    private static function applyQuickEditsToDraft(NewProductDraft $record, array $data): void
    {
        self::applySelectedDraftEdits([$record], $data);
    }

    private static function applyBulkEditsToDrafts(iterable $records, array $data): void
    {
        $selected = array_keys(array_filter($data['fields'] ?? []));
        $values = $data['values'] ?? [];
        if (!is_array($selected) || empty($selected)) {
            return;
        }

        $fieldMap = [];
        foreach (self::draftBulkEditableFields() as $field) {
            $fieldMap[$field['safe_key']] = $field;
        }

        $savedDrafts = 0;
        $skippedConflicts = 0;

        foreach ($records as $record) {
            if (!$record instanceof NewProductDraft) {
                continue;
            }

            $updates = [];

            foreach ($selected as $safeKey) {
                $field = $fieldMap[$safeKey] ?? null;
                if (!$field) {
                    continue;
                }

                if (self::draftQuickFieldHasShopifyConflict($record, $field)) {
                    $skippedConflicts++;
                    continue;
                }

                $value = $values[$safeKey] ?? null;

                if ($field['source'] === 'product') {
                    $attribute = $field['attribute'];

                    if ($attribute === 'tags') {
                        $updates['tags'] = TagNormalizer::normalizeFromArray(is_array($value) ? $value : []);
                        continue;
                    }

                    if ($attribute === 'color_string') {
                        $arr = is_array($value) ? $value : [];
                        $clean = array_values(array_unique(array_filter(array_map(
                            fn ($v) => trim((string) $v),
                            $arr
                        ))));
                        $updates['color_string'] = $clean ? implode('; ', $clean) : null;
                        continue;
                    }

                    if ($attribute === 'published') {
                        $updates['published'] = $value ? 'true' : 'false';
                        continue;
                    }

                    $updates[$attribute] = $value;
                    continue;
                }

                if ($field['source'] === 'row') {
                    $draftAttribute = self::draftAttributeForQuickField($field['source'], $field['attribute']);
                    if ($draftAttribute === null) {
                        continue;
                    }

                    if ($draftAttribute === 'seo_deindex') {
                        $updates[$draftAttribute] = (bool) $value;
                        continue;
                    }

                    $updates[$draftAttribute] = self::nullIfEmpty($value);
                }
            }

            if (empty($updates)) {
                continue;
            }

            $record->fill($updates)->save();
            $savedDrafts++;
        }

        $notification = Notification::make()
            ->title($savedDrafts > 0 ? 'Bulk draft edits saved' : 'No bulk draft edits saved');

        if ($skippedConflicts > 0) {
            $notification
                ->warning()
                ->body("{$savedDrafts} draft(s) were updated. {$skippedConflicts} conflicting field update(s) were skipped. Scroll up and resolve Shopify conflicts first for the locked fields.");
        } else {
            $notification->success();
        }

        self::sendNotification($notification);
    }

    private static function applySelectedDraftEdits(iterable $records, array $data): void
    {
        $selected = array_keys(array_filter($data['fields'] ?? []));
        $values = $data['values'] ?? [];
        if (!is_array($selected) || empty($selected)) {
            return;
        }

        $fieldMap = [];
        foreach (self::draftQuickEditableFields() as $field) {
            $fieldMap[$field['safe_key']] = $field;
        }

        $savedDrafts = 0;
        $skippedConflicts = 0;

        foreach ($records as $record) {
            if (!$record instanceof NewProductDraft) {
                continue;
            }

            $updates = [];
            $payload = is_array($record->payload) ? $record->payload : [];
            $styleProfileUpdates = [];
            foreach ($selected as $safeKey) {
                $field = $fieldMap[$safeKey] ?? null;
                if (!$field) {
                    continue;
                }

                if (self::draftQuickFieldHasShopifyConflict($record, $field)) {
                    $skippedConflicts++;
                    continue;
                }

                if ($field['source'] === 'row') {
                    $value = $values[$safeKey] ?? null;
                    $draftAttribute = self::draftAttributeForQuickField($field['source'], $field['attribute']);

                    if ($draftAttribute === null) {
                        $payload[$field['attribute']] = self::quickEditPayloadValue($value);
                        $updates['payload'] = $payload;
                        continue;
                    }

                    if (in_array($draftAttribute, ['siblings', 'complementary_products'], true)) {
                        $updates[$draftAttribute] = self::dehydrateProductReferenceState($value);
                        continue;
                    }

                    if ($draftAttribute === 'sibling_collection') {
                        $updates[$draftAttribute] = self::normalizeSiblingCollectionValue($value);
                        continue;
                    }

                    if ($draftAttribute === 'seo_deindex') {
                        $updates[$draftAttribute] = (bool) $value;
                        continue;
                    }

                    $updates[$draftAttribute] = self::nullIfEmpty($value);
                    continue;
                }

                if ($field['source'] === 'variant') {
                    $draftAttribute = self::draftAttributeForQuickField($field['source'], $field['attribute']);
                    if ($draftAttribute === null) {
                        continue;
                    }

                    $updates[$draftAttribute] = self::nullIfEmpty($value);
                    continue;
                }

                $attribute = $field['attribute'];
                $value = $values[$safeKey] ?? null;
                $styleProfileAttribute = self::draftStyleProfileAttribute($field['source'], $attribute);
                if ($styleProfileAttribute !== null) {
                    $styleProfileUpdates[$styleProfileAttribute] = self::nullIfEmpty($value);
                    continue;
                }

                if ($attribute === 'tags') {
                    $updates['tags'] = TagNormalizer::normalizeFromArray(is_array($value) ? $value : []);
                    continue;
                }

                if ($attribute === 'color_string') {
                    $selectedColors = is_array($value) ? $value : [];
                    $clean = array_values(array_unique(array_filter(array_map(
                        fn ($token) => trim((string) $token),
                        $selectedColors
                    ))));
                    $updates['color_string'] = $clean === [] ? null : implode('; ', $clean);
                    continue;
                }

                if ($attribute === 'published') {
                    $updates['published'] = $value ? 'true' : 'false';
                    continue;
                }

                if ($attribute === 'product_category') {
                    if (!is_string($value) || trim($value) === '') {
                        $updates[$attribute] = null;
                    } else {
                        $mapping = CategoryTypeMap::byCategoryValue($value);
                        $updates[$attribute] = $mapping['shopify_taxonomy_gid'] ?? $value;
                    }
                    continue;
                }

                $updates[$attribute] = self::nullIfEmpty($value);
            }

            if ($updates === [] && $styleProfileUpdates === []) {
                continue;
            }

            if (array_key_exists('product_category', $updates)) {
                $mapping = CategoryTypeMap::byCategoryValue(
                    is_string($updates['product_category']) ? $updates['product_category'] : null
                );
                if ($mapping) {
                    $updates['type'] = $mapping['type'];
                    $updates['google_product_category'] = $mapping['google_product_category'];
                }
            } elseif (array_key_exists('type', $updates)) {
                $mapping = CategoryTypeMap::byType(
                    is_string($updates['type']) ? $updates['type'] : null
                );
                if ($mapping) {
                    $updates['product_category'] = $mapping['shopify_taxonomy_gid'] ?? $mapping['category'];
                    $updates['google_product_category'] = $mapping['google_product_category'];
                }
            }

            $recordUpdated = false;

            if ($updates !== []) {
                $record->fill($updates)->save();
                $recordUpdated = true;
            }

            if ($styleProfileUpdates !== []) {
                self::upsertDraftStyleProfile($record, $styleProfileUpdates);
                $recordUpdated = true;
            }

            if ($recordUpdated) {
                $savedDrafts++;
            }
        }

        $notification = Notification::make()
            ->title($savedDrafts > 0 ? 'Draft edits saved' : 'No draft edits saved');

        if ($skippedConflicts > 0) {
            $notification
                ->warning()
                ->body("{$skippedConflicts} conflicting field update(s) were skipped. Scroll up and resolve Shopify conflicts first for the locked fields.");
        } else {
            $notification->success();
        }

        self::sendNotification($notification);
    }

    private static function supportsDraftQuickField(string $source, string $attribute): bool
    {
        if ($source === 'product') {
            return in_array($attribute, [
                'title',
                'sku',
                'vendor',
                'type',
                'product_category',
                'google_product_category',
                'seo_title',
                'seo_description',
                'tags',
                'status',
                'published',
                'body_html',
                'color_string',
                'variant_price',
                'variant_compare_at_price',
                'variant_inventory_qty',
                'material_cost',
                'uvp_short_paragraph',
                'seo_deindex',
                'batch',
            ], true);
        }

        if ($source === 'row') {
            return trim($attribute) !== ''
                && $attribute !== HeaderStore::SIBLINGS_COLLECTION_NAME;
        }

        if ($source === 'variant') {
            return self::draftAttributeForQuickField($source, $attribute) !== null;
        }

        return false;
    }

    private static function draftQuickFieldType(string $source, string $attribute): string
    {
        if ($source === 'row' && in_array($attribute, [
            HeaderStore::SIBLINGS,
            HeaderStore::COMPLEMENTARY_PRODUCTS,
        ], true)) {
            return 'product_references';
        }

        return match ($attribute) {
            'price', 'compare_at_price', 'inventory_qty' => 'numeric',
            'type' => 'type',
            'product_category' => 'category',
            'tags' => 'tags',
            'status' => 'status',
            'published', 'seo_deindex' => 'published',
            'body_html', 'seo_description', 'uvp_short_paragraph' => 'textarea',
            'variant_price', 'variant_compare_at_price', 'variant_inventory_qty', 'material_cost' => 'numeric',
            default => 'text',
        };
    }

    private static function draftAttributeForQuickField(string $source, string $attribute): ?string
    {
        if ($source === 'row') {
            if ($attribute === HeaderStore::VARIANT_INVENTORY_QTY) {
                return 'variant_inventory_qty';
            }

            return self::draftAttributeForBulkRowHeader($attribute);
        }

        if ($source === 'variant') {
            return match ($attribute) {
                'sku' => 'sku',
                'price' => 'variant_price',
                'compare_at_price' => 'variant_compare_at_price',
                'inventory_qty' => 'variant_inventory_qty',
                default => null,
            };
        }

        return $attribute;
    }

    public static function seoDraftFormSchema(?NewProductDraft $ownerRecord): array
    {
        $schema = [];

        if (self::draftHasBlockingShopifyWarnings($ownerRecord)) {
            $schema[] = Placeholder::make('seo_draft_conflict_notice')
                ->label('')
                ->content(fn (): HtmlString => self::draftConflictEditingNoticeHtml());
        }

        $schema = array_merge($schema, [
            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('sku')
                        ->maxLength(80)
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, mixed $record) use ($ownerRecord): void {
                            $component->state(self::resolvedSeoDraftSku(
                                $ownerRecord,
                                self::resolveSeoDraftFormStyleProfileRecord($record)
                            ));
                        }),

                    Forms\Components\TextInput::make('style_type')
                        ->label('Product Type')
                        ->maxLength(120)
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, mixed $record) use ($ownerRecord): void {
                            $component->state(self::resolvedSeoDraftProduct(
                                $ownerRecord,
                                self::resolveSeoDraftFormStyleProfileRecord($record)
                            )?->type);
                        }),
                ])
                ->columnSpanFull(),

            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('product_colors')
                        ->label('Colors')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, mixed $record) use ($ownerRecord): void {
                            $component->state(self::resolvedSeoDraftProduct(
                                $ownerRecord,
                                self::resolveSeoDraftFormStyleProfileRecord($record)
                            )?->color_string);
                        }),
                    Forms\Components\TextInput::make('jewelry_material_display')
                        ->label('Jewelry material')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, mixed $record) use ($ownerRecord): void {
                            $component->state(self::resolvedSeoDraftJewelryMaterial(
                                self::resolvedSeoDraftProduct(
                                    $ownerRecord,
                                    self::resolveSeoDraftFormStyleProfileRecord($record)
                                )
                            ));
                        }),
                ])
                ->columnSpanFull(),

            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\Textarea::make('product_description')
                        ->label('Description')
                        ->rows(4)
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\Textarea $component, mixed $record) use ($ownerRecord): void {
                            $component->state(self::resolvedSeoDraftProductDescription(
                                self::resolvedSeoDraftProduct(
                                    $ownerRecord,
                                    self::resolveSeoDraftFormStyleProfileRecord($record)
                                )
                            ));
                        }),
                    Forms\Components\Textarea::make('colour_prompt')
                        ->rows(4)
                        ->default(fn (): ?string => self::defaultSeoDraftFieldValue($ownerRecord, 'colour_prompt')),
                ])
                ->columnSpanFull(),

            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('materials')
                        ->maxLength(255)
                        ->default(fn (): ?string => self::defaultSeoDraftFieldValue($ownerRecord, 'materials')),
                    Forms\Components\TextInput::make('components')
                        ->maxLength(255)
                        ->default(fn (): ?string => self::defaultSeoDraftFieldValue($ownerRecord, 'components')),
                ])
                ->columnSpanFull(),

            Forms\Components\TextInput::make('draft_seo_title')
                ->label('SEO Title')
                ->default(fn (): ?string => self::defaultSeoDraftFieldValue($ownerRecord, 'draft_seo_title'))
                ->live(debounce: 500)
                ->disabled(self::draftAttributeHasShopifyConflict($ownerRecord, 'seo_title'))
                ->helperText(fn (Forms\Get $get): string => StyleProfile::seoTitleLengthHint($get('draft_seo_title')))
                ->maxLength(StyleProfile::SEO_TITLE_RECOMMENDED_MAX)
                ->rules([
                    function (): \Closure {
                        return function (string $attribute, $value, $fail): void {
                            $length = StyleProfile::trimmedLength($value);
                            if ($length > 0 && $length < StyleProfile::SEO_TITLE_RECOMMENDED_MIN) {
                                $fail('SEO title is too short. Use at least ' . StyleProfile::SEO_TITLE_RECOMMENDED_MIN . ' characters.');
                            }
                        };
                    },
                ])
                ->columnSpanFull(),

            Forms\Components\Textarea::make('draft_seo_description')
                ->label('SEO Description (150-160 chars)')
                ->default(fn (): ?string => self::defaultSeoDraftFieldValue($ownerRecord, 'draft_seo_description'))
                ->live(onBlur: true)
                ->disabled(self::draftAttributeHasShopifyConflict($ownerRecord, 'seo_description'))
                ->helperText(fn (Forms\Get $get): string => StyleProfile::seoDescriptionLengthHint($get('draft_seo_description')))
                ->rows(2)
                ->maxLength(StyleProfile::SEO_DESCRIPTION_RECOMMENDED_MAX)
                ->rules([
                    function (): \Closure {
                        return function (string $attribute, $value, $fail): void {
                            $length = StyleProfile::trimmedLength($value);
                            if ($length > 0 && $length < StyleProfile::SEO_DESCRIPTION_RECOMMENDED_MIN) {
                                $fail('SEO description is too short. Use at least ' . StyleProfile::SEO_DESCRIPTION_RECOMMENDED_MIN . ' characters.');
                            }
                        };
                    },
                ])
                ->columnSpanFull(),
        ]);

        return $schema;
    }

    public static function seoDraftFormData(NewProductDraft $record): array
    {
        $styleProfile = self::seoDraftStyleProfile($record);
        $product = self::resolvedSeoDraftProduct($record, $styleProfile);

        return [
            'sku' => self::resolvedSeoDraftSku($record, $styleProfile),
            'style_type' => $product?->type,
            'product_colors' => $product?->color_string,
            'jewelry_material_display' => self::resolvedSeoDraftJewelryMaterial($product),
            'materials' => self::defaultSeoDraftFieldValue($record, 'materials', $styleProfile),
            'components' => self::defaultSeoDraftFieldValue($record, 'components', $styleProfile),
            'colour_prompt' => self::defaultSeoDraftFieldValue($record, 'colour_prompt', $styleProfile),
            'product_description' => self::resolvedSeoDraftProductDescription($product),
            'draft_seo_title' => self::defaultSeoDraftFieldValue($record, 'draft_seo_title', $styleProfile),
            'draft_seo_description' => self::defaultSeoDraftFieldValue($record, 'draft_seo_description', $styleProfile),
        ];
    }

    public static function seoDraftModalHeading(?NewProductDraft $ownerRecord, ?StyleProfile $styleProfile = null): string|HtmlString
    {
        $title = self::resolvedSeoDraftProduct($ownerRecord, $styleProfile)?->title
            ?? $ownerRecord?->title;

        if (!$title) {
            return 'SEO Draft';
        }

        return new HtmlString('SEO Draft for <em>' . e($title) . '</em>');
    }

    public static function normalizeSeoDraftFormData(?NewProductDraft $ownerRecord, array $data): array
    {
        $product = self::resolvedSeoDraftProduct($ownerRecord);

        $data['handle'] = $ownerRecord?->handle;
        $data['product_id'] = $product?->id;
        $data['sku'] = self::resolvedSeoDraftSku($ownerRecord) ?? self::nullIfEmpty($data['sku'] ?? null);

        if (empty($data['image_url'])) {
            $imageUrl = $product?->images()
                ->orderBy('position')
                ->value('src');

            if ($imageUrl) {
                $data['image_url'] = $imageUrl;
            }
        }

        foreach (['materials', 'components', 'colour_prompt', 'draft_seo_title', 'draft_seo_description'] as $field) {
            $data[$field] = self::nullIfEmpty($data[$field] ?? null);
        }

        return $data;
    }

    public static function saveSeoDraft(NewProductDraft $record, array $data): StyleProfile
    {
        if (blank(trim((string) ($record->handle ?? '')))) {
            throw new \InvalidArgumentException('Draft needs a handle before an SEO draft can be saved.');
        }

        $normalized = self::normalizeSeoDraftFormData($record, $data);
        $updates = [];

        foreach (['materials', 'components', 'colour_prompt', 'draft_seo_title', 'draft_seo_description'] as $field) {
            if (array_key_exists($field, $normalized)) {
                if ($field === 'draft_seo_title' && self::draftAttributeHasShopifyConflict($record, 'seo_title')) {
                    continue;
                }

                if ($field === 'draft_seo_description' && self::draftAttributeHasShopifyConflict($record, 'seo_description')) {
                    continue;
                }

                $updates[$field] = $normalized[$field];
            }
        }

        self::upsertDraftStyleProfile($record, $updates);

        return self::seoDraftStyleProfile($record->fresh('styleProfiles') ?? $record)
            ?? StyleProfile::query()
                ->where('handle', $record->handle)
                ->latest('id')
                ->firstOrFail();
    }

    private static function draftStyleProfileAttribute(string $source, string $attribute): ?string
    {
        if ($source !== 'product') {
            return null;
        }

        return match ($attribute) {
            'seo_title' => 'draft_seo_title',
            'seo_description' => 'draft_seo_description',
            default => null,
        };
    }

    private static function draftStyleProfileDefaultValue(NewProductDraft $record, string $attribute): ?string
    {
        return self::defaultSeoDraftFieldValue($record, $attribute);
    }

    private static function seoDraftStyleProfile(NewProductDraft $record): ?StyleProfile
    {
        $profile = $record->relationLoaded('styleProfiles')
            ? $record->styleProfiles->first()
            : $record->styleProfiles()->first();

        return $profile instanceof StyleProfile ? $profile : null;
    }

    private static function resolveSeoDraftFormStyleProfileRecord(mixed $record): ?StyleProfile
    {
        return $record instanceof StyleProfile ? $record : null;
    }

    private static function defaultSeoDraftFieldValue(
        ?NewProductDraft $ownerRecord,
        string $attribute,
        ?StyleProfile $styleProfile = null
    ): ?string {
        $styleProfile ??= $ownerRecord ? self::seoDraftStyleProfile($ownerRecord) : null;

        $value = self::nullIfEmpty($styleProfile?->{$attribute});
        if ($value !== null) {
            return $value;
        }

        $product = self::resolvedSeoDraftProduct($ownerRecord, $styleProfile);

        return match ($attribute) {
            'draft_seo_title' => self::nullIfEmpty($product?->seo_title),
            'draft_seo_description' => self::nullIfEmpty($product?->seo_description),
            default => null,
        };
    }

    private static function resolvedSeoDraftProduct(?NewProductDraft $ownerRecord, ?StyleProfile $styleProfile = null): ?Product
    {
        if ($styleProfile?->relationLoaded('product') && $styleProfile->product) {
            return $styleProfile->product;
        }

        if ($styleProfile?->product) {
            return $styleProfile->product;
        }

        if ($ownerRecord) {
            $product = self::linkedProductForDraft($ownerRecord);
            if ($product) {
                return $product;
            }
        }

        $handle = trim((string) ($styleProfile?->handle ?? $ownerRecord?->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        return Product::query()
            ->where('handle', $handle)
            ->first();
    }

    private static function resolvedSeoDraftSku(?NewProductDraft $ownerRecord, ?StyleProfile $styleProfile = null): ?string
    {
        $sku = self::nullIfEmpty($styleProfile?->sku);
        if ($sku !== null) {
            return $sku;
        }

        $product = self::resolvedSeoDraftProduct($ownerRecord, $styleProfile);
        $sku = self::nullIfEmpty(
            $product?->variants()->orderBy('id')->value('sku')
            ?? $ownerRecord?->sku
            ?? $ownerRecord?->handle
        );

        return $sku;
    }

    private static function resolvedSeoDraftJewelryMaterial(?Product $product): ?string
    {
        if (!$product) {
            return null;
        }

        $row = ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->first();

        return self::nullIfEmpty($row?->get(HeaderStore::JEWELRY_MATERIAL, ''));
    }

    private static function resolvedSeoDraftProductDescription(?Product $product): ?string
    {
        if (!$product) {
            return null;
        }

        return self::nullIfEmpty(trim(strip_tags((string) ($product->body_html ?? ''))));
    }

    private static function quickEditPayloadValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode('; ', array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $value
            ))));
        }

        return trim((string) ($value ?? ''));
    }

    private static function upsertDraftStyleProfile(NewProductDraft $record, array $updates): void
    {
        $handle = trim((string) ($record->handle ?? ''));
        if ($handle === '') {
            return;
        }

        $product = self::linkedProductForDraft($record);
        $styleProfile = StyleProfile::query()
            ->where('handle', $handle)
            ->first();

        if (!$styleProfile) {
            $styleProfile = new StyleProfile([
                'handle' => $handle,
            ]);
        }

        $styleProfile->product_id = $product?->id;
        $styleProfile->handle = $handle;

        if (!filled($styleProfile->sku)) {
            $styleProfile->sku = trim((string) (
                $record->sku
                ?? $product?->variants()->orderBy('id')->value('sku')
                ?? $handle
            )) ?: null;
        }

        if (!filled($styleProfile->image_url)) {
            $styleProfile->image_url = $product?->images()->orderBy('position')->value('src') ?? $record->imageUrl();
        }

        $styleProfile->fill($updates);
        $styleProfile->save();
    }

    private static function sendNotification(Notification $notification): void
    {
        AdminNotification::send($notification);
    }

    private static function shopifySyncWarningsHtml(?NewProductDraft $record): ?HtmlString
    {
        if (!$record) {
            return null;
        }

        $warnings = $record->shopifySyncWarnings();
        if (empty($warnings)) {
            return null;
        }

        $items = array_map(function (array $warning): string {
            $field = trim((string) ($warning['field'] ?? ''));
            $label = e((string) ($warning['label'] ?? $warning['field'] ?? 'Field'));
            $draftValue = e(self::formatShopifyWarningDisplayValue($field, (string) ($warning['draft_value'] ?? '')));
            $shopifyValue = e(self::formatShopifyWarningDisplayValue($field, (string) ($warning['shopify_value'] ?? '')));
            $encodedField = e($field);

            $actions = $field === ''
                ? ''
                : "<div class='mt-2 flex flex-wrap gap-2'>"
                    . "<button type='button' wire:click=\"resolveSingleShopifyWarningUsingShopify('{$encodedField}')\" wire:loading.attr='disabled' class='inline-flex items-center rounded-lg bg-warning-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-warning-500'>Use Shopify For This Field</button>"
                    . "<button type='button' wire:click=\"resolveSingleShopifyWarningKeepingDraft('{$encodedField}')\" wire:loading.attr='disabled' class='inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50'>Keep Draft For This Field</button>"
                    . "</div>";

            return "<li><strong>{$label}</strong>: draft has <code>{$draftValue}</code> but <strong>Shopify</strong> imported <code>{$shopifyValue}</code>.{$actions}</li>";
        }, $warnings);

        return new HtmlString(
            "<div class='rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-900'>"
            . "<p class='font-semibold mb-2'>Draft values differ from the latest <strong>Shopify</strong> import.</p>"
            . "<p class='mb-3'>Resolve each conflicting field separately below, or use the bulk actions underneath to apply one decision to every warning.</p>"
            . "<ul class='list-disc pl-5 space-y-1'>"
            . implode('', $items)
            . '</ul>'
            . '</div>'
        );
    }

    private static function formatShopifyWarningDisplayValue(string $field, string $value): string
    {
        return match ($field) {
            'complementary_products' => self::formatComplementaryWarningDisplayValue($value),
            default => $value,
        };
    }

    private static function formatComplementaryWarningDisplayValue(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }

        $parts = preg_split('/[,\n\r;]+/', $raw) ?: [];
        $tokens = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $parts
        ), static fn (string $item): bool => $item !== ''));

        if ($tokens === []) {
            return $raw;
        }

        $normalizedByToken = [];
        foreach ($tokens as $token) {
            $normalizedByToken[$token] = self::normalizeComplementaryWarningToken($token);
        }

        $titlesByNormalized = Product::query()
            ->select(['title', 'shopify_id', 'handle'])
            ->where(function (Builder $query) use ($normalizedByToken): void {
                foreach (array_unique(array_values($normalizedByToken)) as $normalized) {
                    if ($normalized === '') {
                        continue;
                    }

                    if (str_starts_with($normalized, 'gid://shopify/product/')) {
                        $numericId = substr($normalized, strlen('gid://shopify/product/'));
                        if ($numericId !== '') {
                            $query->orWhere('shopify_id', 'gid://shopify/Product/' . $numericId);
                        }

                        continue;
                    }

                    $query->orWhere('handle', $normalized)
                        ->orWhereRaw('LOWER(title) = ?', [str_replace('-', ' ', $normalized)]);
                }
            })
            ->get()
            ->mapWithKeys(function (Product $product): array {
                $map = [];

                $shopifyId = trim((string) ($product->shopify_id ?? ''));
                if ($shopifyId !== '') {
                    $map[self::normalizeComplementaryWarningToken($shopifyId)] = trim((string) ($product->title ?? ''));
                }

                $handle = trim((string) ($product->handle ?? ''));
                if ($handle !== '') {
                    $map[self::normalizeComplementaryWarningToken($handle)] = trim((string) ($product->title ?? ''));
                }

                $title = trim((string) ($product->title ?? ''));
                if ($title !== '') {
                    $map[self::normalizeComplementaryWarningToken($title)] = $title;
                }

                return $map;
            })
            ->all();

        $display = [];
        foreach ($tokens as $token) {
            $normalized = $normalizedByToken[$token] ?? '';
            $display[] = trim((string) ($titlesByNormalized[$normalized] ?? $token));
        }

        return implode('; ', $display);
    }

    private static function normalizeComplementaryWarningToken(?string $value): string
    {
        $trimmed = trim((string) ($value ?? ''));
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('#gid://shopify/Product/([0-9]+)#i', $trimmed, $matches)) {
            return 'gid://shopify/product/' . $matches[1];
        }

        if (preg_match('#/products/([0-9]+)(?:[/?\\#].*)?$#i', $trimmed, $matches)) {
            return 'gid://shopify/product/' . $matches[1];
        }

        if (preg_match('#(?:^|/)products/([a-z0-9][a-z0-9\\-]*)(?:[/?\\#].*)?$#i', $trimmed, $matches)) {
            $trimmed = $matches[1];
        }

        $trimmed = strtolower($trimmed);
        $trimmed = str_replace('&', 'and', $trimmed);
        $trimmed = preg_replace('/[^a-z0-9]+/', '-', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/-+/', '-', $trimmed) ?? $trimmed;

        return trim($trimmed, '-');
    }

    private static function shopifySyncWarningsBlockingHtml(?NewProductDraft $record): ?HtmlString
    {
        if (!$record) {
            return null;
        }

        $warningCount = $record->shopifySyncWarningCount();
        if ($warningCount <= 0) {
            return null;
        }

        $draftId = (int) $record->id;

        return new HtmlString(
            "<div id='draft-warning-block' class='rounded-xl border-2 border-danger-300 bg-danger-50 p-4 text-sm text-danger-900'>"
            . "<p class='font-semibold mb-2'>Resolve <strong>Shopify</strong> conflicts before changing the conflicting fields in this draft.</p>"
            . "<p>This draft has {$warningCount} unresolved <strong>Shopify</strong> sync warning(s). Non-conflicting changes can still be saved, but conflicting fields must be resolved first using the per-field choices below or the bulk <strong>Use Shopify Values</strong> / <strong>Keep Draft Values</strong> actions.</p>"
            . "</div>"
            . "<script>
                (function () {
                    const run = function () {
                        const block = document.getElementById('draft-warning-block');
                        if (block) {
                            block.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    };

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', run, { once: true });
                    } else {
                        run();
                    }
                })();
            </script>"
        );
    }

    private static function draftHasBlockingShopifyWarnings(?NewProductDraft $record): bool
    {
        return (($record?->shopifySyncWarningCount() ?? 0) > 0);
    }

    private static function draftBlockingWarningsMessage(?NewProductDraft $record): string
    {
        $count = (int) ($record?->shopifySyncWarningCount() ?? 0);

        return $count > 0
            ? "This draft has {$count} unresolved Shopify sync warning(s). Conflicting fields must be resolved first. Scroll up and choose per-field actions or use the bulk keep/apply actions."
            : 'Resolve Shopify sync warnings for the conflicting fields before editing them.';
    }

    private static function draftBlockingWarningsTooltip(?NewProductDraft $record): string
    {
        return self::draftBlockingWarningsMessage($record);
    }

    private static function draftShopifyConflictFields(?NewProductDraft $record): array
    {
        if (!$record) {
            return [];
        }

        $fields = array_map(
            static fn (array $warning): string => trim((string) ($warning['field'] ?? '')),
            $record->shopifySyncWarnings()
        );

        return array_values(array_unique(array_filter($fields)));
    }

    private static function draftAttributeHasShopifyConflict(?NewProductDraft $record, string $attribute): bool
    {
        return in_array($attribute, self::draftShopifyConflictFields($record), true);
    }

    private static function draftQuickFieldHasShopifyConflict(?NewProductDraft $record, array $field): bool
    {
        $attribute = $field['attribute'] ?? null;
        $source = $field['source'] ?? 'product';

        if (!is_string($attribute) || $attribute === '') {
            return false;
        }

        if ($source === 'product') {
            return self::draftAttributeHasShopifyConflict($record, $attribute);
        }

        if ($source === 'row') {
            $draftAttribute = self::draftAttributeForQuickField($source, $attribute);

            if ($draftAttribute !== null) {
                return self::draftAttributeHasShopifyConflict($record, $draftAttribute);
            }

            return self::draftAttributeHasShopifyConflict($record, $attribute);
        }

        if ($source === 'variant') {
            $draftAttribute = self::draftAttributeForQuickField($source, $attribute);

            return $draftAttribute !== null
                && self::draftAttributeHasShopifyConflict($record, $draftAttribute);
        }

        return false;
    }

    private static function draftConflictEditingNoticeHtml(): HtmlString
    {
        return new HtmlString(
            "<div class='rounded-xl border border-danger-300 bg-danger-50 p-3 text-sm text-danger-900'>"
            . "<p class='font-semibold'>Some fields are locked because they conflict with the latest Shopify import.</p>"
            . "<p>Scroll up, review the Shopify conflict warning, then resolve that field with its per-field action or the bulk actions.</p>"
            . '</div>'
        );
    }

    private static function draftConflictFieldNoticeHtml(string $label): HtmlString
    {
        return new HtmlString(
            "<div class='text-sm text-danger-700'>"
            . e($label)
            . " is locked because it conflicts with the latest Shopify import. Scroll up and resolve the conflict first."
            . '</div>'
        );
    }

    private static function removeConflictingDraftInputs(array $data, NewProductDraft $record): array
    {
        foreach (self::draftShopifyConflictFields($record) as $attribute) {
            unset($data[$attribute]);

            if ($attribute === 'title') {
                unset($data['siblings_collection_name']);
            }
        }

        return $data;
    }

    /**
     * @param iterable<mixed> $records
     * @return array{updated:int,skipped:int}
     */
    private static function applyShopifyWarningValuesToDrafts(iterable $records): array
    {
        $updated = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (!$record instanceof NewProductDraft) {
                continue;
            }

            $warnings = $record->shopifySyncWarnings();
            if (empty($warnings)) {
                $skipped++;
                continue;
            }

            $updates = [];
            if (NewProductDraft::supportsShopifySyncWarningsColumn()) {
                $updates['shopify_sync_warnings'] = null;
            }
            foreach ($warnings as $warning) {
                $field = trim((string) ($warning['field'] ?? ''));
                if ($field === '') {
                    continue;
                }

                $updates[$field] = self::draftWarningResolvedValue(
                    $field,
                    (string) ($warning['shopify_value'] ?? '')
                );
            }

            if (empty($updates)) {
                $skipped++;
                continue;
            }

            NewProductDraft::withoutEvents(function () use ($record, $updates): void {
                $record->fill($updates)->save();
            });

            $updated++;
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param iterable<mixed> $records
     * @return array{cleared:int,synced:int,skipped:int}
     */
    private static function keepDraftWarningValues(iterable $records): array
    {
        $cleared = 0;
        $synced = 0;
        $skipped = 0;
        $sync = app(NewProductDraftProductSync::class);

        foreach ($records as $record) {
            if (!$record instanceof NewProductDraft) {
                continue;
            }

            $warnings = $record->shopifySyncWarnings();
            if (empty($warnings)) {
                $skipped++;
                continue;
            }

            NewProductDraft::withoutEvents(function () use ($record): void {
                $updates = [];

                if (NewProductDraft::supportsShopifySyncWarningsColumn()) {
                    $updates['shopify_sync_warnings'] = null;
                }

                if ($updates !== []) {
                    $record->forceFill($updates)->save();
                }
            });

            $cleared++;

            if ($sync->syncToExistingProduct($record->fresh(), ensureApprovalReset: true)) {
                $synced++;
            }
        }

        return [
            'cleared' => $cleared,
            'synced' => $synced,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{resolved:bool,synced:bool}
     */
    public static function resolveSingleShopifyWarning(NewProductDraft $record, string $field, string $strategy): array
    {
        $field = trim($field);
        if ($field === '') {
            return ['resolved' => false, 'synced' => false];
        }

        $warnings = $record->shopifySyncWarnings();
        $warning = collect($warnings)->first(
            fn (array $item): bool => trim((string) ($item['field'] ?? '')) === $field
        );

        if (!is_array($warning)) {
            return ['resolved' => false, 'synced' => false];
        }

        $remainingWarnings = array_values(array_filter(
            $warnings,
            static fn (array $item): bool => trim((string) ($item['field'] ?? '')) !== $field
        ));

        if ($strategy === 'shopify') {
            $updates = [
                $field => self::draftWarningResolvedValue(
                    $field,
                    (string) ($warning['shopify_value'] ?? '')
                ),
            ];

            if (NewProductDraft::supportsShopifySyncWarningsColumn()) {
                $updates['shopify_sync_warnings'] = $remainingWarnings === [] ? null : $remainingWarnings;
            }

            NewProductDraft::withoutEvents(function () use ($record, $updates): void {
                $record->fill($updates)->save();
            });

            return ['resolved' => true, 'synced' => false];
        }

        if ($strategy === 'draft') {
            $updates = [];

            if (NewProductDraft::supportsShopifySyncWarningsColumn()) {
                $updates['shopify_sync_warnings'] = $remainingWarnings === [] ? null : $remainingWarnings;
            }

            if ($updates !== []) {
                NewProductDraft::withoutEvents(function () use ($record, $updates): void {
                    $record->forceFill($updates)->save();
                });
            }

            $synced = app(NewProductDraftProductSync::class)->syncToExistingProduct(
                $record->fresh() ?? $record,
                ensureApprovalReset: true,
                attributes: [$field]
            );

            return ['resolved' => true, 'synced' => $synced];
        }

        return ['resolved' => false, 'synced' => false];
    }

    private static function draftWarningResolvedValue(string $field, string $value): mixed
    {
        $trimmed = trim($value);

        return match ($field) {
            'variant_inventory_qty' => $trimmed === '' ? null : (int) $trimmed,
            'published' => $trimmed === '' ? null : (strtolower($trimmed) === 'true' ? 'true' : 'false'),
            'is_on_sale' => $trimmed !== '' && filter_var($trimmed, FILTER_VALIDATE_BOOLEAN),
            'variant_price',
            'variant_compare_at_price',
            'material_cost' => $trimmed === '' ? null : $trimmed,
            default => $trimmed === '' ? null : $trimmed,
        };
    }

    /**
     * @param array<int, string> $extra
     */
    private static function warningResolutionSummary(int $resolved, int $skipped, array $extra = []): string
    {
        $parts = [];

        if ($resolved > 0) {
            $parts[] = "Resolved {$resolved}.";
        }
        if ($skipped > 0) {
            $parts[] = "Skipped {$skipped} without warnings.";
        }

        foreach ($extra as $part) {
            $parts[] = $part;
        }

        return $parts === [] ? 'No drafts were updated.' : implode(' ', $parts);
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
            'Siblings Option Name',
            'Sibling Collection',
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

    /**
     * @param array<string, mixed> $data
     * @return array<int, Indicator>
     */
    private static function dateTimeRangeIndicators(array $data, string $fromField, string $toField, string $fromLabel, string $toLabel): array
    {
        $indicators = [];

        if (filled($data[$fromField] ?? null)) {
            $indicators[] = Indicator::make($fromLabel . ': ' . self::formatFilterDateTime($data[$fromField]))
                ->removeField($fromField);
        }

        if (filled($data[$toField] ?? null)) {
            $indicators[] = Indicator::make($toLabel . ': ' . self::formatFilterDateTime($data[$toField]))
                ->removeField($toField);
        }

        return $indicators;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int|string, string> $options
     * @return array<int, Indicator>
     */
    private static function singleValueIndicators(array $data, string $label, array $options = [], string $field = 'value'): array
    {
        $value = $data[$field] ?? null;

        if (!filled($value)) {
            return [];
        }

        return [
            Indicator::make($label . ': ' . ($options[$value] ?? (string) $value))
                ->removeField($field),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, Indicator>
     */
    private static function ternaryValueIndicators(array $data, string $trueLabel, ?string $falseLabel = null, string $field = 'value'): array
    {
        $value = self::normalizeTernaryFilterValue($data[$field] ?? null);
        if ($value === null) {
            return [];
        }

        $label = $value ? $trueLabel : $falseLabel;
        if ($label === null) {
            return [];
        }

        return [
            Indicator::make($label)
                ->removeField($field),
        ];
    }

    private static function normalizeTernaryFilterValue(mixed $value): ?bool
    {
        return match (strtolower(trim((string) $value))) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int|string, string> $options
     * @return array<int, Indicator>
     */
    private static function multiValueIndicators(array $data, string $label, array $options = [], string $field = 'values'): array
    {
        $values = $data[$field] ?? [];

        if (!is_array($values) || $values === []) {
            return [];
        }

        $labels = array_map(
            fn ($value): string => $options[$value] ?? (string) $value,
            array_values($values)
        );

        return [
            Indicator::make($label . ': ' . implode(', ', $labels))
                ->removeField($field),
        ];
    }

    private static function formatFilterDateTime(mixed $value): string
    {
        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    private static function draftComplementaryAuditStatusLabel(NewProductDraft $record): string
    {
        $snapshot = self::draftComplementaryAuditSnapshot($record);
        if ($snapshot === null) {
            return 'Not Checked';
        }

        return ($snapshot['status'] ?? null) === ShopifyAudit::STATUS_HEALTHY ? 'Healthy' : 'Needs Audit';
    }

    private static function draftComplementaryAuditStatusColor(NewProductDraft $record): string
    {
        $snapshot = self::draftComplementaryAuditSnapshot($record);
        if ($snapshot === null) {
            return 'gray';
        }

        return (string) ($snapshot['severity'] ?? 'gray');
    }

    private static function draftComplementaryAuditIssuesSummary(NewProductDraft $record): string
    {
        $snapshot = self::draftComplementaryAuditSnapshot($record);
        if ($snapshot === null) {
            return 'None';
        }

        $details = is_array($snapshot['details'] ?? null) ? $snapshot['details'] : [];
        $parts = [];

        if (($snapshot['has_local_shortage'] ?? false) === true) {
            $missing = max(0, ComplementaryProductAuditService::LOCAL_TARGET_COUNT - (int) ($snapshot['local_total'] ?? 0));
            $parts[] = $missing > 0
                ? "Local draft needs {$missing} more complementary backup product(s) to reach 4."
                : 'Local draft is below the 4-product complementary target.';
        }

        if (($snapshot['has_shopify_shortage'] ?? false) === true) {
            $parts[] = 'Shopify has fewer than 3 complementary products.';
        }

        foreach (($details['shopify_ineligible'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['title'] ?? '')) ?: trim((string) ($item['handle'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? ''));
            if ($label !== '') {
                $parts[] = 'Shopify ref invalid: ' . $label . ($reason !== '' ? ' (' . $reason . ')' : '');
            }
        }

        $unexpectedIds = array_values(array_filter(array_map(
            static fn (mixed $value): int => (int) $value,
            $snapshot['unexpected_shopify_ids'] ?? []
        )));

        if ($unexpectedIds !== []) {
            $labels = Product::query()
                ->whereKey($unexpectedIds)
                ->get(['id', 'title', 'handle'])
                ->map(fn (Product $product): string => trim((string) ($product->title ?? '')) ?: trim((string) ($product->handle ?? '')))
                ->filter()
                ->values()
                ->all();

            foreach ($labels as $label) {
                $parts[] = 'Shopify ref missing from draft primary list: ' . $label;
            }
        }

        return $parts !== [] ? implode(' | ', $parts) : 'None';
    }

    private static function draftComplementaryAuditRecord(NewProductDraft $record): ?ShopifyAudit
    {
        $product = null;

        $shopifyId = trim((string) ($record->shopify_id ?? ''));
        if ($shopifyId !== '') {
            $product = Product::query()
                ->where('shopify_id', $shopifyId)
                ->with('complementaryProductsAudit')
                ->first();
        }

        if (!$product instanceof Product) {
            $handle = trim((string) ($record->handle ?? ''));
            if ($handle !== '') {
                $product = Product::query()
                    ->where('handle', $handle)
                    ->with('complementaryProductsAudit')
                    ->first();
            }
        }

        return $product?->complementaryProductsAudit;
    }

    /**
     * @return array{
     *   status:string,
     *   severity:string,
     *   details:array<string,mixed>,
     *   unexpected_shopify_ids:array<int,int>,
     *   local_total:int,
     *   shopify_total:int,
     *   has_local_shortage:bool,
     *   has_shopify_shortage:bool
     * }|null
     */
    private static function draftComplementaryAuditSnapshot(NewProductDraft $record): ?array
    {
        $audit = self::draftComplementaryAuditRecord($record);
        if (!$audit instanceof ShopifyAudit) {
            return null;
        }

        $details = is_array($audit->details) ? $audit->details : [];
        $service = app(ComplementaryProductAuditService::class);
        $localPrimaryIds = array_slice(
            $service->resolveProductIdsFromTokens(
                $service->parseReferenceTokens($record->complementary_products)
            ),
            0,
            ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT
        );
        $localIds = $service->resolveProductIdsFromTokens(
            $service->parseReferenceTokens($record->complementary_products)
        );
        $shopifyIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): int => (int) $value,
            $details['shopify_ids'] ?? []
        ))));
        $unexpectedShopifyIds = array_values(array_filter(
            $shopifyIds,
            static fn (int $productId): bool => !in_array($productId, $localPrimaryIds, true)
        ));
        $hasShopifyIneligible = is_array($details['shopify_ineligible'] ?? null)
            && ($details['shopify_ineligible'] ?? []) !== [];
        $hasLocalShortage = count($localIds) < ComplementaryProductAuditService::LOCAL_TARGET_COUNT;
        $hasShopifyShortage = count($shopifyIds) < ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT;
        $hasDanger = $hasShopifyIneligible || $unexpectedShopifyIds !== [] || $hasShopifyShortage;

        return [
            'status' => ($hasDanger || $hasLocalShortage)
                ? ShopifyAudit::STATUS_FLAGGED
                : ShopifyAudit::STATUS_HEALTHY,
            'severity' => $hasDanger
                ? 'danger'
                : ($hasLocalShortage ? 'warning' : 'success'),
            'details' => $details,
            'unexpected_shopify_ids' => $unexpectedShopifyIds,
            'local_total' => count($localIds),
            'shopify_total' => count($shopifyIds),
            'has_local_shortage' => $hasLocalShortage,
            'has_shopify_shortage' => $hasShopifyShortage,
        ];
    }

    private static function complementaryAuditIssuesSummaryFromDetails(mixed $details): string
    {
        if (!is_array($details)) {
            return 'None';
        }

        $parts = [];

        foreach (($details['local_ineligible'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['title'] ?? '')) ?: trim((string) ($item['handle'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? ''));
            if ($label !== '') {
                $parts[] = 'Local ref invalid on Shopify: ' . $label . ($reason !== '' ? ' (' . $reason . ')' : '');
            }
        }

        foreach (($details['shopify_ineligible'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['title'] ?? '')) ?: trim((string) ($item['handle'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? ''));
            if ($label !== '') {
                $parts[] = 'Shopify ref invalid: ' . $label . ($reason !== '' ? ' (' . $reason . ')' : '');
            }
        }

        foreach (($details['shopify_missing_local'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['title'] ?? '')) ?: trim((string) ($item['handle'] ?? ''));
            if ($label !== '') {
                $parts[] = 'Shopify ref missing from local list: ' . $label;
            }
        }

        return $parts !== [] ? implode(' | ', $parts) : 'None';
    }
}
