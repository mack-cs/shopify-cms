<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Models\Import;
use App\Models\Setting;
use App\Models\Product;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use App\Services\ShopifyCsvExporter;
use App\Services\ShopifyCsvImporter;
use App\Services\ShopifyCsvValidator;
use App\Services\ShopifyApiImporter;
use App\Jobs\ShopifySyncJob;
use App\Services\Normalizer;
use App\Services\HeaderStore;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Builder;
use App\Models\ShopifyRow;
use App\Filament\Resources\ImportResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\ImportResource\RelationManagers;

class ImportResource extends Resource
{
    protected static ?string $model = Import::class;
protected static ?string $navigationGroup = 'Product Data';
protected static ?string $navigationLabel = 'Product Feed';
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                 FileUpload::make('filename')
                    ->label('CSV File')
                    ->disk('public')
                    ->directory('imports')
                    ->acceptedFileTypes(['text/csv'])
                    ->required()
                    ->reactive(),
            Select::make('mode')
                ->options(['overwrite' => 'Overwrite', 'append' => 'Append'])
                ->default('overwrite')
                ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('id'),
            TextColumn::make('filename'),
            TextColumn::make('mode'),
            TextColumn::make('status'),
            TextColumn::make('state')
                ->label('State')
                ->state(fn (Import $record) => $record->is_current ? 'Current' : 'Archived')
                ->badge()
                ->color(fn (Import $record) => $record->is_current ? 'success' : 'gray'),
            TextColumn::make('is_valid')
                ->label('Validation')
                ->badge()
                ->state(fn (Import $record) => $record->is_valid ? 'Valid' : 'Invalid')
                ->color(fn (Import $record) => $record->is_valid ? 'success' : 'danger'),
            TextColumn::make('created_at')->dateTime(),
        ])->headerActions([
            Action::make('syncFromShopify')
                ->label('Sync from Shopify')
                ->requiresConfirmation()
                ->color('primary')
                ->visible(function (): bool {
                    $user = Auth::user();
                    if (!$user) {
                        return false;
                    }
                    return $user->can(PermissionEnum::ShopifyManage->value)
                        || $user->can(PermissionEnum::ImportCreate->value);
                })
                ->action(function (ShopifyApiImporter $importer): void {
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
                        $import = $importer->createOrReuseCurrentImport($user->id);
                        ShopifySyncJob::dispatch($import->id);
                        $lastSync = Setting::getValue('shopify_last_sync_at');
                        $lastSyncLabel = $lastSync ? " Last sync: {$lastSync}." : '';
                        self::sendNotification(
                            Notification::make()
                                ->title('Shopify sync queued')
                                ->body("Import #{$import->id} is processing in the background.{$lastSyncLabel}")
                                ->success()
                        );
                    } catch (\Throwable $e) {
                        self::sendNotification(
                            Notification::make()
                                ->title('Shopify sync failed')
                                ->body($e->getMessage())
                                ->danger()
                        );
                    }
                }),
        ])->actions([
            // Action::make('validateImport')
            //     ->label('Validate CSV')
            //     ->requiresConfirmation()
            //     ->disabled(fn (Import $record) => !$record->is_current)
            //     ->action(function (Import $record, ShopifyCsvValidator $validator) {
            //         $result = self::validateImportRecord($record, $validator);
            //         if ($result['valid']) {
            //             self::sendNotification(
            //                 Notification::make()
            //                     ->title('CSV looks valid')
            //                     ->success()
            //             );
            //             return;
            //         }

            //         $body = self::formatValidationErrors($result['errors']);

            //         self::sendNotification(
            //             Notification::make()
            //                 ->title('CSV validation failed')
            //                 ->body($body)
            //                 ->danger()
            //         );
            //     }),
            Action::make('runImport')
    ->label('Process Import')
    ->requiresConfirmation()
    ->disabled(fn (Import $record) => !$record->is_current || !$record->is_valid || $record->status === 'processing' || $record->status === 'ready')
    ->action(function (Import $record, ShopifyCsvImporter $importer) {

        // Your FileUpload uses disk('public'), so use the same disk here:
        $disk = Storage::disk('public');

        if (!$record->is_valid) {
            self::sendNotification(
                Notification::make()
                    ->title('Import blocked')
                    ->body('CSV is invalid. Run validation and upload a corrected file.')
                    ->danger()
            );
            return;
        }

        if (!$record->filename) {
            self::sendNotification(
                Notification::make()
                    ->title('Missing file path')
                    ->danger()
            );
            return;
        }

        if (!$disk->exists($record->filename)) {
            self::sendNotification(
                Notification::make()
                    ->title('File not found')
                    ->body("Could not find: {$record->filename} on public disk")
                    ->danger()
            );
            return;
        }

        $absolutePath = $disk->path($record->filename);
        $templatePath = HeaderStore::latestTemplatePath();
        if ($templatePath === null) {
            self::sendNotification(
                Notification::make()
                    ->title('Template missing')
                    ->body('No CSV template found in storage/app/public/template or legacy imports.')
                    ->danger()
            );
            return;
        }
        $validator = app(ShopifyCsvValidator::class);
        $validation = $validator->validateAgainstTemplate($absolutePath, $templatePath);
        if (!$validation['valid']) {
            $record->forceFill(['is_valid' => false, 'status' => 'failed'])->save();
            $body = self::formatValidationErrors($validation['errors']);

            self::sendNotification(
                Notification::make()
                    ->title('Import rejected: invalid CSV')
                    ->body($body)
                    ->danger()
            );
            return;
        }

        if ($record->mode === 'overwrite') {
            ShopifyRow::where('import_id', '!=', $record->id)->delete();
            Product::where('import_id', '!=', $record->id)->delete();
        }

        $record->update(['status' => 'processing']);

        // ✅ IMPORTANT: process THIS Import record
        $importer->importIntoExistingImport($record, $absolutePath);

        self::sendNotification(
            Notification::make()
                ->title('Import processed')
                ->body("Import #{$record->id} is ready")
                ->success()
        );
    }),
            Action::make('exportAll')
            ->label('Export (All)')
            ->disabled(fn (Import $record) => !$record->is_current || !$record->is_valid || $record->status !== 'ready')
            ->visible(fn (): bool => Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false)
            ->action(function (Import $record, ShopifyCsvExporter $exporter, Normalizer $normalizer) {
                if (!(Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false)) {
                    self::sendNotification(
                        Notification::make()
                            ->title('Export blocked')
                            ->body('Only Super Admin can export all products.')
                            ->danger()
                    );
                    return;
                }
                $normalizer->recalculateErrors($record);
                if (Product::where('import_id', $record->id)->where('has_errors', true)->exists()) {
                    self::sendNotification(
                        Notification::make()
                            ->title('Export blocked')
                            ->body('Fix required fields before exporting.')
                            ->danger()
                    );
                    return;
                }
                $csv = $exporter->exportToString($record, 'all');
                $timestamp = now()->format('Ymd_His');
                $name = "products_{$timestamp}_all.csv";
                $disk = Storage::disk('public');
                $disk->put("exports/{$name}", $csv);
                $url = $disk->url("exports/{$name}");

                self::sendNotification(
                    Notification::make()
                        ->title('Export created')
                ->body("Saved to public/exports/{$name}")
                ->success()
                ->actions([
                    NotificationAction::make('download')
                                ->label('Download')
                                ->url($url, shouldOpenInNewTab: true),
                        ])
                );
            }),

        Action::make('exportApproved')
            ->label('Export (Approved)')
            ->disabled(fn (Import $record) => !$record->is_current || !$record->is_valid || $record->status !== 'ready')
            ->action(function (Import $record, ShopifyCsvExporter $exporter, Normalizer $normalizer) {
                $normalizer->recalculateErrors($record);
                $approvedHandles = Product::where('import_id', $record->id)
                    ->get()
                    ->filter(fn ($p) => $p->isApprovedByTwo())
                    ->pluck('handle')
                    ->filter()
                    ->values()
                    ->all();

                if (!empty($approvedHandles)) {
                    $hasErrors = Product::where('import_id', $record->id)
                        ->whereIn('handle', $approvedHandles)
                        ->where('has_errors', true)
                        ->exists();
                    if ($hasErrors) {
                        self::sendNotification(
                            Notification::make()
                                ->title('Export blocked')
                                ->body('Fix required fields for approved products before exporting.')
                                ->danger()
                        );
                        return;
                    }
                }

                $totalHandles = Product::where('import_id', $record->id)->count();
                $approvedCount = count($approvedHandles);

                if ($approvedCount === 0) {
                    self::sendNotification(
                        Notification::make()
                            ->title('Nothing to export')
                            ->body('No products are approved for export yet (need 2 approvals each).')
                            ->warning()
                    );
                    return;
                }

                if ($approvedCount < $totalHandles) {
                    $notApproved = $totalHandles - $approvedCount;
                    self::sendNotification(
                        Notification::make()
                            ->title('Partial export')
                            ->body("Exporting {$approvedCount} approved products. {$notApproved} are not approved yet.")
                            ->warning()
                    );
                }

                $csv = $exporter->exportToString($record, 'approved');
                $timestamp = now()->format('Ymd_His');
                $name = "products_{$timestamp}_approved.csv";

                // If you want it downloadable easily, use public disk
                $disk = Storage::disk('public');
                $disk->put("exports/{$name}", $csv);
                $url = $disk->url("exports/{$name}");

                self::sendNotification(
                    Notification::make()
                        ->title('Export created')
                        ->body("Saved to public/exports/{$name}")
                        ->success()
                        ->actions([
                            NotificationAction::make('download')
                                ->label('Download')
                                ->url($url, shouldOpenInNewTab: true),
                        ])
                );
            })
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(PermissionEnum::ImportViewCurrent->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->can(PermissionEnum::ImportCreate->value) ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->can(PermissionEnum::ImportDelete->value) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->can(PermissionEnum::ImportDelete->value) ?? false;
    }

    public static function validateImportRecord(Import $record, ShopifyCsvValidator $validator): array
    {
        $disk = Storage::disk('public');

        if (!$record->filename || !$disk->exists($record->filename)) {
            $record->forceFill(['is_valid' => false, 'status' => 'failed'])->save();
            return [
                'valid' => false,
                'errors' => ['File not found.'],
            ];
        }

        $absolutePath = $disk->path($record->filename);
        $templatePath = HeaderStore::latestTemplatePath();
        if ($templatePath === null) {
            $record->forceFill(['is_valid' => false, 'status' => 'failed'])->save();
            return [
                'valid' => false,
                'errors' => ['Template file missing.'],
            ];
        }

        $result = $validator->validateAgainstTemplate($absolutePath, $templatePath);
        $record->forceFill([
            'is_valid' => (bool) $result['valid'],
            'status' => $result['valid'] ? 'uploaded' : 'failed',
        ])->save();

        return $result;
    }

    public static function formatValidationErrors(array $errors): string
    {
        $preview = array_slice($errors, 0, 5);
        $moreCount = max(0, count($errors) - count($preview));
        $body = implode("\n", $preview);
        if ($moreCount > 0) {
            $body .= "\n...and {$moreCount} more.";
        }

        return $body;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImports::route('/'),
            'edit' => Pages\EditImport::route('/{record}/edit'),
        ];
    }

    private static function sendNotification(Notification $notification): void
    {
        if ($user = Auth::user()) {
            $notification->sendToDatabase($user);
        }
        $notification->send();
    }
}
