<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use App\Filament\Resources\InventoryResource\Widgets\InventoryRunBanner;
use App\Jobs\DailyShopifyInventoryRefreshJob;
use App\Models\ProcurementIncomingStock;
use App\Models\ProcurementSupplierImportBatch;
use App\Models\Variant;
use App\Services\AsyncJobStateService;
use App\Services\InventoryAccessService;
use App\Services\Procurement\SupplierOrderCsvService;
use App\Services\ProcurementPipelineService;
use App\Services\ProductInventoryCsvImporter;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
            Actions\Action::make('previewSupplierOrdersCsv')
                ->label('Upload Supplier Orders')->icon('heroicon-o-truck')->color('info')
                ->modalWidth(MaxWidth::Medium)
                ->visible(fn (): bool => $this->activeTab === 'orders'
                    && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->modalDescription('Upload the selected-order CSV after filling in Order ID, Quantity Ordered, and ETA. Valid rows are saved immediately; invalid rows are rejected.')
                ->form([FileUpload::make('file')->label('Supplier orders CSV')->required()->disk('local')->directory('imports/procurement')->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])])
                ->action(function (array $data, SupplierOrderCsvService $csv): void {
                    try {
                        $path = Storage::disk('local')->path((string) $data['file']);
                        $batch = $csv->preview($path, 'order', Auth::id(), basename((string) $data['file']));
                        if ($batch->invalid_count > 0) {
                            Notification::make()->title('Order upload needs corrections')->body($this->supplierPreviewBody($batch))->warning()->persistent()->send();

                            return;
                        }
                        $csv->confirm($batch->uuid, Auth::id());
                        Notification::make()->title('Supplier orders uploaded')
                            ->body("{$batch->valid_count} row(s) saved. Use Review Upload → Latest Orders Upload to check them.")
                            ->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Preview failed')->body($e->getMessage())->danger()->send();
                    }
                }),
            Actions\Action::make('previewSupplierReceiptsCsv')
                ->label('Upload Receipts')->icon('heroicon-o-inbox-arrow-down')->color('success')
                ->modalWidth(MaxWidth::Medium)
                ->visible(fn (): bool => $this->activeTab === 'orders'
                    && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->modalDescription('Upload received quantities. Valid rows are staged for review and will not change Shopify until selected and pushed.')
                ->form([FileUpload::make('file')->label('Supplier receipts CSV')->required()->disk('local')->directory('imports/procurement')->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])])
                ->action(function (array $data, SupplierOrderCsvService $csv): void {
                    try {
                        $path = Storage::disk('local')->path((string) $data['file']);
                        $batch = $csv->preview($path, 'receipt', Auth::id(), basename((string) $data['file']));
                        if ($batch->invalid_count > 0) {
                            Notification::make()->title('Received-order upload needs corrections')->body($this->supplierPreviewBody($batch))->warning()->persistent()->send();

                            return;
                        }
                        $csv->confirm($batch->uuid, Auth::id(), dispatchReceipts: false);
                        Notification::make()->title('Received quantities staged')
                            ->body("{$batch->valid_count} row(s) are awaiting review. Filter by Awaiting Shopify Push, select approved rows, then use Push Received To Shopify.")
                            ->success()->persistent()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Preview failed')->body($e->getMessage())->danger()->send();
                    }
                }),
            ])->label('Upload Files')->icon('heroicon-o-arrow-up-tray')->button()
                ->visible(fn (): bool => $this->activeTab === 'orders'
                    && app(InventoryAccessService::class)->canUpdateInventory(Auth::user())),
            Actions\Action::make('pasteSupplierOrder')
                ->label('Paste Purchase Order')->icon('heroicon-o-clipboard-document-list')->color('warning')
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->modalSubmitActionLabel('Validate & Create Orders')
                ->visible(fn (): bool => $this->activeTab === 'orders'
                    && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->modalDescription('Paste tab-separated rows copied from Google Sheets or Excel using: Item (optional), SKU, Quantity Ordered, Order ID and ETA Date. SKU identifies the existing CMS product; product name and vendor are never changed. Item is only a row reference and is not saved.')
                ->form([
                    Textarea::make('pasted_rows')
                        ->label('Purchase-order rows')
                        ->helperText("Item\tSKU\tQuantity Ordered\tOrder ID\tETA Date")
                        ->rows(12)
                        ->required(),
                ])
                ->action(function (array $data, SupplierOrderCsvService $csv): void {
                    try {
                        $batch = $csv->previewPastedOrder((string) $data['pasted_rows'], Auth::id());

                        if ($batch->invalid_count > 0) {
                            Notification::make()
                                ->title('Purchase order needs corrections')
                                ->body($this->supplierPreviewBody($batch))
                                ->warning()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $csv->confirm($batch->uuid, Auth::id());
                        Notification::make()
                            ->title('Purchase order created')
                            ->body("{$batch->valid_count} order line(s) validated and saved successfully.")
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Purchase order was not created')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Actions\Action::make('pasteSupplierReceipts')
                ->label('Paste Received Orders')->icon('heroicon-o-clipboard-document-check')->color('success')
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->modalSubmitActionLabel('Validate & Stage Receipts')
                ->visible(fn (): bool => $this->activeTab === 'orders'
                    && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->modalDescription('Paste tab-separated rows copied from Google Sheets or Excel using this receipt shape: Order ID, SKU, Quantity Received. The rows are validated first and will not update Shopify until you review and push them.')
                ->form([
                    Textarea::make('pasted_rows')
                        ->label('Received-order rows')
                        ->helperText("Order ID\tSKU\tQuantity Received")
                        ->rows(12)
                        ->required(),
                ])
                ->action(function (array $data, SupplierOrderCsvService $csv): void {
                    try {
                        $batch = $csv->previewPastedReceipt((string) $data['pasted_rows'], Auth::id());

                        if ($batch->invalid_count > 0) {
                            Notification::make()
                                ->title('Received orders need corrections')
                                ->body($this->supplierPreviewBody($batch))
                                ->warning()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $csv->confirm($batch->uuid, Auth::id(), dispatchReceipts: false);
                        Notification::make()
                            ->title('Received quantities staged')
                            ->body("{$batch->valid_count} row(s) are awaiting review. Filter by Awaiting Shopify Push, select approved rows, then use Push Received To Shopify.")
                            ->success()
                            ->persistent()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Received quantities were not staged')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Actions\Action::make('recalculateProcurement')
                ->label('Recalculate Procurement')
                ->icon('heroicon-o-calculator')
                ->color('primary')
                ->visible(fn (): bool => $this->activeTab === 'orders'
                    && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->requiresConfirmation()
                ->modalHeading('Run procurement recalculation?')
                ->modalDescription('This queues the full procurement pipeline using the latest CMS supplier orders and inventory. Results and Google Sheets update when the background run completes.')
                ->modalSubmitActionLabel('Queue Recalculation')
                ->action(function (ProcurementPipelineService $pipeline): void {
                    try {
                        $timezone = (string) config('procurement.timezone', 'Africa/Johannesburg');
                        $result = $pipeline->queue(now($timezone)->toDateString(), Auth::id(), forceRecalculation: true);
                        $run = $result['run'];

                        Notification::make()
                            ->title($result['queued'] ? 'Procurement recalculation queued' : 'Procurement recalculation already running')
                            ->body($result['queued']
                                ? "Run #{$run->id} will update predictions and Google Sheets when complete."
                                : "Run #{$run->id} currently has status {$run->status}.")
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Recalculation was not queued')->body($e->getMessage())->danger()->send();
                    }
                }),
            Actions\Action::make('checkShopifyInventory')
                ->label('Check Shopify Inventory')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->activeTab === 'everyday'
                    && app(InventoryAccessService::class)->canAccess(Auth::user()))
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
            Actions\Action::make('importStockCsv')
                ->label('Import Stock CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('info')
                ->modalWidth(MaxWidth::Medium)
                ->visible(fn (): bool => $this->activeTab === 'everyday'
                    && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->modalHeading('Import Stock CSV')
                ->modalDescription('This stages physical On Hand counts from a CSV and records inventory history. It does not push changes to Shopify.')
                ->form([
                    FileUpload::make('file')
                        ->label('CSV File')
                        ->required()
                        ->disk('local')
                        ->directory('imports/inventory')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->helperText('Accepted match columns: product_id, shopify_product_id, handle, sku, variant_id, or shopify_variant_id. The physical count column can be on_hand, inventory_qty, stock, quantity, or qty. Multi-variant products need SKU or variant ID.'),
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
                        $body .= "\n".implode("\n", $result['warnings']);
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
            ? ($batch->type === 'order'
                ? 'Correct the invalid rows and try again. No orders were created.'
                : 'Correct the invalid rows and try again. No receipts were staged.')
            : 'All rows are ready.';

        return "{$batch->valid_count} valid, {$batch->invalid_count} invalid.\n{$lines}\n{$next}";
    }

    public function getTabs(): array
    {
        return [
            'everyday' => Tab::make('Everyday Inventory')
                ->icon('heroicon-o-archive-box')
                ->badge((string) Variant::query()->inventoryWorkspaceEligible()->count()),
            'orders' => Tab::make('Supplier Orders')
                ->icon('heroicon-o-truck')
                ->badge((string) Variant::query()
                    ->inventoryWorkspaceEligible()
                    ->whereHas('procurementIncomingStock', fn ($query) => $query
                        ->where('total_quantity_on_order', '>', 0))
                    ->count())
                ->badgeColor('info'),
        ];
    }
}
