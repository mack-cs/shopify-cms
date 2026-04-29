<?php

namespace App\Filament\Resources;

use App\Enums\PermissionEnum;
use App\Models\Product;
use App\Models\ProductUrlRedirect;
use App\Services\AdminNotification;
use App\Services\ProductUrlRedirectService;
use Filament\Forms\Form;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
use App\Filament\Resources\ProductUrlRedirectResource\Pages;

class ProductUrlRedirectResource extends Resource
{
    protected static ?string $model = ProductUrlRedirect::class;
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'URL Redirects';
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
                TextColumn::make('product.handle')
                    ->label('Product')
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
                        ProductUrlRedirect::STATUS_SYNCED => 'success',
                        ProductUrlRedirect::STATUS_FAILED => 'danger',
                        ProductUrlRedirect::STATUS_IGNORED => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('synced_at')
                    ->dateTime()
                    ->toggleable(),
                TextColumn::make('last_error')
                    ->label('Last Error')
                    ->limit(80)
                    ->tooltip(fn (ProductUrlRedirect $record): ?string => $record->last_error)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ProductUrlRedirect::STATUS_PENDING => 'Pending',
                        ProductUrlRedirect::STATUS_SYNCED => 'Synced',
                        ProductUrlRedirect::STATUS_FAILED => 'Failed',
                        ProductUrlRedirect::STATUS_IGNORED => 'Ignored',
                    ]),
            ])
            ->headerActions([
                Action::make('createRedirect')
                    ->label('Add Redirect')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form([
                        Select::make('product_id')
                            ->label('Product')
                            ->required()
                            ->options(fn (): array => Product::query()
                                ->orderBy('handle')
                                ->limit(500)
                                ->get()
                                ->mapWithKeys(fn (Product $product): array => [
                                    $product->id => trim(($product->handle ?? '') . ' - ' . ($product->title ?? '')),
                                ])
                                ->all())
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Product::query()
                                ->where('handle', 'like', "%{$search}%")
                                ->orWhere('title', 'like', "%{$search}%")
                                ->orderBy('handle')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Product $product): array => [
                                    $product->id => trim(($product->handle ?? '') . ' - ' . ($product->title ?? '')),
                                ])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Product::query()
                                ->whereKey($value)
                                ->get()
                                ->map(fn (Product $product): string => trim(($product->handle ?? '') . ' - ' . ($product->title ?? '')))
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

                        ProductUrlRedirect::query()->updateOrCreate(
                            ['path' => "/products/{$oldHandle}"],
                            [
                                'product_id' => (int) $data['product_id'],
                                'created_by' => Auth::id(),
                                'old_handle' => $oldHandle,
                                'new_handle' => $newHandle,
                                'target' => "/products/{$newHandle}",
                                'status' => ProductUrlRedirect::STATUS_PENDING,
                                'shopify_redirect_id' => null,
                                'last_error' => null,
                                'synced_at' => null,
                            ]
                        );

                        self::sendNotification(Notification::make()
                            ->title('Redirect created')
                            ->body("Created pending redirect from /products/{$oldHandle} to /products/{$newHandle}.")
                            ->success()
                        );
                    }),
                Action::make('exportPending')
                    ->label('Export Pending CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(function (ProductUrlRedirectService $service): void {
                        $redirects = ProductUrlRedirect::query()
                            ->whereIn('status', [
                                ProductUrlRedirect::STATUS_PENDING,
                                ProductUrlRedirect::STATUS_FAILED,
                            ])
                            ->orderBy('id')
                            ->get();

                        self::notifyExport($service, $redirects, 'pending');
                    }),
                Action::make('exportHistory')
                    ->label('Export History CSV')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('gray')
                    ->action(function (ProductUrlRedirectService $service): void {
                        $redirects = ProductUrlRedirect::query()
                            ->with(['product:id,handle,shopify_id', 'creator:id,email'])
                            ->orderBy('id')
                            ->get();

                        self::notifyHistoryExport($service, $redirects, 'all');
                    }),
                Action::make('importHistory')
                    ->label('Import History CSV')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('info')
                    ->form([
                        \Filament\Forms\Components\FileUpload::make('file')
                            ->label('CSV File')
                            ->required()
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->helperText('Use a history export from URL Redirects. The import upserts by redirect path and preserves created and updated dates from the CSV.'),
                    ])
                    ->action(function (array $data, ProductUrlRedirectService $service): void {
                        $path = Storage::disk('local')->path($data['file']);
                        $result = $service->importHistoryFromPath($path);

                        self::sendNotification(Notification::make()
                            ->title('Redirect history import complete')
                            ->body(
                                "Total: {$result['total']}, Created: {$result['created']}, " .
                                "Updated: {$result['updated']}, Missing product: {$result['skipped_missing_product']}, " .
                                "Invalid rows: {$result['skipped_invalid']}."
                            )
                            ->success()
                        );
                    }),
                Action::make('syncPending')
                    ->label('Sync Pending to Shopify')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $ids = ProductUrlRedirect::query()
                            ->whereIn('status', [
                                ProductUrlRedirect::STATUS_PENDING,
                                ProductUrlRedirect::STATUS_FAILED,
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

                        \App\Jobs\ProductUrlRedirectSyncJob::dispatch($ids, Auth::id());

                        self::sendNotification(Notification::make()
                            ->title('Redirect sync queued')
                            ->body('Queued pending URL redirects for Shopify sync.')
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
                    ->visible(fn (ProductUrlRedirect $record): bool => $record->status !== ProductUrlRedirect::STATUS_SYNCED)
                    ->action(function (ProductUrlRedirect $record): void {
                        \App\Jobs\ProductUrlRedirectSyncJob::dispatch([(int) $record->id], Auth::id());

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
                    ->visible(fn (ProductUrlRedirect $record): bool => $record->status !== ProductUrlRedirect::STATUS_SYNCED && $record->status !== ProductUrlRedirect::STATUS_IGNORED)
                    ->action(function (ProductUrlRedirect $record): void {
                        $record->forceFill([
                            'status' => ProductUrlRedirect::STATUS_IGNORED,
                            'last_error' => null,
                        ])->save();
                    }),
                Action::make('deleteRecord')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete redirect record')
                    ->modalDescription('This deletes only the local redirect record from this app. It does not remove the redirect from Shopify.')
                    ->action(function (ProductUrlRedirect $record): void {
                        $path = $record->path;
                        $record->delete();

                        self::sendNotification(Notification::make()
                            ->title('Redirect record deleted')
                            ->body("Deleted local redirect record {$path}. Shopify was not changed.")
                            ->success()
                        );
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
                            \App\Jobs\ProductUrlRedirectSyncJob::dispatch($ids, Auth::id());

                            self::sendNotification(Notification::make()
                                ->title('Redirect sync queued')
                                ->body('Queued selected URL redirects for Shopify sync.')
                                ->success()
                            );
                        }),
                    BulkAction::make('exportSelected')
                        ->label('Export Selected CSV')
                        ->icon('heroicon-o-document-arrow-down')
                        ->action(function (Collection $records, ProductUrlRedirectService $service): void {
                            self::notifyExport($service, $records, 'selected');
                        }),
                    BulkAction::make('ignoreSelected')
                        ->label('Ignore Selected')
                        ->icon('heroicon-o-eye-slash')
                        ->action(function (Collection $records): void {
                            ProductUrlRedirect::query()
                                ->whereIn('id', $records->pluck('id')->all())
                                ->update([
                                    'status' => ProductUrlRedirect::STATUS_IGNORED,
                                    'last_error' => null,
                                ]);
                        }),
                    BulkAction::make('deleteSelected')
                        ->label('Delete Selected')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected redirect records')
                        ->modalDescription('This deletes only the selected local redirect records from this app. It does not remove redirects from Shopify.')
                        ->action(function (Collection $records): void {
                            $ids = $records->pluck('id')->map(fn ($id): int => (int) $id)->all();
                            $count = count($ids);

                            if ($count === 0) {
                                self::sendNotification(Notification::make()
                                    ->title('Nothing deleted')
                                    ->body('No redirect records were selected.')
                                    ->warning()
                                );
                                return;
                            }

                            ProductUrlRedirect::query()
                                ->whereIn('id', $ids)
                                ->delete();

                            self::sendNotification(Notification::make()
                                ->title('Redirect records deleted')
                                ->body("Deleted {$count} local redirect record(s). Shopify was not changed.")
                                ->success()
                            );
                        }),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('product');
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(PermissionEnum::ShopifyManage->value) ?? false;
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
        return Auth::user()?->can(PermissionEnum::ShopifyManage->value) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->can(PermissionEnum::ShopifyManage->value) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductUrlRedirects::route('/'),
        ];
    }

    private static function notifyExport(ProductUrlRedirectService $service, Collection $redirects, string $scopeLabel): void
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

    private static function notifyHistoryExport(ProductUrlRedirectService $service, Collection $redirects, string $scopeLabel): void
    {
        if ($redirects->isEmpty()) {
            self::sendNotification(Notification::make()
                ->title('Nothing to export')
                ->body("There are no {$scopeLabel} redirect history rows to export.")
                ->warning()
            );
            return;
        }

        $export = $service->exportHistory($redirects);
        $url = Storage::disk($export['disk'])->url($export['path']);

        self::sendNotification(Notification::make()
            ->title('Redirect history CSV created')
            ->body("Saved {$export['row_count']} redirect history row(s) to {$export['path']}")
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
