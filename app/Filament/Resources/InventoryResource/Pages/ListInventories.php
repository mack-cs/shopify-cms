<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use App\Filament\Resources\InventoryAdjustmentRequestResource;
use App\Filament\Resources\InventoryResource\Widgets\InventoryRunBanner;
use App\Jobs\DailyShopifyInventoryRefreshJob;
use App\Models\InventoryAdjustmentRequest;
use App\Models\ProcurementIncomingStock;
use App\Models\ProcurementSupplierImportBatch;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierReceipt;
use App\Models\Variant;
use App\Services\AsyncJobStateService;
use App\Services\InventoryAccessService;
use App\Services\Procurement\ProcurementPriceUpdateService;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    protected function notifyPreviouslyCapturedOrder(ProcurementSupplierImportBatch $batch): bool
    {
        if ($batch->status !== 'completed') {
            return false;
        }

        $orders = collect($batch->preview_rows)->pluck('order_id')->filter()->unique()->implode(', ');
        Notification::make()
            ->title('Purchase order already captured')
            ->body("The accepted rows were already recorded for order(s): {$orders}. No new order lines were created. {$batch->invalid_count} rejected row(s) remain not saved; use Review Supplier Import to download and correct them.")
            ->warning()
            ->persistent()
            ->send();

        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                $this->supplierInputAction('previewSupplierOrdersCsv','Upload Supplier Orders','order',false),
                $this->supplierInputAction('previewSupplierReceiptsCsv','Upload Receipts','receipt',false),
            ])->label('Upload Files')->icon('heroicon-o-arrow-up-tray')->button()
                ->visible(fn():bool=>$this->activeTab==='orders' && app(InventoryAccessService::class)->canUpdateInventory(Auth::user())),
            $this->supplierInputAction('pasteSupplierOrder','Paste Purchase Order','order',true),
            $this->supplierInputAction('pasteSupplierReceipts','Paste Received Orders','receipt',true),
            Actions\Action::make('reviewSupplierImport')->label('Review Supplier Import')
                ->visible(fn():bool=>$this->activeTab==='orders' && $this->supplierImportBatchUuid!==null && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->modalWidth(MaxWidth::SevenExtraLarge)
                ->modalHeading(fn():string=>$this->supplierBatch()->type==='order'?'Review Purchase Order Rows':'Review Receipt Rows')
                ->modalContent(fn()=>view('filament.inventory.supplier-import-preview',['batch'=>$this->supplierBatch(),'warnings'=>app(SupplierOrderCsvService::class)->outOfSequenceWarnings($this->supplierBatch())]))
                ->modalSubmitAction(fn(Actions\StaticAction $action)=>$this->supplierBatch()->status==='completed'?false:$action)
                ->modalSubmitActionLabel(fn():string=>$this->supplierBatch()->type==='order'?'Save valid order rows':'Stage valid receipt rows')
                ->form(function():array {
                    $batch=$this->supplierBatch(); $warnings=app(SupplierOrderCsvService::class)->outOfSequenceWarnings($batch);
                    return [
                        \Filament\Forms\Components\Checkbox::make('accept_valid_rows')->label('I reviewed the rejected rows. Save only valid rows; leave rejected rows not saved.')
                            ->visible($batch->invalid_count>0)->required($batch->invalid_count>0)->accepted(),
                        \Filament\Forms\Components\Checkbox::make('confirm_out_of_sequence')->label('I confirm receiving against the later purchase order')
                            ->visible($warnings->isNotEmpty())->required($warnings->isNotEmpty())->accepted(),
                        Textarea::make('out_of_sequence_reason')->label('Reason for receiving the later order')->visible($warnings->isNotEmpty())->required($warnings->isNotEmpty()),
                    ];
                })
                ->action(function(array $data,SupplierOrderCsvService $csv,Actions\Action $action):void {
                    app(InventoryAccessService::class)->authorizeInventory(Auth::id());
                    try {
                        $batch=$csv->confirm($this->supplierBatch()->uuid,Auth::id(),dispatchReceipts:false,
                            outOfSequenceReason:(string)($data['out_of_sequence_reason']??''),acceptValidRows:(bool)($data['accept_valid_rows']??false));
                        Notification::make()->title($batch->type==='order'?'Purchase order created':'Received quantities staged')
                            ->body($batch->valid_count.' valid row(s) saved; '.$batch->invalid_count.' rejected row(s) not saved. '.($batch->type==='receipt'?'Waiting to be pushed: review and use Push Received To Shopify.':''))->success()->persistent()->send();
                    } catch(\Illuminate\Validation\ValidationException $e) {
                        Notification::make()->title('Review required before saving')->body($e->getMessage())->warning()->persistent()->send();
                        $action->halt();
                    }
                }),            Actions\Action::make('recalculateProcurement')
                ->label('Recalculate Procurement')
                ->icon('heroicon-o-calculator')
                ->color('danger')
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
            Actions\Action::make('applyPriceUpdates')
                ->label('Apply Price & Cost Updates')
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->visible(fn (): bool => $this->activeTab === 'orders'
                    && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                ->requiresConfirmation()
                ->modalHeading('Apply Price & Cost Updates')
                ->modalDescription('Populated New Price and New Cost values are queued for Shopify. Each field is processed independently. SKUs that are not an active catalog variant, have no Shopify ID, or have invalid values are skipped and left in the sheet. Successful fields update Current Price or Current Cost and clear only the confirmed input after Shopify accepts the change.')
                ->modalSubmitActionLabel('Apply Price & Cost Updates')
                ->action(function (ProcurementPriceUpdateService $prices): void {
                    try {
                        $result = $prices->queueFromSheet(Auth::id());
                        $unready = $result['unready_skus'] ?? [];
                        $body = "Queued {$result['queued']} price/cost update row(s). Skipped {$result['skipped_unchanged']} unchanged row(s).";
                        if (($result['skipped_unready'] ?? 0) > 0) {
                            $preview = implode(', ', array_slice($unready, 0, 8));
                            $body .= " Skipped {$result['skipped_unready']} SKU(s) that cannot be sent to Shopify";
                            $body .= $preview !== '' ? ": {$preview}." : '.';
                            $body .= ' Valid fields were still queued. Unready New Price/New Cost cells were left as-is.';
                        }
                        Notification::make()
                            ->title($result['queued'] > 0 ? 'Price and cost updates queued' : 'No price or cost updates to queue')
                            ->body($body)
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Price and cost updates were not queued')->body($e->getMessage())->danger()->persistent()->send();
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
            Actions\Action::make('reviewMyInventoryApprovals')
                ->label(fn (): string => 'Review My Approvals'.($this->pendingInventoryApprovalsCount() > 0 ? ' ('.$this->pendingInventoryApprovalsCount().')' : ''))
                ->icon('heroicon-o-clipboard-document-check')
                ->color('warning')
                ->visible(fn (): bool => $this->activeTab === 'inventory_adjustments'
                    && $this->pendingInventoryApprovalsCount() > 0)
                ->url(fn (): string => InventoryAdjustmentRequestResource::getUrl()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            InventoryRunBanner::class,
        ];
    }

    protected function getTableQuery(): ?Builder
    {
        return match ($this->activeTab) {
            'supplier_reports' => ProcurementSupplierOrder::query()
                ->with(['createdBy', 'amendments.amendedBy', 'lines.variant.product', 'lines.draft', 'lines.receipts', 'receipts'])
                ->withCount('lines'),
            'grv_reports' => ProcurementSupplierReceipt::query()
                ->with(['line.order', 'line.variant.product', 'createdBy'])
                ->whereNotNull('grv_number')
                ->whereIn('id', ProcurementSupplierReceipt::query()
                    ->selectRaw('MIN(id)')
                    ->whereNotNull('grv_number')
                    ->groupBy('grv_number')),
            default => parent::getTableQuery(),
        };
    }

    public ?string $supplierImportBatchUuid = null;

    private function supplierBatch(): ProcurementSupplierImportBatch
    {
        return ProcurementSupplierImportBatch::where('uuid',$this->supplierImportBatchUuid)->firstOrFail();
    }

    private function supplierInputAction(string $name,string $label,string $type,bool $paste): Actions\Action
    {
        return Actions\Action::make($name)->label($label)->icon($paste?'heroicon-o-clipboard-document-list':'heroicon-o-arrow-up-tray')
            ->visible(fn():bool=>$this->activeTab==='orders' && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
            ->modalWidth($paste?MaxWidth::FiveExtraLarge:MaxWidth::Medium)
            ->modalSubmitActionLabel('Validate & Review')
            ->modalDescription($type==='order'?'Review purchase order rows before saving. Existing quantities are never overwritten.':'Review receipt rows before staging. Staging does not update Shopify inventory.')
            ->form($paste?[Textarea::make('pasted_rows')->label('Spreadsheet rows')->rows(12)->required()
                ->helperText($type==='order'?"Item (optional)\tSKU\tQuantity Ordered\tOrder ID\tETA Date":"Order ID\tSKU\tQuantity Received")]
                :[FileUpload::make('file')->label('CSV file')->disk('local')->directory('supplier-imports')->acceptedFileTypes(['text/csv','text/plain','application/vnd.ms-excel'])->required()])
            ->action(function(array $data,SupplierOrderCsvService $csv) use($type,$paste):void {
                app(InventoryAccessService::class)->authorizeInventory(Auth::id());
                try {
                    $batch=$paste?($type==='order'?$csv->previewPastedOrder((string)$data['pasted_rows'],Auth::id()):$csv->previewPastedReceipt((string)$data['pasted_rows'],Auth::id()))
                        :$csv->preview(Storage::disk('local')->path((string)$data['file']),$type,Auth::id(),basename((string)$data['file']));
                    $this->supplierImportBatchUuid=$batch->uuid;
                    if($type==='order' && $this->notifyPreviouslyCapturedOrder($batch)) return;
                    $this->replaceMountedAction('reviewSupplierImport');
                } catch(\Illuminate\Validation\ValidationException $e) {
                    Notification::make()->title('Rows could not be reviewed')->body($e->getMessage())->warning()->persistent()->send();
                }
            });
    }
    private function outOfSequenceWarningBody(\Illuminate\Support\Collection $warnings): string
    {
        $lines = $warnings->take(10)->map(function (array $warning): string {
            $earlierEta = filled($warning['earlier_eta'] ?? null)
                ? date('d F Y', strtotime((string) $warning['earlier_eta']))
                : 'an earlier date';
            $receivingEta = filled($warning['receiving_eta'] ?? null)
                ? date('d F Y', strtotime((string) $warning['receiving_eta']))
                : 'no ETA';

            return "SKU {$warning['sku']}: earlier order {$warning['earlier_order_number']} has ETA {$earlierEta}; receiving {$warning['receiving_order_number']} with ETA {$receivingEta}.";
        })->implode("\n");

        return "There are earlier pending supplier orders for one or more SKUs. Confirm that the receipt is correct and provide a reason before continuing.\n{$lines}";
    }

    private function pendingInventoryApprovalsCount(): int
    {
        $userId = Auth::id();

        if ($userId === null) {
            return 0;
        }

        return InventoryAdjustmentRequest::query()
            ->where('status', InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL)
            ->where('approver_id', $userId)
            ->count();
    }

    public function getTabs(): array
    {
        return [
            'everyday' => Tab::make('Everyday Inventory')
                ->icon('heroicon-o-archive-box')
                ->badge((string) Variant::query()->inventoryWorkspaceEligible()->count()),
            'orders' => Tab::make('Supplier Orders')
                ->icon('heroicon-o-truck')
                ->modifyQueryUsing(fn ($query) => $query
                    ->whereHas('product', fn ($product) => $product->nonBundle()))
                ->badge((string) Variant::query()
                    ->inventoryWorkspaceEligible()
                    ->whereHas('product', fn ($product) => $product->nonBundle())
                    ->whereHas('procurementIncomingStock', fn ($query) => $query
                        ->where('total_quantity_on_order', '>', 0))
                    ->count())
                ->badgeColor('info'),
            'supplier_reports' => Tab::make('Supplier Reporting')
                ->icon('heroicon-o-document-chart-bar')
                ->badge((string) ProcurementSupplierOrder::query()->count())
                ->badgeColor('gray'),
            'grv_reports' => Tab::make('GRV Receipts')
                ->icon('heroicon-o-receipt-percent')
                ->badge((string) ProcurementSupplierReceipt::query()
                    ->whereNotNull('grv_number')
                    ->distinct('grv_number')
                    ->count('grv_number'))
                ->badgeColor('success'),
            'inventory_adjustments' => Tab::make('Inventory Adjustments')
                ->icon('heroicon-o-clipboard-document-check')
                ->modifyQueryUsing(fn ($query) => $query->whereHas('inventoryAdjustmentRequestItems'))
                ->badge((string) Variant::query()
                    ->inventoryWorkspaceEligible()
                    ->whereHas('inventoryAdjustmentRequestItems')
                    ->count())
                ->badgeColor('warning'),
        ];
    }
}
