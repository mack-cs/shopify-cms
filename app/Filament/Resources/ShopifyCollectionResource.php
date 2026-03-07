<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Exports\ShopifyCollectionExporter;
use App\Filament\Resources\ShopifyCollectionResource\Pages;
use App\Models\CollectionApproval;
use App\Models\ShopifyCollection;
use App\Services\ShopifyCollectionsImporter;
use App\Services\ShopifyCollectionUpdater;
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
use Illuminate\Support\Facades\Auth;
use App\Jobs\ShopifyCollectionsSyncJob;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Support\Facades\Storage;

class ShopifyCollectionResource extends Resource
{
    protected static ?string $model = ShopifyCollection::class;
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Collections';
    protected static ?int $navigationSort = 5;

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
                Forms\Components\Section::make('Marketing Channels & Indexing')
                    ->schema([
                        Forms\Components\Placeholder::make('published_channel_names')
                            ->label('Published Channels')
                            ->content(fn (ShopifyCollection $record): string => $record->published_channel_names ?: 'Not published on any channel')
                            ->columnSpan(1),
                        Forms\Components\Placeholder::make('online_only_warning')
                            ->label('Action Needed')
                            ->content('This collection is published only to Online Store. Set Deindex to true or false before pushing to Shopify.')
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
                Forms\Components\Section::make('Draft SEO (make edits here)')
                    ->schema([
                        Forms\Components\TextInput::make('draft_title')
                            ->label('Draft Title')
                            ->maxLength(255),
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
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->requiresConfirmation()
                    ->action(function (ShopifyCollection $record): void {
                        if (!self::canApprove($record)) {
                            Notification::make()
                                ->title('Cannot approve')
                                ->body(self::approvalBlockMessage($record))
                                ->warning()
                                ->send();
                            return;
                        }

                        $exists = CollectionApproval::where('collection_id', $record->id)
                            ->where('user_id', Auth::id())
                            ->where('approval_version', $record->approval_version)
                            ->exists();

                        if ($exists) {
                            Notification::make()
                                ->title('Already approved')
                                ->body('You have already approved this version.')
                                ->warning()
                                ->send();
                            return;
                        }

                        CollectionApproval::create([
                            'collection_id' => $record->id,
                            'user_id' => Auth::id(),
                            'approval_version' => $record->approval_version,
                        ]);

                        if ($record->approvalsForCurrentVersionCount() >= 2) {
                            self::applyApprovedDrafts($record);
                        }

                        Notification::make()
                            ->title('Approval recorded')
                            ->success()
                            ->send();
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
                                'seo_title' => 'SEO title',
                                'seo_description' => 'SEO description',
                                'deindex' => 'Deindex (seo.hide_from_google metafield)',
                            ])
                            ->columns(2)
                            ->default(['title', 'seo_title', 'seo_description', 'deindex']),
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
                    ->action(function (ShopifyCollection $record, array $data, ShopifyCollectionUpdater $updater): void {
                        if (!$record->isApprovedByTwo()) {
                            Notification::make()
                                ->title('Approval required')
                                ->body('This collection needs 2 approvals before syncing to Shopify.')
                                ->warning()
                                ->send();
                            return;
                        }

                        $fields = array_fill_keys($data['fields'] ?? [], true);
                        if (empty($fields)) {
                            Notification::make()
                                ->title('Choose at least one field')
                                ->warning()
                                ->send();
                            return;
                        }

                        $deindexOverride = self::parseNullableBoolean($data['deindex_override'] ?? null);
                        if ($deindexOverride !== null) {
                            $record->forceFill(['deindex' => $deindexOverride])->save();
                            $record->refresh();
                        }

                        if (self::requiresDeindexDecision($record)) {
                            Notification::make()
                                ->title('Action needed before Shopify push')
                                ->body('This collection is only on Online Store. Set Deindex to true or false first.')
                                ->warning()
                                ->send();
                            return;
                        }

                        try {
                            $payload = self::collectionPayload($record, $fields, $data);
                            if ($payload === []) {
                                Notification::make()
                                    ->title('No fields to update')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            $updater->update($record, $payload);

                            Notification::make()
                                ->title('Collection pushed to Shopify')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Shopify update failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
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
                                "Not Found: {$result['skipped_not_found']}"
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
                        ->action(function ($records): void {
                            $approvedCount = 0;
                            $skippedCount = 0;
                            $blockedCount = 0;

                            foreach ($records as $record) {
                                if (!self::canApprove($record)) {
                                    $blockedCount++;
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

                                CollectionApproval::create([
                                    'collection_id' => $record->id,
                                    'user_id' => Auth::id(),
                                    'approval_version' => $record->approval_version,
                                ]);
                                $approvedCount++;

                                if ($record->approvalsForCurrentVersionCount() >= 2) {
                                    self::applyApprovedDrafts($record);
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
                                $parts[] = "Blocked {$blockedCount}: set deindex=true or complete draft SEO.";
                            }

                            Notification::make()
                                ->title('Bulk approval complete')
                                ->body($parts ? implode(' ', $parts) : 'No collections were approved.')
                                ->success()
                                ->send();
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
                                    'seo_title' => 'SEO title',
                                    'seo_description' => 'SEO description',
                                    'deindex' => 'Deindex (seo.hide_from_google metafield)',
                                ])
                                ->columns(2)
                                ->default(['title', 'seo_title', 'seo_description', 'deindex']),
                        ])
                        ->action(function ($records, array $data, ShopifyCollectionUpdater $updater): void {
                            $fields = array_fill_keys($data['fields'] ?? [], true);
                            if (empty($fields)) {
                                Notification::make()
                                    ->title('Choose at least one field')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            $synced = 0;
                            $skippedNotApproved = 0;
                            $skippedActionRequired = 0;
                            $failed = 0;
                            foreach ($records as $record) {
                                if (!$record->isApprovedByTwo()) {
                                    $skippedNotApproved++;
                                    continue;
                                }
                                if (self::requiresDeindexDecision($record)) {
                                    $skippedActionRequired++;
                                    continue;
                                }

                                try {
                                    $payload = self::collectionPayload($record, $fields);
                                    if ($payload === []) {
                                        continue;
                                    }
                                    $updater->update($record, $payload);
                                    $synced++;
                                } catch (\Throwable $e) {
                                    $failed++;
                                }
                            }

                            $message = "Synced {$synced} collection(s).";
                            if ($skippedNotApproved > 0) {
                                $message .= " Skipped not approved: {$skippedNotApproved}.";
                            }
                            if ($skippedActionRequired > 0) {
                                $message .= " Skipped action required (set Deindex true/false): {$skippedActionRequired}.";
                            }
                            if ($failed > 0) {
                                $message .= " Failed: {$failed}.";
                            }

                            Notification::make()
                                ->title('Collection sync complete')
                                ->body($message)
                                ->success()
                                ->send();
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
        $importId = \App\Models\Import::where('filename', 'shopify-collections')
            ->orderByDesc('id')
            ->value('id');
        if (!$importId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('import_id', $importId);
    }

    private static function collectionPayload(ShopifyCollection $record, array $fields, array $data = []): array
    {
        $payload = [];
        if (!empty($fields['title'])) {
            $payload['title'] = $record->title;
        }
        if (!empty($fields['description_html'])) {
            $payload['description_html'] = $record->description_html;
        }
        $handleOverride = trim((string) ($data['handle_override'] ?? ''));
        if ($handleOverride !== '') {
            $payload['handle'] = $handleOverride;
        }
        if (!empty($fields['seo_title'])) {
            $payload['seo_title'] = $record->seo_title;
        }
        if (!empty($fields['seo_description'])) {
            $payload['seo_description'] = $record->seo_description;
        }
        if (!empty($fields['deindex']) && $record->deindex !== null) {
            $payload['deindex'] = (bool) $record->deindex;
        }

        return $payload;
    }

    private static function parseNullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function requiresDeindexDecision(ShopifyCollection $record): bool
    {
        return (bool) $record->published_on_online_store_only && $record->deindex === null;
    }

    private static function draftSeoComplete(ShopifyCollection $record): bool
    {
        return trim((string) ($record->draft_title ?? '')) !== ''
            && trim((string) ($record->draft_seo_title ?? '')) !== ''
            && trim((string) ($record->draft_seo_description ?? '')) !== '';
    }

    private static function canApprove(ShopifyCollection $record): bool
    {
        if (self::requiresDeindexDecision($record)) {
            return false;
        }

        if ($record->deindex === true) {
            return true;
        }

        return self::draftSeoComplete($record);
    }

    private static function approvalBlockMessage(ShopifyCollection $record): string
    {
        if (self::requiresDeindexDecision($record)) {
            return 'This collection is only on Online Store. Choose deindex true or false before approval.';
        }

        if ($record->deindex === true) {
            return 'Approval is blocked unexpectedly. Please retry.';
        }

        return 'Approval requires either deindex=true or complete draft SEO fields (title, SEO title, SEO description).';
    }

    private static function applyApprovedDrafts(ShopifyCollection $record): void
    {
        ShopifyCollection::withoutEvents(function () use ($record): void {
            $record->forceFill([
                'title' => $record->draft_title,
                'description_html' => $record->draft_description_html,
                'seo_title' => $record->draft_seo_title,
                'seo_description' => $record->draft_seo_description,
            ])->save();
        });
    }
}
