<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Import;
use App\Models\Product;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use App\Services\ShopifyCsvExporter;
use App\Services\ShopifyCsvImporter;
use App\Services\ShopifyCsvValidator;
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
            TextColumn::make('created_at')->dateTime(),
        ])->actions([
            Action::make('validateImport')
                ->label('Validate CSV')
                ->requiresConfirmation()
                ->disabled(fn (Import $record) => !$record->is_current)
                ->action(function (Import $record, ShopifyCsvValidator $validator) {
                    $disk = Storage::disk('public');

                    if (!$record->filename || !$disk->exists($record->filename)) {
                        self::sendNotification(
                            Notification::make()
                                ->title('File not found')
                                ->danger()
                        );
                        return;
                    }

                    $absolutePath = $disk->path($record->filename);
                    $templatePath = storage_path('app/private/imports/products.csv');

                    $result = $validator->validateAgainstTemplate($absolutePath, $templatePath);
                    if ($result['valid']) {
                        self::sendNotification(
                            Notification::make()
                                ->title('CSV looks valid')
                                ->success()
                        );
                        return;
                    }

                    $errors = $result['errors'];
                    $preview = array_slice($errors, 0, 5);
                    $moreCount = max(0, count($errors) - count($preview));
                    $body = implode("\n", $preview);
                    if ($moreCount > 0) {
                        $body .= "\n...and {$moreCount} more.";
                    }

                    self::sendNotification(
                        Notification::make()
                            ->title('CSV validation failed')
                            ->body($body)
                            ->danger()
                    );
                }),
            Action::make('runImport')
    ->label('Process Import')
    ->requiresConfirmation()
    ->disabled(fn (Import $record) => !$record->is_current || $record->status === 'processing' || $record->status === 'ready')
    ->action(function (Import $record, ShopifyCsvImporter $importer) {

        // Your FileUpload uses disk('public'), so use the same disk here:
        $disk = Storage::disk('public');

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
        $templatePath = storage_path('app/private/imports/products.csv');
        $validator = app(ShopifyCsvValidator::class);
        $validation = $validator->validateAgainstTemplate($absolutePath, $templatePath);
        if (!$validation['valid']) {
            $errors = $validation['errors'];
            $preview = array_slice($errors, 0, 5);
            $moreCount = max(0, count($errors) - count($preview));
            $body = implode("\n", $preview);
            if ($moreCount > 0) {
                $body .= "\n...and {$moreCount} more.";
            }

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
            ->disabled(fn (Import $record) => !$record->is_current || $record->status !== 'ready')
            ->action(function (Import $record, ShopifyCsvExporter $exporter) {
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
            ->disabled(fn (Import $record) => !$record->is_current || $record->status !== 'ready')
            ->action(function (Import $record, ShopifyCsvExporter $exporter) {

                $totalHandles = Product::where('import_id', $record->id)->count();

                $approvedHandles = Product::where('import_id', $record->id)
                    ->get()
                    ->filter(fn ($p) => $p->isApprovedByTwo())
                    ->count();

                if ($approvedHandles === 0) {
                    self::sendNotification(
                        Notification::make()
                            ->title('Nothing to export')
                            ->body('No products are approved for export yet (need 2 approvals each).')
                            ->warning()
                    );
                    return;
                }

                if ($approvedHandles < $totalHandles) {
                    $notApproved = $totalHandles - $approvedHandles;
                    self::sendNotification(
                        Notification::make()
                            ->title('Partial export')
                            ->body("Exporting {$approvedHandles} approved products. {$notApproved} are not approved yet.")
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
            'create' => Pages\CreateImport::route('/create'),
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
