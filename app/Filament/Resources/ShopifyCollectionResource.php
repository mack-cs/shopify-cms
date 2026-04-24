<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Exports\ShopifyCollectionExporter;
use App\Filament\Resources\ShopifyCollectionResource\Pages;
use App\Jobs\ShopifyCollectionUpdateJob;
use App\Jobs\ShopifyCollectionsSyncJob;
use App\Models\CollectionApproval;
use App\Models\DeletionRequest;
use App\Models\ShopifyCollection;
use App\Services\AdminNotification;
use App\Services\DeletionRequestWorkflowService;
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
use Illuminate\Support\HtmlString;

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
                Forms\Components\Placeholder::make('batch')
                    ->label('Batch')
                    ->content(fn (ShopifyCollection $record): string => trim((string) ($record->batch ?? '')) ?: 'Not set')
                    ->columnSpan(1),
                Forms\Components\Placeholder::make('handle')
                    ->label('Current Shopify Handle')
                    ->content(fn (ShopifyCollection $record): string => trim((string) ($record->handle ?? '')) ?: 'Not set')
                    ->columnSpan(1),
                Forms\Components\Section::make('URL Change & Redirect')
                    ->schema([
                        Forms\Components\Placeholder::make('url_change_notice')
                            ->label('')
                            ->content('Add a new URL handle only when you want to rename this collection URL. Leave it blank to keep the current URL. After approval and push to Shopify, the old URL will redirect to the new one.'),
                        Forms\Components\TextInput::make('draft_handle')
                            ->label('Proposed New URL Handle')
                            ->maxLength(255)
                            ->prefix('/collections/')
                            ->placeholder('new-handle-only')
                            ->dehydrateStateUsing(fn ($state): ?string => self::normalizeHandleInput($state))
                            ->helperText(fn (?ShopifyCollection $record): string => 'Enter only the last part of the URL. Current URL: /collections/' . (trim((string) ($record?->handle ?? '')) ?: '[not set]')),
                    ])
                    ->columnSpanFull(),
                Forms\Components\Placeholder::make('sync_status')
                    ->label('Sync Status')
                    ->content(fn (ShopifyCollection $record): string => $record->sync_status === ShopifyCollection::SYNC_STATUS_SYNCED ? 'Synced' : 'Pending')
                    ->columnSpan(1),
                Forms\Components\Placeholder::make('last_synced_at')
                    ->label('Last Synced')
                    ->content(fn (ShopifyCollection $record): string => $record->last_synced_at?->format('Y-m-d H:i:s') ?? 'Never synced')
                    ->columnSpan(1),
                Forms\Components\Section::make('Shopify Sync Warnings')
                    ->schema([
                        Forms\Components\Placeholder::make('shopify_sync_warnings_notice')
                            ->label('')
                            ->content(fn (?ShopifyCollection $record): ?HtmlString => self::shopifySyncWarningsHtml($record)),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('useShopifyWarningValues')
                                ->label('Use Shopify Values')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('warning')
                                ->requiresConfirmation()
                                ->action(function (?ShopifyCollection $record) {
                                    if (!$record) {
                                        return null;
                                    }

                                    $result = self::applyShopifyWarningValuesToDrafts([$record->fresh()]);

                                    self::sendNotification(Notification::make()
                                        ->title('Shopify values applied')
                                        ->body(self::warningResolutionSummary($result['updated'], $result['skipped']))
                                        ->success()
                                    );

                                    return redirect(self::getUrl('edit', ['record' => $record]));
                                }),
                            Forms\Components\Actions\Action::make('keepDraftWarningValues')
                                ->label('Keep Draft Values')
                                ->icon('heroicon-o-arrow-up-tray')
                                ->color('gray')
                                ->requiresConfirmation()
                                ->action(function (?ShopifyCollection $record) {
                                    if (!$record) {
                                        return null;
                                    }

                                    $result = self::keepDraftWarningValues([$record->fresh()]);

                                    self::sendNotification(Notification::make()
                                        ->title('Draft values kept')
                                        ->body(self::warningResolutionSummary($result['cleared'], $result['skipped']))
                                        ->success()
                                    );

                                    return redirect(self::getUrl('edit', ['record' => $record]));
                                }),
                        ])->alignStart(),
                    ])
                    ->visible(fn (?ShopifyCollection $record): bool => ($record?->shopifySyncWarningCount() ?? 0) > 0)
                    ->columnSpanFull(),
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
                Forms\Components\Section::make('Draft Content (make edits here)')
                    ->schema([
                        Forms\Components\TextInput::make('draft_title')
                            ->label('Draft Title')
                            ->maxLength(255)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?ShopifyCollection $record): void {
                                if (!$record || trim((string) ($component->getState() ?? '')) !== '') {
                                    return;
                                }

                                $component->state($record->title);
                            }),
                        Forms\Components\Textarea::make('draft_description_html')
                            ->label('Draft Description')
                            ->rows(3)
                            ->afterStateHydrated(function (Forms\Components\Textarea $component, ?ShopifyCollection $record): void {
                                if (!$record || trim((string) ($component->getState() ?? '')) !== '') {
                                    return;
                                }

                                $component->state($record->description_html);
                            }),
                        Forms\Components\TextInput::make('draft_seo_title')
                            ->label('Draft SEO Title')
                            ->maxLength(255)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?ShopifyCollection $record): void {
                                if (!$record || trim((string) ($component->getState() ?? '')) !== '') {
                                    return;
                                }

                                $component->state($record->seo_title);
                            }),
                        Forms\Components\Textarea::make('draft_seo_description')
                            ->label('Draft SEO Description')
                            ->rows(3)
                            ->maxLength(512)
                            ->afterStateHydrated(function (Forms\Components\Textarea $component, ?ShopifyCollection $record): void {
                                if (!$record || trim((string) ($component->getState() ?? '')) !== '') {
                                    return;
                                }

                                $component->state($record->seo_description);
                            }),
                        Forms\Components\TextInput::make('draft_footer_title')
                            ->label('Draft Footer Title')
                            ->helperText('Will sync to Shopify metafield custom.footer_description.')
                            ->maxLength(255)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?ShopifyCollection $record): void {
                                if (!$record || trim((string) ($component->getState() ?? '')) !== '') {
                                    return;
                                }

                                $component->state($record->footer_title);
                            }),
                        Forms\Components\Textarea::make('draft_elegant_footer_description')
                            ->label('Draft Elegant Footer Description')
                            ->helperText('Will sync to Shopify metafield custom.elegant_footer_description.')
                            ->rows(4)
                            ->afterStateHydrated(function (Forms\Components\Textarea $component, ?ShopifyCollection $record): void {
                                if (!$record || trim((string) ($component->getState() ?? '')) !== '') {
                                    return;
                                }

                                $component->state($record->elegant_footer_description);
                            }),
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
                Tables\Columns\TextColumn::make('draft_handle')
                    ->label('Proposed URL')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Tables\Columns\TextColumn::make('delete_request_status')
                    ->label('Delete Request')
                    ->state(fn (ShopifyCollection $record): string => self::deletionRequestStatusLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Processing' => 'danger',
                        'Pending 1/2', 'Pending 2/2' => 'warning',
                        default => 'gray',
                    })
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
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('draft_seo_description')
                    ->label('Draft SEO Desc')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('draft_footer_title')
                    ->label('Draft Footer Title')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('draft_elegant_footer_description')
                    ->label('Draft Elegant Footer Desc')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Tables\Columns\TextColumn::make('footer_title')
                    ->label('Shopify Footer Title')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('elegant_footer_description')
                    ->label('Shopify Elegant Footer Desc')
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
                Filter::make('recently_edited_today')
                    ->label('Recently Edited Today')
                    ->query(fn (Builder $query): Builder => $query->whereDate('updated_at', today())),
                Filter::make('edited_last_7_days')
                    ->label('Edited in Last 7 Days')
                    ->query(fn (Builder $query): Builder => $query->where('updated_at', '>=', now()->subDays(7))),
                Filter::make('pending_changes')
                    ->label('Pending Changes')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $sub): void {
                        $sub->whereNull('sync_status')
                            ->orWhere('sync_status', '!=', ShopifyCollection::SYNC_STATUS_SYNCED)
                            ->orWhereRaw(
                                '(select count(distinct user_id) from collection_approvals where collection_approvals.collection_id = collections.id and collection_approvals.approval_version = collections.approval_version) < 2'
                            );
                    })),
                Filter::make('awaiting_approval')
                    ->label('Awaiting Approval')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        '(select count(distinct user_id) from collection_approvals where collection_approvals.collection_id = collections.id and collection_approvals.approval_version = collections.approval_version) < 2'
                    )),
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
                            self::sendNotification(
                                Notification::make()
                                    ->title('Shopify credentials missing')
                                    ->body('Set SHOPIFY_SHOP and SHOPIFY_ADMIN_ACCESS_TOKEN in .env before syncing.')
                                    ->danger()
                            );
                            return;
                        }

                        try {
                            $import = $importer->createOrReuseCollectionsImport($user->id);
                            ShopifyCollectionsSyncJob::dispatch($import->id);

                            self::sendNotification(
                                Notification::make()
                                    ->title('Collections sync queued')
                                    ->body("Import #{$import->id} is processing in the background")
                                    ->success()
                            );
                        } catch (\Throwable $e) {
                            self::sendNotification(
                                Notification::make()
                                    ->title('Collections sync failed')
                                    ->body($e->getMessage())
                                    ->danger()
                            );
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
                            self::sendNotification(
                                Notification::make()
                                    ->title('No collections import found')
                                    ->body('Run Sync Collections first.')
                                    ->warning()
                            );
                            return;
                        }

                        $path = Storage::disk('local')->path($data['file']);
                        $import = \App\Models\Import::find($importId);
                        if (!$import) {
                            return;
                        }

                        $result = $importer->importFromPath($import, $path);

                        self::sendNotification(
                            Notification::make()
                                ->title('Collection SEO import complete')
                                ->body(
                                    "Total: {$result['total']}, Updated: {$result['updated']}, " .
                                    "Missing Handle: {$result['skipped_missing_handle']}, " .
                                    "Not Found: {$result['skipped_not_found']}, " .
                                    "Batch: {$result['batch']}"
                                )
                                ->success()
                        );
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
                                'footer_title' => 'Footer title metafield',
                                'elegant_footer_description' => 'Elegant footer description metafield',
                                'deindex' => 'Deindex (seo.hide_from_google metafield)',
                            ])
                            ->columns(2)
                            ->default(['title', 'description_html', 'seo_title', 'seo_description', 'footer_title', 'elegant_footer_description', 'deindex']),
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
                    BulkAction::make('requestDelete')
                        ->label('Request Delete')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason')
                                ->rows(3)
                                ->maxLength(1000),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $requested = 0;
                            $skipped = 0;
                            $failed = 0;

                            foreach ($records as $record) {
                                if (!$record instanceof ShopifyCollection) {
                                    continue;
                                }

                                if (self::currentDeletionRequest($record) !== null) {
                                    $skipped++;
                                    continue;
                                }

                                try {
                                    app(DeletionRequestWorkflowService::class)->submit($record, (int) Auth::id(), $data['reason'] ?? null);
                                    $requested++;
                                } catch (\Throwable $e) {
                                    $failed++;

                                    Log::error('Bulk collection delete request failed.', [
                                        'collection_id' => $record->id,
                                        'user_id' => Auth::id(),
                                        'message' => $e->getMessage(),
                                    ]);
                                }
                            }

                            $parts = [];
                            if ($requested > 0) {
                                $parts[] = "Requested {$requested}.";
                            }
                            if ($skipped > 0) {
                                $parts[] = "Skipped {$skipped} with an existing delete request.";
                            }
                            if ($failed > 0) {
                                $parts[] = "Failed {$failed}; check logs for the underlying error.";
                            }

                            $notification = Notification::make()
                                ->title('Bulk delete request complete')
                                ->body($parts ? implode(' ', $parts) : 'No collections were submitted for deletion.');

                            if ($failed > 0) {
                                $notification->danger();
                            } elseif ($requested > 0) {
                                $notification->success();
                            } else {
                                $notification->warning();
                            }

                            self::sendNotification($notification);
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('approveDelete')
                        ->label('Approve Delete')
                        ->icon('heroicon-o-check-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $approved = 0;
                            $queued = 0;
                            $skipped = 0;
                            $failed = 0;

                            foreach ($records as $record) {
                                if (!$record instanceof ShopifyCollection) {
                                    continue;
                                }

                                if (!self::canApproveDeletion($record)) {
                                    $skipped++;
                                    continue;
                                }

                                try {
                                    $result = app(DeletionRequestWorkflowService::class)->approve($record, (int) Auth::id());
                                    $approved++;

                                    if (($result['queued'] ?? false) === true) {
                                        $queued++;
                                    }
                                } catch (\Throwable $e) {
                                    $failed++;

                                    Log::error('Bulk collection delete approval failed.', [
                                        'collection_id' => $record->id,
                                        'user_id' => Auth::id(),
                                        'message' => $e->getMessage(),
                                    ]);
                                }
                            }

                            $parts = [];
                            if ($approved > 0) {
                                $parts[] = "Approved {$approved}.";
                            }
                            if ($queued > 0) {
                                $parts[] = "Queued {$queued} for deletion.";
                            }
                            if ($skipped > 0) {
                                $parts[] = "Skipped {$skipped} not awaiting your approval.";
                            }
                            if ($failed > 0) {
                                $parts[] = "Failed {$failed}; check logs for the underlying error.";
                            }

                            $notification = Notification::make()
                                ->title('Bulk delete approval complete')
                                ->body($parts ? implode(' ', $parts) : 'No delete approvals were recorded.');

                            if ($failed > 0) {
                                $notification->danger();
                            } elseif ($approved > 0) {
                                $notification->success();
                            } else {
                                $notification->warning();
                            }

                            self::sendNotification($notification);
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

    public static function requestDeletion(ShopifyCollection $record, ?string $reason = null): void
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

    public static function approveDeletion(ShopifyCollection $record): void
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

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        $importId = self::currentImportId();
        if (!$importId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('import_id', $importId);
    }

    public static function pushToShopifyFormSchema(): array
    {
        return [
            Forms\Components\CheckboxList::make('fields')
                ->label('Fields to sync')
                ->options([
                    'title' => 'Title',
                    'description_html' => 'Description',
                    'seo_title' => 'SEO title',
                    'seo_description' => 'SEO description',
                    'footer_title' => 'Footer title metafield',
                    'elegant_footer_description' => 'Elegant footer description metafield',
                    'deindex' => 'Deindex (seo.hide_from_google metafield)',
                ])
                ->columns(2)
                ->default(['title', 'description_html', 'seo_title', 'seo_description', 'footer_title', 'elegant_footer_description', 'deindex']),
            Forms\Components\Select::make('deindex_override')
                ->label('Deindex Decision (optional)')
                ->options([
                    '1' => 'True (hide from search engines)',
                    '0' => 'False (keep indexed)',
                ])
                ->native(false)
                ->nullable()
                ->placeholder('Keep current decision'),
        ];
    }

    public static function queuePushToShopify(ShopifyCollection $record, array $data): void
    {
        $record = $record->fresh() ?? $record;

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
        $handleOverride = self::proposedHandleForSync($record);
        if ($fieldNames === [] && $handleOverride === null) {
            self::sendNotification(
                Notification::make()
                    ->title('Nothing to sync')
                    ->body('Select at least one field, or set a proposed new URL handle on the collection first.')
                    ->warning()
            );
            return;
        }

        $deindexOverride = self::parseNullableBoolean($data['deindex_override'] ?? null);
        ShopifyCollectionUpdateJob::dispatch(
            [$record->id],
            $fieldNames,
            Auth::id(),
            $handleOverride,
            $deindexOverride !== null,
            $deindexOverride,
        );

        self::sendNotification(
            Notification::make()
                ->title('Collection sync queued')
                ->body('This collection will be pushed to Shopify in the background.')
                ->success()
        );
    }

    private static function parseNullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function proposedHandleForSync(ShopifyCollection $record): ?string
    {
        $draftHandle = self::normalizeHandleInput($record->draft_handle);
        $currentHandle = trim((string) ($record->handle ?? ''));

        if ($draftHandle === '' || $draftHandle === $currentHandle) {
            return null;
        }

        return $draftHandle;
    }

    private static function normalizeHandleInput(mixed $value): ?string
    {
        $handle = trim((string) ($value ?? ''));
        if ($handle === '') {
            return null;
        }

        $handle = preg_replace('#^https?://[^/]+/#i', '', $handle) ?? $handle;
        $handle = ltrim($handle, '/');

        if (str_starts_with(strtolower($handle), 'collections/')) {
            $handle = substr($handle, strlen('collections/'));
        }

        $handle = trim($handle, " \t\n\r\0\x0B/");

        return $handle === '' ? null : $handle;
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
                'footer_title' => self::preferredDraftValue($record->draft_footer_title, $record->footer_title),
                'elegant_footer_description' => self::preferredDraftValue($record->draft_elegant_footer_description, $record->elegant_footer_description),
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
        AdminNotification::send($notification);
    }

    private static function shopifySyncWarningsHtml(?ShopifyCollection $record): ?HtmlString
    {
        if (!$record) {
            return null;
        }

        $warnings = $record->shopifySyncWarnings();
        if (empty($warnings)) {
            return null;
        }

        $items = array_map(function (array $warning): string {
            $label = e((string) ($warning['label'] ?? $warning['field'] ?? 'Field'));
            $draftValue = e((string) ($warning['draft_value'] ?? ''));
            $shopifyValue = e((string) ($warning['shopify_value'] ?? ''));

            return "<li><strong>{$label}</strong>: draft has <code>{$draftValue}</code> but Shopify imported <code>{$shopifyValue}</code>.</li>";
        }, $warnings);

        return new HtmlString(
            "<div class='rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-900'>"
            . "<p class='font-semibold mb-2'>Draft values differ from the latest Shopify import.</p>"
            . "<ul class='list-disc pl-5 space-y-1'>"
            . implode('', $items)
            . '</ul>'
            . '</div>'
        );
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
            if (!$record instanceof ShopifyCollection) {
                continue;
            }

            $warnings = $record->shopifySyncWarnings();
            if (empty($warnings)) {
                $skipped++;
                continue;
            }

            $updates = [];
            if (ShopifyCollection::supportsShopifySyncWarningsColumn()) {
                $updates['shopify_sync_warnings'] = null;
            }

            foreach ($warnings as $warning) {
                $field = trim((string) ($warning['field'] ?? ''));
                if ($field === '') {
                    continue;
                }

                $updates[$field] = self::draftWarningResolvedValue((string) ($warning['shopify_value'] ?? ''));
            }

            if ($updates === []) {
                $skipped++;
                continue;
            }

            ShopifyCollection::withoutEvents(function () use ($record, $updates): void {
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
     * @return array{cleared:int,skipped:int}
     */
    private static function keepDraftWarningValues(iterable $records): array
    {
        $cleared = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (!$record instanceof ShopifyCollection) {
                continue;
            }

            $warnings = $record->shopifySyncWarnings();
            if (empty($warnings)) {
                $skipped++;
                continue;
            }

            ShopifyCollection::withoutEvents(function () use ($record): void {
                if (!ShopifyCollection::supportsShopifySyncWarningsColumn()) {
                    return;
                }

                $record->forceFill([
                    'shopify_sync_warnings' => null,
                ])->save();
            });

            $cleared++;
        }

        return [
            'cleared' => $cleared,
            'skipped' => $skipped,
        ];
    }

    private static function draftWarningResolvedValue(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $value;
    }

    private static function warningResolutionSummary(int $resolved, int $skipped): string
    {
        $parts = [];

        if ($resolved > 0) {
            $parts[] = "Resolved {$resolved}.";
        }
        if ($skipped > 0) {
            $parts[] = "Skipped {$skipped} without warnings.";
        }

        return $parts === [] ? 'No collections were updated.' : implode(' ', $parts);
    }

    private static function currentDeletionRequest(ShopifyCollection $record): ?DeletionRequest
    {
        return app(DeletionRequestWorkflowService::class)->openRequestFor($record);
    }

    private static function canApproveDeletion(ShopifyCollection $record): bool
    {
        $request = self::currentDeletionRequest($record);

        return $request !== null
            && $request->status === DeletionRequest::STATUS_PENDING
            && !$request->userHasApproved(Auth::id());
    }

    private static function deletionRequestStatusLabel(ShopifyCollection $record): string
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

    private static function currentImportId(): ?int
    {
        return \App\Models\Import::where('filename', 'shopify-collections')
            ->orderByDesc('id')
            ->value('id');
    }
}
