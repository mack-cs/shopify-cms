<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Exports\ShopifyCollectionExporter;
use App\Filament\Resources\ShopifyCollectionResource\Pages;
use App\Jobs\ShopifyCollectionUpdateJob;
use App\Jobs\ShopifyCollectionsSyncJob;
use App\Models\CollectionApproval;
use App\Models\ShopifyCollection;
use App\Services\ShopifyCollectionsImporter;
use App\Services\ShopifyCollectionSeoImporter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShopifyCollectionResource extends Resource
{
    protected static ?string $model = ShopifyCollection::class;
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Collections';
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Shopify Title')
                    ->maxLength(255)
                    ->disabled()
                    ->columnSpan(1),
                Forms\Components\TextInput::make('handle')
                    ->label('Handle')
                    ->maxLength(255)
                    ->helperText('Controls the collection URL slug.')
                    ->disabled()
                    ->columnSpan(1),
                Forms\Components\Placeholder::make('batch')
                    ->label('Batch')
                    ->content(fn (ShopifyCollection $record): string => trim((string) ($record->batch ?? '')) ?: 'Not set')
                    ->columnSpan(1),
                Forms\Components\Placeholder::make('sync_status')
                    ->label('Sync Status')
                    ->content(fn (ShopifyCollection $record): string => $record->sync_status === ShopifyCollection::SYNC_STATUS_SYNCED ? 'Synced' : 'Pending')
                    ->columnSpan(1),
                Forms\Components\Placeholder::make('last_synced_at')
                    ->label('Last Synced')
                    ->content(fn (ShopifyCollection $record): string => $record->last_synced_at?->format('Y-m-d H:i:s') ?? 'Never synced')
                    ->columnSpan(1),
                Forms\Components\Section::make('Marketing Channels & Indexing')
                    ->schema([
                        Forms\Components\Placeholder::make('published_channel_names')
                            ->label('Published Channels')
                            ->content(fn (ShopifyCollection $record): string => $record->published_channel_names ?: 'Not published on any channel')
                            ->columnSpan(1),
                        Forms\Components\Placeholder::make('online_only_warning')
                            ->label('Channel Info')
                            ->content('This collection is published only to Online Store. Indexing can still be decided manually by your team.')
                            ->visible(fn (ShopifyCollection $record): bool => $record->published_on_online_store_only && $record->deindex === null)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('deindex')
                            ->label('Deindex')
                            ->options([
                                '1' => 'True (hide from search engines)',
                                '0' => 'False (keep indexed)',
                            ])
                            ->native(false)
                            ->nullable()
                            ->placeholder('Not decided')
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('seo_title')
                    ->label('Shopify SEO Title')
                    ->maxLength(255)
                    ->disabled()
                    ->columnSpan(1),
                Forms\Components\Textarea::make('seo_description')
                    ->label('Shopify SEO Description')
                    ->rows(3)
                    ->maxLength(512)
                    ->disabled()
                    ->columnSpan(1),
                Forms\Components\RichEditor::make('description_html')
                    ->label('Description')
                    ->disabled()
                    ->columnSpanFull(),
                Forms\Components\Section::make('Draft SEO (make edits here)')
                    ->schema([
                        Forms\Components\TextInput::make('draft_title')
                            ->label('Draft Title')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('draft_description_html')
                            ->label('Draft Description')
                            ->rows(3),
                        Forms\Components\TextInput::make('draft_seo_title')
                            ->label('Draft SEO Title')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('draft_seo_description')
                            ->label('Draft SEO Description')
                            ->rows(3)
                            ->maxLength(512),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('handle')
                    ->label('Handle')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('batch')
                    ->label('Batch')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sync_status')
                    ->label('Sync Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === ShopifyCollection::SYNC_STATUS_SYNCED ? 'Synced' : 'Pending')
                    ->color(fn (?string $state): string => $state === ShopifyCollection::SYNC_STATUS_SYNCED ? 'success' : 'warning')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Last Synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('deindex')
                    ->label('Deindex')
                    ->state(function (ShopifyCollection $record): string {
                        if ($record->deindex === null) {
                            return 'Undecided';
                        }

                        return $record->deindex ? 'True' : 'False';
                    })
                    ->badge()
                    ->color(function (string $state): string {
                        return match ($state) {
                            'True' => 'danger',
                            'False' => 'success',
                            default => 'warning',
                        };
                    }),
                Tables\Columns\IconColumn::make('published_on_online_store_only')
                    ->label('Online Only')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('published_channel_names')
                    ->label('Published Channels')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('approvals_current')
                    ->label('Approvals')
                    ->state(fn (ShopifyCollection $record) => $record->approvalsForCurrentVersionCount())
                    ->formatStateUsing(fn (int $state) => "{$state}/2")
                    ->badge()
                    ->color(fn (int $state) => $state >= 2 ? 'success' : ($state === 1 ? 'warning' : 'gray'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('approved')
                    ->label('Approved')
                    ->state(fn (ShopifyCollection $record) => $record->isApprovedByTwo())
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('draft_seo_title')
                    ->label('Draft SEO Title')
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\TextColumn::make('draft_seo_description')
                    ->label('Draft SEO Desc')
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\TextColumn::make('seo_title')
                    ->label('Shopify SEO Title')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('seo_description')
                    ->label('Shopify SEO Desc')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('description_html')
                    ->label('Description')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('batch')
                    ->label('Batch')
                    ->options(function (): array {
                        $importId = self::currentImportId();
                        if (!$importId) {
                            return [];
                        }

                        return ShopifyCollection::query()
                            ->where('import_id', $importId)
                            ->whereNotNull('batch')
                            ->where('batch', '!=', '')
                            ->distinct()
                            ->orderByDesc('batch')
                            ->pluck('batch', 'batch')
                            ->all();
                    })
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('approved')
                    ->label('Approved')
                    ->queries(
                        true: fn ($query) => $query->whereRaw(
                            '(select count(distinct user_id) from collection_approvals where collection_approvals.collection_id = collections.id and collection_approvals.approval_version = collections.approval_version) >= 2'
                        ),
                        false: fn ($query) => $query->whereRaw(
                            '(select count(distinct user_id) from collection_approvals where collection_approvals.collection_id = collections.id and collection_approvals.approval_version = collections.approval_version) < 2'
                        )
                    ),
                TernaryFilter::make('missing_seo')
                    ->label('Missing SEO')
                    ->queries(
                        true: fn ($query) => $query->where(function ($sub): void {
                            $sub->whereNull('draft_title')
                                ->orWhere('draft_title', '')
                                ->orWhereNull('draft_seo_title')
                                ->orWhere('draft_seo_title', '')
                                ->orWhereNull('draft_seo_description')
                                ->orWhere('draft_seo_description', '');
                        }),
                        false: fn ($query) => $query->whereNotNull('draft_title')
                            ->where('draft_title', '!=', '')
                            ->whereNotNull('draft_seo_title')
                            ->where('draft_seo_title', '!=', '')
                            ->whereNotNull('draft_seo_description')
                            ->where('draft_seo_description', '!=', '')
                    ),
                TernaryFilter::make('synced')
                    ->label('Synced')
                    ->queries(
                        true: fn (Builder $query) => $query->where('sync_status', ShopifyCollection::SYNC_STATUS_SYNCED),
                        false: fn (Builder $query) => $query->where(function (Builder $sub): void {
                            $sub->whereNull('sync_status')
                                ->orWhere('sync_status', '!=', ShopifyCollection::SYNC_STATUS_SYNCED);
                        }),
                    ),
                Filter::make('updated_date')
                    ->label('Updated Date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Updated From'),
                        Forms\Components\DatePicker::make('to')->label('Updated To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $from): Builder => $q->whereDate('updated_at', '>=', $from))
                        ->when($data['to'] ?? null, fn (Builder $q, $to): Builder => $q->whereDate('updated_at', '<=', $to))),
                Filter::make('synced_date')
                    ->label('Sync Date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Synced From'),
                        Forms\Components\DatePicker::make('to')->label('Synced To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $from): Builder => $q->whereDate('last_synced_at', '>=', $from))
                        ->when($data['to'] ?? null, fn (Builder $q, $to): Builder => $q->whereDate('last_synced_at', '<=', $to))),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->requiresConfirmation()
                    ->action(function (ShopifyCollection $record): void {
                        if (!self::canApprove($record)) {
                            self::sendNotification(
                                Notification::make()
                                ->title('Cannot approve')
                                ->body(self::approvalBlockMessage($record))
                                ->warning()
                            );
                            return;
                        }

                        $exists = CollectionApproval::where('collection_id', $record->id)
                            ->where('user_id', Auth::id())
                            ->where('approval_version', $record->approval_version)
                            ->exists();

                        if ($exists) {
                            self::sendNotification(
                                Notification::make()
                                ->title('Already approved')
                                ->body('You have already approved this version.')
                                ->warning()
                            );
                            return;
                        }

                        try {
                            self::storeApproval($record);
                        } catch (\Throwable $e) {
                            Log::error('Collection approval failed.', [
                                'collection_id' => $record->id,
                                'user_id' => Auth::id(),
                                'approval_version' => $record->approval_version,
                                'message' => $e->getMessage(),
                            ]);

                            self::sendNotification(
                                Notification::make()
                                ->title('Approval failed')
                                ->body('The collection could not be approved. Check the logs for the underlying error.')
                                ->danger()
                            );

                            return;
                        }

                        self::sendNotification(
                            Notification::make()
                            ->title('Approval recorded')
                            ->success()
                        );
                    }),
                Tables\Actions\Action::make('pushToShopify')
                    ->label('Push to Shopify')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\CheckboxList::make('fields')
                            ->label('Fields to sync')
                            ->options([
                                'title' => 'Title',
                                'description_html' => 'Description',
                                'seo_title' => 'SEO title',
                                'seo_description' => 'SEO description',
                                'deindex' => 'Deindex (seo.hide_from_google metafield)',
                            ])
                            ->columns(2)
                            ->default(['title', 'description_html', 'seo_title', 'seo_description', 'deindex']),
                        Forms\Components\TextInput::make('handle_override')
                            ->label('Handle (URL) - optional')
                            ->maxLength(255)
                            ->helperText('Set a new handle to update the Shopify URL. This does not change the local record.'),
                        Forms\Components\Select::make('deindex_override')
                            ->label('Deindex Decision (optional)')
                            ->options([
                                '1' => 'True (hide from search engines)',
                                '0' => 'False (keep indexed)',
                            ])
                            ->native(false)
                            ->nullable()
                            ->placeholder('Keep current decision'),
                    ])
                    ->action(function (ShopifyCollection $record, array $data): void {
                        if (!$record->isApprovedByTwo()) {
                            self::sendNotification(
                                Notification::make()
                                ->title('Approval required')
                                ->body('This collection needs 2 approvals before syncing to Shopify.')
                                ->warning()
                            );
                            return;
                        }

                        $fieldNames = array_values($data['fields'] ?? []);
                        if ($fieldNames === []) {
                            self::sendNotification(
                                Notification::make()
                                ->title('Choose at least one field')
                                ->warning()
                            );
                            return;
                        }

                        $deindexOverride = self::parseNullableBoolean($data['deindex_override'] ?? null);
                        ShopifyCollectionUpdateJob::dispatch(
                            [$record->id],
                            $fieldNames,
                            Auth::id(),
                            trim((string) ($data['handle_override'] ?? '')) ?: null,
                            $deindexOverride !== null,
                            $deindexOverride,
                        );

                        self::sendNotification(
                            Notification::make()
                                ->title('Collection sync queued')
                                ->body('This collection will be pushed to Shopify in the background.')
                                ->success()
                        );
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('syncCollections')
                    ->label('Sync Collections')
                    ->requiresConfirmation()
                    ->color('primary')
                    ->action(function (ShopifyCollectionsImporter $importer): void {
                        $user = Auth::user();
                        if (!$user) {
                            return;
                        }

                        if (!config('services.shopify.shop') || !config('services.shopify.admin_access_token')) {
                            Notification::make()
                                ->title('Shopify credentials missing')
                                ->body('Set SHOPIFY_SHOP and SHOPIFY_ADMIN_ACCESS_TOKEN in .env before syncing.')
                                ->danger()
                                ->send();
                            return;
                        }

                        try {
                            $import = $importer->createOrReuseCollectionsImport($user->id);
                            ShopifyCollectionsSyncJob::dispatch($import->id);

                            Notification::make()
                                ->title('Collections sync queued')
                                ->body("Import #{$import->id} is processing in the background")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Collections sync failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('importSeoCsv')
                    ->label('Import SEO CSV')
                    ->color('info')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('CSV File')
                            ->required()
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel']),
                    ])
                    ->action(function (array $data, ShopifyCollectionSeoImporter $importer): void {
                        $importId = \App\Models\Import::where('filename', 'shopify-collections')
                            ->orderByDesc('id')
                            ->value('id');
                        if (!$importId) {
                            Notification::make()
                                ->title('No collections import found')
                                ->body('Run Sync Collections first.')
                                ->warning()
                                ->send();
                            return;
                        }

                        $path = Storage::disk('local')->path($data['file']);
                        $import = \App\Models\Import::find($importId);
                        if (!$import) {
                            return;
                        }

                        $result = $importer->importFromPath($import, $path);

                        Notification::make()
                            ->title('Collection SEO import complete')
                            ->body(
                                "Total: {$result['total']}, Updated: {$result['updated']}, " .
                                "Missing Handle: {$result['skipped_missing_handle']}, " .
                                "Not Found: {$result['skipped_not_found']}, " .
                                "Batch: {$result['batch']}"
                            )
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(ShopifyCollectionExporter::class),
                    BulkAction::make('bulkApprove')
                        ->label('Bulk Approve')
                        ->icon('heroicon-o-check-badge')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $approvedCount = 0;
                            $skippedCount = 0;
                            $blockedCount = 0;
                            $failedCount = 0;
                            $missingDraftTitleCount = 0;
                            $missingDraftSeoTitleCount = 0;
                            $missingDraftSeoDescriptionCount = 0;

                            foreach ($records as $record) {
                                if (!self::canApprove($record)) {
                                    $blockedCount++;

                                    $missingFields = self::missingDraftSeoFields($record);
                                    if (in_array('draft_title', $missingFields, true)) {
                                        $missingDraftTitleCount++;
                                    }
                                    if (in_array('draft_seo_title', $missingFields, true)) {
                                        $missingDraftSeoTitleCount++;
                                    }
                                    if (in_array('draft_seo_description', $missingFields, true)) {
                                        $missingDraftSeoDescriptionCount++;
                                    }

                                    continue;
                                }

                                $exists = CollectionApproval::where('collection_id', $record->id)
                                    ->where('user_id', Auth::id())
                                    ->where('approval_version', $record->approval_version)
                                    ->exists();

                                if ($exists) {
                                    $skippedCount++;
                                    continue;
                                }

                                try {
                                    self::storeApproval($record);
                                    $approvedCount++;
                                } catch (\Throwable $e) {
                                    $failedCount++;

                                    Log::error('Bulk collection approval failed.', [
                                        'collection_id' => $record->id,
                                        'user_id' => Auth::id(),
                                        'approval_version' => $record->approval_version,
                                        'message' => $e->getMessage(),
                                    ]);
                                }
                            }

                            $parts = [];
                            if ($approvedCount > 0) {
                                $parts[] = "Approved {$approvedCount}.";
                            }
                            if ($skippedCount > 0) {
                                $parts[] = "Skipped {$skippedCount} already approved by you.";
                            }
                            if ($blockedCount > 0) {
                                $parts[] = "Blocked {$blockedCount}.";
                                if ($missingDraftTitleCount > 0) {
                                    $parts[] = "Missing draft title: {$missingDraftTitleCount}.";
                                }
                                if ($missingDraftSeoTitleCount > 0) {
                                    $parts[] = "Missing draft SEO title: {$missingDraftSeoTitleCount}.";
                                }
                                if ($missingDraftSeoDescriptionCount > 0) {
                                    $parts[] = "Missing draft SEO description: {$missingDraftSeoDescriptionCount}.";
                                }
                                $parts[] = 'Set Deindex to true if you want approval without draft SEO.';
                            }
                            if ($failedCount > 0) {
                                $parts[] = "Failed {$failedCount}; check logs for the underlying error.";
                            }

                            $notification = Notification::make()
                                ->title('Bulk approval complete')
                                ->body($parts ? implode(' ', $parts) : 'No collections were approved.');

                            if ($failedCount > 0) {
                                $notification->danger();
                            } elseif ($blockedCount > 0) {
                                $notification->warning();
                            } else {
                                $notification->success();
                            }

                            self::sendNotification($notification);
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('pushToShopify')
                        ->label('Push to Shopify')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\CheckboxList::make('fields')
                                ->label('Fields to sync')
                                ->options([
                                    'title' => 'Title',
                                    'description_html' => 'Description',
                                    'seo_title' => 'SEO title',
                                    'seo_description' => 'SEO description',
                                    'deindex' => 'Deindex (seo.hide_from_google metafield)',
                                ])
                                ->columns(2)
                                ->default(['title', 'description_html', 'seo_title', 'seo_description', 'deindex']),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $fieldNames = array_values($data['fields'] ?? []);
                            if ($fieldNames === []) {
                                self::sendNotification(
                                    Notification::make()
                                    ->title('Choose at least one field')
                                    ->warning()
                                );
                                return;
                            }

                            $ids = $records->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
                            ShopifyCollectionUpdateJob::dispatch($ids, $fieldNames, Auth::id());

                            self::sendNotification(
                                Notification::make()
                                    ->title('Collection sync queued')
                                    ->body('Selected collections will be pushed to Shopify in the background.')
                                    ->success()
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShopifyCollections::route('/'),
            'create' => Pages\CreateShopifyCollection::route('/create'),
            'edit' => Pages\EditShopifyCollection::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasAnyRole([
            RolesEnum::SuperAdmin->value,
            RolesEnum::Admin->value,
            RolesEnum::SeoReviewer->value,
        ]) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        $importId = self::currentImportId();
        if (!$importId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('import_id', $importId);
    }

    private static function parseNullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function draftSeoComplete(ShopifyCollection $record): bool
    {
        return self::missingDraftSeoFields($record) === [];
    }

    private static function canApprove(ShopifyCollection $record): bool
    {
        if ($record->deindex === true) {
            return true;
        }

        return self::draftSeoComplete($record);
    }

    private static function approvalBlockMessage(ShopifyCollection $record): string
    {
        if ($record->deindex === true) {
            return 'Approval is blocked unexpectedly. Please retry.';
        }

        $missingFields = self::missingDraftSeoFields($record);

        if ($missingFields === []) {
            return 'Approval is blocked unexpectedly. Please retry.';
        }

        $labels = array_map(
            fn (string $field): string => match ($field) {
                'draft_title' => 'draft title',
                'draft_seo_title' => 'draft SEO title',
                'draft_seo_description' => 'draft SEO description',
                default => $field,
            },
            $missingFields,
        );

        return 'Missing ' . implode(', ', $labels) . '. Set Deindex to true if you want approval without draft SEO.';
    }

    private static function applyApprovedDrafts(ShopifyCollection $record): void
    {
        ShopifyCollection::withoutEvents(function () use ($record): void {
            $record->forceFill([
                'title' => self::preferredDraftValue($record->draft_title, $record->title),
                'description_html' => self::preferredDraftValue($record->draft_description_html, $record->description_html),
                'seo_title' => self::preferredDraftValue($record->draft_seo_title, $record->seo_title),
                'seo_description' => self::preferredDraftValue($record->draft_seo_description, $record->seo_description),
                'sync_status' => ShopifyCollection::SYNC_STATUS_PENDING,
            ]);

            if ($record->isDirty()) {
                $record->save();
            }
        });
    }

    private static function preferredDraftValue(mixed $draftValue, mixed $currentValue): mixed
    {
        if (is_string($draftValue)) {
            return trim($draftValue) === '' ? $currentValue : $draftValue;
        }

        return $draftValue ?? $currentValue;
    }

    private static function storeApproval(ShopifyCollection $record): void
    {
        CollectionApproval::create([
            'collection_id' => $record->id,
            'user_id' => Auth::id(),
            'approval_version' => $record->approval_version,
        ]);

        if ($record->approvalsForCurrentVersionCount() >= 2) {
            self::applyApprovedDrafts($record);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function missingDraftSeoFields(ShopifyCollection $record): array
    {
        $missing = [];

        if (trim((string) ($record->draft_title ?? '')) === '') {
            $missing[] = 'draft_title';
        }

        if (trim((string) ($record->draft_seo_title ?? '')) === '') {
            $missing[] = 'draft_seo_title';
        }

        if (trim((string) ($record->draft_seo_description ?? '')) === '') {
            $missing[] = 'draft_seo_description';
        }

        return $missing;
    }

    private static function sendNotification(Notification $notification): void
    {
        if ($user = Auth::user()) {
            $notification->sendToDatabase($user);
        }

        $notification->send();
    }

    private static function currentImportId(): ?int
    {
        return \App\Models\Import::where('filename', 'shopify-collections')
            ->orderByDesc('id')
            ->value('id');
    }
}
