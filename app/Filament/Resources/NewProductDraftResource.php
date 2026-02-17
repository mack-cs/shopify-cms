<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\NewProductDraftResource\Pages;
use App\Models\NewProductDraft;
use App\Models\NewProductDraftApproval;
use App\Models\Variant;
use App\Services\NewProductDraftCsvImporter;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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
    protected static ?int $navigationSort = 3;

    private static function defaultBatch(): string
    {
        return 'batch' . now()->format('Ymd');
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Section::make('Core')
                ->schema([
                    TextInput::make('title')->required()->maxLength(255),
                    Textarea::make('body_html')->label('Description')->rows(5),
                    TextInput::make('handle')->maxLength(255)->helperText('Filled after Shopify creates the product.'),
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

                                    if ($draftQuery->exists() || Variant::where('sku', $sku)->exists()) {
                                        $fail('SKU must be unique across new products and existing products.');
                                    }
                                };
                            },
                        ]),
                    TextInput::make('vendor')->maxLength(255),
                    TextInput::make('type')->label('Type')->maxLength(255),
                    TextInput::make('batch')
                        ->label('Batch')
                        ->default(fn () => self::defaultBatch())
                        ->helperText('Optional. Defaults to today, e.g. batch20260217.'),
                    TextInput::make('product_category')->label('Product Category')->maxLength(255),
                    TextInput::make('google_product_category')->label('Google Product Category')->maxLength(255),
                    Textarea::make('tags')->label('Tags')->rows(2)->helperText('Comma-separated.'),
                    TextInput::make('color_string')->label('Colors')->maxLength(512),
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
                ])->columns(2),
            Section::make('Approval')
                ->schema([
                    Placeholder::make('approval_version')
                        ->label('Approval Version')
                        ->content(fn (?NewProductDraft $record) => $record?->approval_version ?? 1),
                    Placeholder::make('approvals_current')
                        ->label('Approvals (Current Version)')
                        ->content(fn (?NewProductDraft $record) => $record?->approvalsForCurrentVersionCount() ?? 0),
                    Placeholder::make('approved')
                        ->label('Approved By Two')
                        ->content(fn (?NewProductDraft $record) => ($record?->isApprovedByTwo() ?? false) ? 'Yes' : 'No'),
                ])->columns(3)->collapsed(),
            Section::make('SEO')
                ->schema([
                    TextInput::make('seo_title')->label('SEO Title')->maxLength(255),
                    Textarea::make('seo_description')->label('SEO Description')->rows(2)->maxLength(160),
                ])->columns(2),
            Section::make('Variant Defaults')
                ->schema([
                    TextInput::make('variant_price')->label('Price')->numeric(),
                    TextInput::make('variant_compare_at_price')->label('Compare-at price')->numeric(),
                    TextInput::make('variant_inventory_qty')->label('Inventory')->numeric(),
                    TextInput::make('variant_inventory_policy')->default('deny')->disabled(),
                    TextInput::make('variant_fulfillment_service')->default('manual')->disabled(),
                ])->columns(2),
            Section::make('Images')
                ->schema([
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
                        ->helperText('Optional. Uploaded image will be used for Shopify creation.'),
                    TextInput::make('image_url')
                        ->label('Image URL')
                        ->placeholder('https://...')
                        ->helperText('Optional. Use external URL instead of upload.'),
                ]),
            Section::make('Additional Fields')
                ->schema([
                    KeyValue::make('payload')
                        ->keyLabel('Header')
                        ->valueLabel('Value')
                        ->addActionLabel('Add field'),
                ]),
        ]);
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

                                if ($draftQuery->exists() || Variant::where('sku', $sku)->exists()) {
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
                TextColumn::make('seo_title')
                    ->label('SEO Title')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('seo_description')
                    ->label('SEO Description')
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
                    ->requiresConfirmation()
                    ->visible(function (NewProductDraft $record): bool {
                        return !NewProductDraftApproval::where('new_product_draft_id', $record->id)
                            ->where('user_id', Auth::id())
                            ->where('approval_version', $record->approval_version)
                            ->exists();
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
                                "Updated: {$result['updated']}, Missing title: {$result['skipped_missing_title']}, " .
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
