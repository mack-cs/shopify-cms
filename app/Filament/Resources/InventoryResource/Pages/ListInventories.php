<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use App\Filament\Resources\InventoryResource\Widgets\InventoryRunBanner;
use App\Jobs\DailyShopifyInventoryRefreshJob;
use App\Models\Variant;
use App\Models\ProcurementSupplierImportBatch;
use App\Services\AsyncJobStateService;
use App\Services\InventoryAccessService;
use App\Services\ProductInventoryCsvExporter;
use App\Services\ProductInventoryCsvImporter;
use App\Services\Procurement\SupplierOrderCsvService;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('previewSupplierOrdersCsv')
                ->label('Upload Supplier Orders')->icon('heroicon-o-truck')->color('info')
                ->visible(fn (): bool => app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->modalDescription('CSV headers: SKU, Order ID, Quantity Ordered, ETA. This step validates and stores a preview only; it does not create orders.')
                ->form([FileUpload::make('file')->label('Supplier orders CSV')->required()->disk('local')->directory('imports/procurement')->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])])
                ->action(function (array $data, SupplierOrderCsvService $csv): void {
                    try {
                        $path = Storage::disk('local')->path((string) $data['file']);
                        $batch = $csv->preview($path, 'order', Auth::id(), basename((string) $data['file']));
                        $body = $this->supplierPreviewBody($batch);
                        Notification::make()->title('Supplier order preview ready')->body($body)
                            ->when($batch->invalid_count > 0, fn ($n) => $n->warning(), fn ($n) => $n->success())->persistent()->send();
                    } catch (Throwable $e) { Notification::make()->title('Preview failed')->body($e->getMessage())->danger()->send(); }
                }),
            Actions\Action::make('previewSupplierReceiptsCsv')
                ->label('Upload Receipts')->icon('heroicon-o-inbox-arrow-down')->color('success')
                ->visible(fn (): bool => app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->modalDescription('CSV headers: Order ID, SKU, Quantity Received. This step validates and stores a preview only; Shopify is not updated until confirmation.')
                ->form([FileUpload::make('file')->label('Supplier receipts CSV')->required()->disk('local')->directory('imports/procurement')->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])])
                ->action(function (array $data, SupplierOrderCsvService $csv): void {
                    try {
                        $path = Storage::disk('local')->path((string) $data['file']);
                        $batch = $csv->preview($path, 'receipt', Auth::id(), basename((string) $data['file']));
                        $body = $this->supplierPreviewBody($batch);
                        Notification::make()->title('Receipt preview ready')->body($body)
                            ->when($batch->invalid_count > 0, fn ($n) => $n->warning(), fn ($n) => $n->success())->persistent()->send();
                    } catch (Throwable $e) { Notification::make()->title('Preview failed')->body($e->getMessage())->danger()->send(); }
                }),
            Actions\Action::make('confirmSupplierImport')
                ->label('Confirm Supplier Import')->icon('heroicon-o-check-circle')->color('warning')
                ->visible(fn (): bool => app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->requiresConfirmation()->modalDescription('Orders will be committed immediately. Receipt rows will queue delta adjustments in Shopify. A completed preview cannot be processed twice.')
                ->form([\Filament\Forms\Components\TextInput::make('batch_uuid')->label('Preview ID')->required()->uuid()])
                ->action(function (array $data, SupplierOrderCsvService $csv): void {
                    try {
                        $batch = $csv->confirm((string) $data['batch_uuid'], Auth::id());
                        Notification::make()->title('Supplier import confirmed')->body($batch->type === 'receipt' ? 'Receipt adjustments were queued on the procurement queue.' : 'Orders and procurement Sheets were updated.')->success()->send();
                    } catch (Throwable $e) { Notification::make()->title('Confirmation failed')->body($e->getMessage())->danger()->send(); }
                }),
            Actions\Action::make('checkShopifyInventory')
                ->label('Check Shopify Inventory')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => app(InventoryAccessService::class)->canAccess(Auth::user()))
                ->requiresConfirmation()
                ->modalHeading('Check Shopify Inventory')
                ->modalDescription('This will read the latest product status and variant inventory from Shopify into the local inventory records without pushing local changes to Shopify.')
                ->modalSubmitActionLabel('Queue Check')
                ->action(function (): void {
                    app(AsyncJobStateService::class)->markQueued(AsyncJobStateService::INVENTORY_CHECK);
                    DailyShopifyInventoryRefreshJob::dispatch(Auth::id());

                    Notification::make()
                        ->title('Shopify inventory check queued')
                        ->body('The read-only Shopify inventory refresh is running in the background.')
                            ->success()
                            ->send();
                }),
            Actions\Action::make('exportStockCsv')
                ->label('Export Stock CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->action(function (ProductInventoryCsvExporter $exporter): void {
                    $timestamp = now()->format('Ymd_His');
                    $path = "exports/inventory_stock_{$timestamp}.csv";

                    Storage::disk('public')->put($path, $exporter->exportToString());
                    $url = Storage::disk('public')->url($path);

                    Notification::make()
                        ->title('Stock export ready')
                        ->body("Saved to public/{$path}. Edit the stock column, then import the CSV back here.")
                        ->success()
                        ->actions([
                            NotificationAction::make('download')
                                ->label('Download CSV')
                                ->url($url, shouldOpenInNewTab: true),
                        ])
                        ->send();
                }),
            Actions\Action::make('importStockCsv')
                ->label('Import Stock CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('info')
                ->visible(fn (): bool => app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->modalHeading('Import Stock CSV')
                ->modalDescription('This updates local inventory from a CSV and records inventory history. It does not push changes to Shopify.')
                ->form([
                    FileUpload::make('file')
                        ->label('CSV File')
                        ->required()
                        ->disk('local')
                        ->directory('imports/inventory')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->helperText('Accepted match columns: product_id, shopify_product_id, handle, sku, variant_id, or shopify_variant_id. Stock column can be inventory_qty, stock, quantity, or qty. Multi-variant products need SKU or variant ID.'),
                ])
                ->action(function (array $data, ProductInventoryCsvImporter $importer): void {
                    $file = is_string($data['file'] ?? null) ? $data['file'] : null;
                    if ($file === null) {
                        Notification::make()
                            ->title('No file selected')
                            ->danger()
                            ->send();
                        return;
                    }

                    try {
                        $result = $importer->importFromPath(Storage::disk('local')->path($file), Auth::id());
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Stock import failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        return;
                    }

                    $skipped = $result['skipped_missing_identifier']
                        + $result['skipped_missing_quantity']
                        + $result['skipped_invalid_quantity']
                        + $result['skipped_invalid_tracked']
                        + $result['skipped_not_found']
                        + $result['skipped_ambiguous'];

                    $body = "Rows: {$result['total']}, Updated: {$result['updated']}, Unchanged: {$result['unchanged']}, Snapshots: {$result['snapshots']}, Skipped: {$skipped}.";
                    if (($result['warnings'] ?? []) !== []) {
                        $body .= "\n" . implode("\n", $result['warnings']);
                    }

                    $notification = Notification::make()
                        ->title('Stock import complete')
                        ->body($body);

                    $skipped > 0 ? $notification->warning() : $notification->success();
                    $notification->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            InventoryRunBanner::class,
        ];
    }

    private function supplierPreviewBody(ProcurementSupplierImportBatch $batch): string
    {
        $lines = collect($batch->preview_rows)->take(10)->map(function (array $row) use ($batch): string {
            $quantity = $row[$batch->type === 'order' ? 'quantity_ordered' : 'quantity_received'] ?? '';
            $eta = $batch->type === 'order' ? ' · ETA '.($row['eta'] ?? '') : '';
            $errors = (array) data_get($batch->errors, (string) ($row['_row'] ?? ''), []);
            $result = $errors === [] ? 'VALID' : 'INVALID: '.implode('; ', $errors);
            return "Row {$row['_row']}: ".($row['sku'] ?? '').' · '.($row['order_id'] ?? '')." · Qty {$quantity}{$eta} · {$result}";
        })->implode("\n");
        $next = $batch->invalid_count > 0
            ? 'Correct the CSV and upload the corrected file.'
            : 'Use Confirm Supplier Import and paste the preview ID below.';
        return "Preview ID: {$batch->uuid}\n{$batch->valid_count} valid, {$batch->invalid_count} invalid.\n{$lines}\n{$next}";
    }

    public function getTabs(): array
    {
        $counts = $this->tabCounts();

        return [
            'all' => Tab::make('All')
                ->badge((string) $counts['all']),
            'in_stock' => Tab::make('In Stock')
                ->badge((string) $counts['in_stock'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->whereRaw('LOWER(status) = ?', ['active']))
                    ->where('inventory_tracked', true)
                    ->where('inventory_qty', '>', 0)),
            'out_of_stock' => Tab::make('Out Of Stock')
                ->badge((string) $counts['out_of_stock'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('inventory_tracked', true)
                    ->where('inventory_qty', '<=', 0)),
            'not_tracked' => Tab::make('Not Tracked')
                ->badge((string) $counts['not_tracked'])
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(function (Builder $builder): void {
                    $builder->where('inventory_tracked', false)
                        ->orWhereNull('inventory_tracked');
                })),
        ];
    }

    /**
     * @return array{all:int,in_stock:int,out_of_stock:int,not_tracked:int}
     */
    private function tabCounts(): array
    {
        $baseQuery = Variant::query()->whereHas('product');

        return [
            'all' => (clone $baseQuery)->count(),
            'in_stock' => (clone $baseQuery)
                ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->whereRaw('LOWER(status) = ?', ['active']))
                ->where('inventory_tracked', true)
                ->where('inventory_qty', '>', 0)
                ->count(),
            'out_of_stock' => (clone $baseQuery)
                ->where('inventory_tracked', true)
                ->where('inventory_qty', '<=', 0)
                ->count(),
            'not_tracked' => (clone $baseQuery)
                ->where(function (Builder $builder): void {
                    $builder->where('inventory_tracked', false)
                        ->orWhereNull('inventory_tracked');
                })
                ->count(),
        ];
    }
}
