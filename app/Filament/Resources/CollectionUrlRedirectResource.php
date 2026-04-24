<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Models\CollectionUrlRedirect;
use App\Models\ShopifyCollection;
use App\Services\AdminNotification;
use App\Services\CollectionHandleService;
use App\Services\CollectionUrlRedirectService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Filament\Resources\CollectionUrlRedirectResource\Pages;

class CollectionUrlRedirectResource extends Resource
{
    protected static ?string $model = CollectionUrlRedirect::class;
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'Collection URL Redirects';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-right';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('collection.handle')
                    ->label('Collection')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('old_handle')
                    ->label('Old Handle')
                    ->searchable(),
                TextColumn::make('new_handle')
                    ->label('New Handle')
                    ->searchable(),
                TextColumn::make('path')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('target')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CollectionUrlRedirect::STATUS_SYNCED => 'success',
                        CollectionUrlRedirect::STATUS_FAILED => 'danger',
                        CollectionUrlRedirect::STATUS_IGNORED => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('synced_at')
                    ->dateTime()
                    ->toggleable(),
                TextColumn::make('last_error')
                    ->label('Last Error')
                    ->limit(80)
                    ->tooltip(fn (CollectionUrlRedirect $record): ?string => $record->last_error)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        CollectionUrlRedirect::STATUS_PENDING => 'Pending',
                        CollectionUrlRedirect::STATUS_SYNCED => 'Synced',
                        CollectionUrlRedirect::STATUS_FAILED => 'Failed',
                        CollectionUrlRedirect::STATUS_IGNORED => 'Ignored',
                    ]),
            ])
            ->headerActions([
                Action::make('createRedirect')
                    ->label('Add Redirect')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form([
                        Select::make('collection_id')
                            ->label('Collection')
                            ->required()
                            ->options(fn (): array => ShopifyCollection::query()
                                ->orderBy('handle')
                                ->limit(500)
                                ->get()
                                ->mapWithKeys(fn (ShopifyCollection $collection): array => [
                                    $collection->id => trim(($collection->handle ?? '') . ' - ' . ($collection->title ?? '')),
                                ])
                                ->all())
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => ShopifyCollection::query()
                                ->where('handle', 'like', "%{$search}%")
                                ->orWhere('title', 'like', "%{$search}%")
                                ->orderBy('handle')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (ShopifyCollection $collection): array => [
                                    $collection->id => trim(($collection->handle ?? '') . ' - ' . ($collection->title ?? '')),
                                ])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => ShopifyCollection::query()
                                ->whereKey($value)
                                ->get()
                                ->map(fn (ShopifyCollection $collection): string => trim(($collection->handle ?? '') . ' - ' . ($collection->title ?? '')))
                                ->first())
                            ->preload(),
                        TextInput::make('old_handle')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('new_handle')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        $oldHandle = trim((string) ($data['old_handle'] ?? ''));
                        $newHandle = trim((string) ($data['new_handle'] ?? ''));

                        if ($oldHandle === '' || $newHandle === '') {
                            self::sendNotification(Notification::make()
                                ->title('Redirect not created')
                                ->body('Both old and new handles are required.')
                                ->warning()
                            );
                            return;
                        }

                        $collection = ShopifyCollection::query()->find((int) $data['collection_id']);
                        if (!$collection) {
                            self::sendNotification(Notification::make()
                                ->title('Redirect not created')
                                ->body('Collection not found.')
                                ->warning()
                            );
                            return;
                        }

                        app(CollectionHandleService::class)->createPendingRedirect($collection, $oldHandle, $newHandle, Auth::id());

                        self::sendNotification(Notification::make()
                            ->title('Redirect created')
                            ->body("Created pending redirect from /collections/{$oldHandle} to /collections/{$newHandle}.")
                            ->success()
                        );
                    }),
                Action::make('exportPending')
                    ->label('Export Pending CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(function (CollectionUrlRedirectService $service): void {
                        $redirects = CollectionUrlRedirect::query()
                            ->whereIn('status', [
                                CollectionUrlRedirect::STATUS_PENDING,
                                CollectionUrlRedirect::STATUS_FAILED,
                            ])
                            ->orderBy('id')
                            ->get();

                        self::notifyExport($service, $redirects, 'pending');
                    }),
                Action::make('syncPending')
                    ->label('Sync Pending to Shopify')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $ids = CollectionUrlRedirect::query()
                            ->whereIn('status', [
                                CollectionUrlRedirect::STATUS_PENDING,
                                CollectionUrlRedirect::STATUS_FAILED,
                            ])
                            ->pluck('id')
                            ->map(fn ($id): int => (int) $id)
                            ->all();

                        if (empty($ids)) {
                            self::sendNotification(Notification::make()
                                ->title('Nothing to sync')
                                ->body('There are no pending or failed redirects to sync.')
                                ->warning()
                            );
                            return;
                        }

                        \App\Jobs\CollectionUrlRedirectSyncJob::dispatch($ids, Auth::id());

                        self::sendNotification(Notification::make()
                            ->title('Redirect sync queued')
                            ->body('Queued pending collection URL redirects for Shopify sync.')
                            ->success()
                        );
                    }),
            ])
            ->actions([
                Action::make('sync')
                    ->label('Sync')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn (CollectionUrlRedirect $record): bool => $record->status !== CollectionUrlRedirect::STATUS_SYNCED)
                    ->action(function (CollectionUrlRedirect $record): void {
                        \App\Jobs\CollectionUrlRedirectSyncJob::dispatch([(int) $record->id], Auth::id());

                        self::sendNotification(Notification::make()
                            ->title('Redirect sync queued')
                            ->body("Queued redirect {$record->path} for Shopify sync.")
                            ->success()
                        );
                    }),
                Action::make('ignore')
                    ->label('Ignore')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn (CollectionUrlRedirect $record): bool => $record->status !== CollectionUrlRedirect::STATUS_SYNCED && $record->status !== CollectionUrlRedirect::STATUS_IGNORED)
                    ->action(function (CollectionUrlRedirect $record): void {
                        $record->forceFill([
                            'status' => CollectionUrlRedirect::STATUS_IGNORED,
                            'last_error' => null,
                        ])->save();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('syncSelected')
                        ->label('Sync Selected')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $ids = $records->pluck('id')->map(fn ($id): int => (int) $id)->all();
                            \App\Jobs\CollectionUrlRedirectSyncJob::dispatch($ids, Auth::id());

                            self::sendNotification(Notification::make()
                                ->title('Redirect sync queued')
                                ->body('Queued selected collection URL redirects for Shopify sync.')
                                ->success()
                            );
                        }),
                    BulkAction::make('exportSelected')
                        ->label('Export Selected CSV')
                        ->icon('heroicon-o-document-arrow-down')
                        ->action(function (Collection $records, CollectionUrlRedirectService $service): void {
                            self::notifyExport($service, $records, 'selected');
                        }),
                    BulkAction::make('ignoreSelected')
                        ->label('Ignore Selected')
                        ->icon('heroicon-o-eye-slash')
                        ->action(function (Collection $records): void {
                            CollectionUrlRedirect::query()
                                ->whereIn('id', $records->pluck('id')->all())
                                ->update([
                                    'status' => CollectionUrlRedirect::STATUS_IGNORED,
                                    'last_error' => null,
                                ]);
                        }),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('collection');
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
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCollectionUrlRedirects::route('/'),
        ];
    }

    private static function notifyExport(CollectionUrlRedirectService $service, Collection $redirects, string $scopeLabel): void
    {
        if ($redirects->isEmpty()) {
            self::sendNotification(Notification::make()
                ->title('Nothing to export')
                ->body("There are no {$scopeLabel} redirects to export.")
                ->warning()
            );
            return;
        }

        $export = $service->exportRedirects($redirects);
        $url = Storage::disk($export['disk'])->url($export['path']);

        self::sendNotification(Notification::make()
            ->title('Redirect CSV created')
            ->body("Saved {$export['row_count']} redirect(s) to {$export['path']}")
            ->success()
            ->actions([
                NotificationAction::make('download')
                    ->label('Download')
                    ->url($url, shouldOpenInNewTab: true),
            ])
        );
    }

    private static function sendNotification(Notification $notification): void
    {
        AdminNotification::send($notification);
    }
}
