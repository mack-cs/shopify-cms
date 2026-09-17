<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryResource\Pages;
use App\Jobs\InventorySyncJob;
use App\Jobs\ProcessSupplierReceiptJob;
use App\Models\ProcurementSupplierImportBatch;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProcurementSupplierReceipt;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryAdjustmentRequestItem;
use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\Variant;
use App\Services\BulkInventoryTrackingService;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\InventoryAccessService;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\InventoryOperationContext;
use App\Services\Procurement\ProcurementSelectionCsvExporter;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierReceiptService;
use App\Services\ProductInventoryCsvExporter;
use App\Services\ProductInventoryHistoryRecorder;
use App\Services\ProductSellabilityService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;

class InventoryResource extends Resource
{
    protected static ?string $model = Variant::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->getModel() instanceof Variant
                ? $query
                    ->inventoryWorkspaceEligible()
                    ->with([
                        'product',
                        'procurementIncomingStock',
                        'supplierOrderLines.order',
                        'supplierOrderLines.receipts',
                        'inventoryAdjustmentRequestItems.request.requester',
                        'inventoryAdjustmentRequestItems.request.approver',
                        'inventoryAdjustmentRequestItems.request.reviewedBy',
                    ])
                : $query)
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('product.id')
                    ->label('Product ID')
                    ->sortable()
                    ->searchable()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('product.title')
                    ->label('Title')
                    ->searchable()
                    ->wrap()
                    ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders', 'inventory_adjustments'], true)),
                TextColumn::make('product.handle')
                    ->label('Handle')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('product.status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match (strtolower(trim((string) $state))) {
                        'active' => 'success',
                        'draft' => 'warning',
                        'archived' => 'gray',
                        default => 'gray',
                    })
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders', 'inventory_adjustments'], true)),
                IconColumn::make('inventory_tracked')
                    ->label('Tracked')
                    ->icon(fn (Variant $record): string => match ($record->inventory_tracked) {
                        true => 'heroicon-m-check-circle',
                        false => 'heroicon-m-minus-circle',
                        default => 'heroicon-m-question-mark-circle',
                    })
                    ->color(fn (Variant $record): string => match ($record->inventory_tracked) {
                        true => 'success',
                        false => 'gray',
                        default => 'warning',
                    })
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('inventory_qty')
                    ->label('Available')
                    ->state(fn (Variant $record): string => match ($record->inventory_tracked) {
                        false => 'Not tracked',
                        null => 'Unknown',
                        default => ($record->current_available_quantity ?? $record->inventory_qty) !== null
                            ? (string) ((int) ($record->current_available_quantity ?? $record->inventory_qty))
                            : 'Unknown',
                    })
                    ->sortable()
                    ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders'], true)),
                TextColumn::make('current_committed_quantity')
                    ->label('Committed')
                    ->state(fn (Variant $record): string => $record->inventory_tracked === false
                        ? 'Not tracked'
                        : ($record->current_committed_quantity !== null
                            ? (string) ((int) $record->current_committed_quantity)
                            : 'Unknown'))
                    ->sortable()
                    ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders'], true)),
                TextColumn::make('current_reserved_quantity')
                    ->label('Reserved')
                    ->state(fn (Variant $record): string => $record->inventory_tracked === false
                        ? 'Not tracked'
                        : ($record->current_reserved_quantity !== null
                            ? (string) ((int) $record->current_reserved_quantity)
                            : 'Unknown'))
                    ->sortable()
                    ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders'], true)),
                TextColumn::make('current_on_hand_quantity')
                    ->label('On Hand')
                    ->state(fn (Variant $record): string => $record->inventory_tracked === false
                        ? 'Not tracked'
                        : ($record->current_on_hand_quantity !== null
                            ? (string) ((int) $record->current_on_hand_quantity)
                            : 'Unknown'))
                    ->sortable()
                    ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders'], true)),
                TextColumn::make('quantity_on_order')
                    ->label('Qty On Order')
                    ->state(fn (Variant $record): int => (int) ($record->procurementIncomingStock?->total_quantity_on_order ?? 0))
                    ->badge()->color('info')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders'),
                TextColumn::make('wip_orders')
                    ->label('WIP Orders')
                    ->state(fn (Variant $record): int => (int) ($record->procurementIncomingStock?->number_of_wip_orders ?? 0))
                    ->badge()->color('warning')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders'),
                TextColumn::make('next_eta')
                    ->label('Next ETA')
                    ->state(function (Variant $record): ?string {
                        return $record->supplierOrderLines
                            ->filter(fn (ProcurementSupplierOrderLine $line): bool => $line->status === 'open' && $line->quantity_outstanding > 0)
                            ->pluck('eta_date')->filter()->sort()->first()?->format('d/m/Y');
                    })
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders'),
                TextColumn::make('supplier_report_order_number')
                    ->label('Order ID')
                    ->state(fn (Model $record): string => $record instanceof ProcurementSupplierOrder
                        ? ($record->order_number ?: 'Legacy order')
                        : '-')
                    ->wrap()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports'),
                TextColumn::make('supplier_report_created_by')
                    ->label('Created By')
                    ->state(fn (Model $record): string => $record instanceof ProcurementSupplierOrder
                        ? ($record->createdBy?->name ?: $record->createdBy?->email ?: '-')
                        : '-')
                    ->wrap()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports'),
                TextColumn::make('supplier_report_status')
                    ->label('Order Status')
                    ->state(function (Model $record): string {
                        if (! ($record instanceof ProcurementSupplierOrder)) {
                            return '-';
                        }
                        $lines = $record->lines;
                        if ($lines->isEmpty()) {
                            return '-';
                        }

                        return $lines->contains(fn (ProcurementSupplierOrderLine $line): bool => $line->quantity_outstanding > 0 && $line->quantity_received > 0)
                            ? 'Partially received'
                            : ($lines->contains(fn (ProcurementSupplierOrderLine $line): bool => $line->quantity_outstanding > 0)
                                ? 'Open'
                                : 'Completed');
                    })
                    ->badge()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports'),
                TextColumn::make('supplier_report_ordered')
                    ->label('Ordered')
                    ->state(fn (Model $record): int => $record instanceof ProcurementSupplierOrder ? $record->lines->sum('quantity_ordered') : 0)
                    ->numeric()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports'),
                TextColumn::make('supplier_report_received')
                    ->label('Received')
                    ->state(fn (Model $record): int => $record instanceof ProcurementSupplierOrder ? $record->lines->sum(fn (ProcurementSupplierOrderLine $line): int => $line->quantity_received) : 0)
                    ->numeric()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports'),
                TextColumn::make('supplier_report_outstanding')
                    ->label('Outstanding')
                    ->state(fn (Model $record): int => $record instanceof ProcurementSupplierOrder ? $record->lines->sum(fn (ProcurementSupplierOrderLine $line): int => $line->quantity_outstanding) : 0)
                    ->numeric()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports'),
                TextColumn::make('supplier_report_order_date')
                    ->label('Order Date')
                    ->state(fn (Model $record): ?string => $record instanceof ProcurementSupplierOrder ? $record->created_at?->format('d/m/Y H:i') : null)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports'),
                TextColumn::make('supplier_report_grvs')
                    ->label('GRVs')
                    ->state(fn (Model $record): string => $record instanceof ProcurementSupplierOrder
                        ? ($record->receipts->pluck('grv_number')->filter()->unique()->implode(', ') ?: '-')
                        : '-')
                    ->copyable()
                    ->placeholder('-')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports'),
                TextColumn::make('grv_report_number')
                    ->label('GRV Number')
                    ->state(fn (Model $record): string => $record instanceof ProcurementSupplierReceipt ? ($record->grv_number ?: 'GRV pending') : '-')
                    ->copyable()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'grv_reports'),
                TextColumn::make('grv_report_order')
                    ->label('Supplier Order ID')
                    ->state(fn (Model $record): ?string => $record instanceof ProcurementSupplierReceipt ? $record->line?->order?->order_number : null)
                    ->placeholder('-')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'grv_reports'),
                TextColumn::make('grv_report_received_at')
                    ->label('Receipt Date')
                    ->state(fn (Model $record): ?string => $record instanceof ProcurementSupplierReceipt ? ($record->received_at ?? $record->created_at)?->format('d/m/Y H:i') : null)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'grv_reports'),
                TextColumn::make('grv_report_received_by')
                    ->label('Received By')
                    ->state(fn (Model $record): ?string => $record instanceof ProcurementSupplierReceipt ? $record->createdBy?->name : null)
                    ->placeholder('-')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'grv_reports'),
                TextColumn::make('grv_report_sku_preview')
                    ->label('SKU Preview')
                    ->state(function (Model $record): string {
                        if (! ($record instanceof ProcurementSupplierReceipt) || blank($record->grv_number)) {
                            return '-';
                        }

                        return ProcurementSupplierReceipt::query()
                            ->with('line')
                            ->where('grv_number', $record->grv_number)
                            ->get()
                            ->map(fn (ProcurementSupplierReceipt $receipt): string => ($receipt->line?->sku ?? '-') . ' x ' . $receipt->quantity_received)
                            ->pipe(function (Collection $items): string {
                                $preview = $items->take(2)->implode(', ');
                                $remaining = $items->count() - 2;

                                return $remaining > 0 ? "{$preview} +{$remaining} more" : ($preview ?: '-');
                            });
                    })
                    ->wrap()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'grv_reports'),
                TextColumn::make('adjustment_status')
                    ->label('Adjustment Status')
                    ->state(fn (Variant $record): string => self::latestInventoryAdjustmentItem($record)?->request?->status ?? '-')
                    ->badge()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'inventory_adjustments'),
                TextColumn::make('adjustment_requested_change')
                    ->label('Requested Change')
                    ->state(function (Variant $record): string {
                        $item = self::latestInventoryAdjustmentItem($record);
                        if (! $item instanceof InventoryAdjustmentRequestItem) {
                            return '-';
                        }

                        $from = $item->original_inventory_tracked ? ($item->original_on_hand_quantity ?? 'Unknown') : 'Not tracked';
                        $to = $item->requested_inventory_tracked ? ($item->requested_on_hand_quantity ?? 'Unknown') : 'Not tracked';

                        return "{$from} -> {$to}";
                    })
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'inventory_adjustments'),
                TextColumn::make('adjustment_reason')
                    ->label('Reason')
                    ->state(fn (Variant $record): ?string => self::latestInventoryAdjustmentItem($record)?->reason)
                    ->wrap()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'inventory_adjustments'),
                TextColumn::make('adjustment_requester')
                    ->label('Requested By')
                    ->state(fn (Variant $record): ?string => self::latestInventoryAdjustmentItem($record)?->request?->requester?->name)
                    ->placeholder('-')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'inventory_adjustments'),
                TextColumn::make('adjustment_approver')
                    ->label('Approver')
                    ->state(fn (Variant $record): ?string => self::latestInventoryAdjustmentItem($record)?->request?->approver?->name)
                    ->placeholder('-')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'inventory_adjustments'),
                TextColumn::make('adjustment_reviewed_by')
                    ->label('Reviewed By')
                    ->state(fn (Variant $record): ?string => self::latestInventoryAdjustmentItem($record)?->request?->reviewedBy?->name)
                    ->placeholder('-')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'inventory_adjustments'),
                TextColumn::make('adjustment_submitted_at')
                    ->label('Submitted')
                    ->state(fn (Variant $record): ?string => self::latestInventoryAdjustmentItem($record)?->request?->submitted_at?->format('d/m/Y H:i'))
                    ->placeholder('-')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'inventory_adjustments'),
                TextColumn::make('sellable_state')
                    ->label('Sellable')
                    ->state(function (Variant $record): string {
                        $product = $record->product;
                        if (! $product instanceof Product) {
                            return 'Unknown';
                        }

                        return app(ProductSellabilityService::class)->isLocallySellable($product)
                            ? 'Sellable'
                            : 'Not Sellable';
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Sellable' ? 'success' : 'danger')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                IconColumn::make('inventory_local_dirty')
                    ->label('Pending Push')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('inventory_sync_error')
                    ->label('Sync Error')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('inventory_last_synced_at')
                    ->label('From Shopify')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders'], true)),
                TextColumn::make('inventory_pushed_at')
                    ->label('To Shopify')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('last_synced_at')
                    ->label('Last Synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('updated_at')
                    ->label('Updated Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('created_at')
                    ->label('Created Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
            ])
            ->filters([
                SelectFilter::make('inventory_state')
                    ->label('Inventory State')
                    ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders'], true))
                    ->options([
                        'in_stock' => 'In Stock',
                        'out_of_stock' => 'Out Of Stock',
                        'not_tracked' => 'Not Tracked / Unknown',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($query->getModel() instanceof Variant)) {
                            return $query;
                        }
                        return match ($data['value'] ?? null) {
                            'in_stock' => $query->where('inventory_tracked', true)->where('inventory_qty', '>', 0),
                            'out_of_stock' => $query->where('inventory_tracked', true)->where('inventory_qty', '<=', 0),
                            'not_tracked' => $query->where(fn (Builder $builder) => $builder
                                ->where('inventory_tracked', false)->orWhereNull('inventory_tracked')),
                            default => $query,
                        };
                    }),
                SelectFilter::make('order_state')
                    ->label('On Order Status')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders')
                    ->options([
                        'any_on_order' => 'Any Quantity On Order',
                        'planned_only' => 'Quantity To Order (Not Placed)',
                        'multiple_wip' => 'Multiple WIP Orders',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($query->getModel() instanceof Variant)) {
                            return $query;
                        }
                        return match ($data['value'] ?? null) {
                            'any_on_order' => $query->whereHas('procurementIncomingStock', fn (Builder $stockQuery): Builder => $stockQuery
                                ->where('total_quantity_on_order', '>', 0)),
                            'planned_only' => $query->whereHas('procurementIncomingStock', fn (Builder $stockQuery): Builder => $stockQuery
                                ->where('quantity_to_order', '>', 0)->where('total_quantity_on_order', 0)),
                            'multiple_wip' => $query->whereHas('procurementIncomingStock', fn (Builder $stockQuery): Builder => $stockQuery
                                ->where('number_of_wip_orders', '>', 1)),
                            default => $query,
                        };
                    }),
                SelectFilter::make('order_review')
                    ->label('Review Upload')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders')
                    ->options([
                        'latest_orders' => 'Latest Orders Upload',
                        'latest_receipts' => 'Latest Received Upload',
                        'awaiting_shopify' => 'Awaiting Shopify Push',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($query->getModel() instanceof Variant)) {
                            return $query;
                        }
                        $value = $data['value'] ?? null;
                        if ($value === 'awaiting_shopify') {
                            return $query->whereHas('supplierOrderLines.receipts', fn (Builder $receiptQuery): Builder => $receiptQuery
                                ->where('status', 'pending'));
                        }
                        $type = match ($value) {
                            'latest_orders' => 'order',
                            'latest_receipts' => 'receipt',
                            default => null,
                        };
                        if ($type === null) {
                            return $query;
                        }
                        $batch = ProcurementSupplierImportBatch::query()
                            ->where('type', $type)->where('status', 'completed')->latest('completed_at')->first();
                        $skus = collect($batch?->preview_rows ?? [])->pluck('sku')
                            ->map(fn ($sku): string => strtoupper(trim((string) $sku)))->filter()->unique();

                        return $skus->isEmpty()
                            ? $query->whereRaw('1 = 0')
                            : $query->whereIn(DB::raw('UPPER(TRIM(sku))'), $skus);
                    }),
                Filter::make('sku_list')
                    ->label('SKU List')
                    ->form([
                        Forms\Components\Textarea::make('skus')
                            ->label('SKUs')
                            ->rows(5)
                            ->helperText('Paste SKUs separated by commas, spaces, or new lines.'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($query->getModel() instanceof Variant)) {
                            return $query;
                        }
                        $skus = collect(preg_split('/[\s,;]+/', strtoupper((string) ($data['skus'] ?? ''))))
                            ->map(fn ($sku): string => trim((string) $sku))->filter()->unique();

                        return $skus->isEmpty() ? $query : $query->whereIn(DB::raw('UPPER(TRIM(sku))'), $skus);
                    })
                    ->indicateUsing(fn (array $data): ?string => filled($data['skus'] ?? null) ? 'SKU list applied' : null),
                Filter::make('supplier_report_order_id')
                    ->label('Order ID')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports')
                    ->form([
                        Forms\Components\TextInput::make('order_id')->label('Order ID'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->getModel() instanceof ProcurementSupplierOrder && filled($data['order_id'] ?? null)
                        ? $query->where('order_number', 'like', '%'.trim((string) $data['order_id']).'%')
                        : $query),
                Filter::make('supplier_report_created_by')
                    ->label('Created By')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports')
                    ->form([
                        Forms\Components\TextInput::make('created_by')->label('Created By'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->getModel() instanceof ProcurementSupplierOrder && filled($data['created_by'] ?? null)
                        ? $query->whereHas('createdBy', fn (Builder $userQuery): Builder => $userQuery
                            ->where('name', 'like', '%'.trim((string) $data['created_by']).'%')
                            ->orWhere('email', 'like', '%'.trim((string) $data['created_by']).'%'))
                        : $query),
                SelectFilter::make('supplier_report_status_filter')
                    ->label('Order Status')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports')
                    ->options([
                        'open' => 'Pending/Open',
                        'partial' => 'Partially Received',
                        'completed' => 'Completed',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($query->getModel() instanceof ProcurementSupplierOrder)) {
                            return $query;
                        }
                        return match ($data['value'] ?? null) {
                            'open' => $query->whereHas('lines', fn (Builder $lineQuery): Builder => $lineQuery
                                ->where('status', 'open')
                                ->whereDoesntHave('receipts', fn (Builder $receiptQuery): Builder => $receiptQuery->where('status', 'succeeded'))),
                            'partial' => $query
                                ->whereHas('lines.receipts', fn (Builder $receiptQuery): Builder => $receiptQuery->where('status', 'succeeded'))
                                ->whereHas('lines', fn (Builder $lineQuery): Builder => $lineQuery->where('status', 'open')),
                            'completed' => $query->whereHas('lines')
                                ->whereDoesntHave('lines', fn (Builder $lineQuery): Builder => $lineQuery->where('status', 'open')),
                            default => $query,
                        };
                    }),
                Filter::make('supplier_report_date_range')
                    ->label('Order Date')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From')->native(false),
                        Forms\Components\DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => ! ($query->getModel() instanceof ProcurementSupplierOrder) ? $query : $query
                        ->when($data['from'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('created_at', '<=', $date))),
                Filter::make('supplier_report_grv')
                    ->label('GRV Number')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'supplier_reports')
                    ->form([
                        Forms\Components\TextInput::make('grv_number')->label('GRV Number'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->getModel() instanceof ProcurementSupplierOrder && filled($data['grv_number'] ?? null)
                        ? $query->whereHas('receipts', fn (Builder $receiptQuery): Builder => $receiptQuery->where('grv_number', trim((string) $data['grv_number'])))
                        : $query),
                Filter::make('grv_report_grv')
                    ->label('GRV Number')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'grv_reports')
                    ->form([
                        Forms\Components\TextInput::make('grv_number')->label('GRV Number'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->getModel() instanceof ProcurementSupplierReceipt && filled($data['grv_number'] ?? null)
                        ? $query->where('grv_number', trim((string) $data['grv_number']))
                        : $query),
                Filter::make('grv_report_order_id')
                    ->label('Order ID')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'grv_reports')
                    ->form([
                        Forms\Components\TextInput::make('order_id')->label('Order ID'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->getModel() instanceof ProcurementSupplierReceipt && filled($data['order_id'] ?? null)
                        ? $query->whereHas('line.order', fn (Builder $orderQuery): Builder => $orderQuery->where('order_number', 'like', '%'.trim((string) $data['order_id']).'%'))
                        : $query),
                SelectFilter::make('adjustment_status_filter')
                    ->label('Adjustment Status')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'inventory_adjustments')
                    ->options([
                        InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL => 'Pending Approval',
                        InventoryAdjustmentRequest::STATUS_APPROVED => 'Approved',
                        InventoryAdjustmentRequest::STATUS_REJECTED => 'Rejected',
                        InventoryAdjustmentRequest::STATUS_APPLIED => 'Applied',
                        InventoryAdjustmentRequest::STATUS_FAILED => 'Failed',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->getModel() instanceof Variant && filled($data['value'] ?? null)
                        ? $query->whereHas('inventoryAdjustmentRequestItems.request', fn (Builder $requestQuery): Builder => $requestQuery->where('status', $data['value']))
                        : $query),
                Filter::make('adjustment_user')
                    ->label('Adjustment User')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'inventory_adjustments')
                    ->form([
                        Forms\Components\TextInput::make('user')->label('Requester/approver/reviewer'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->getModel() instanceof Variant && filled($data['user'] ?? null)
                        ? $query->whereHas('inventoryAdjustmentRequestItems.request', fn (Builder $requestQuery): Builder => $requestQuery
                            ->whereHas('requester', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', '%'.trim((string) $data['user']).'%'))
                            ->orWhereHas('approver', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', '%'.trim((string) $data['user']).'%'))
                            ->orWhereHas('reviewedBy', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', '%'.trim((string) $data['user']).'%')))
                        : $query),
                Filter::make('pending_push')
                    ->label('Pending Push')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday')
                    ->query(fn (Builder $query): Builder => $query->getModel() instanceof Variant ? $query->where('inventory_local_dirty', true) : $query),
                SelectFilter::make('product_status')
                    ->label('Status')
                    ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders'], true))
                    ->options([
                        'active' => 'Active',
                        'draft' => 'Draft',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($query->getModel() instanceof Variant)) {
                            return $query;
                        }
                        $value = strtolower(trim((string) ($data['value'] ?? '')));
                        if ($value === '') {
                            return $query;
                        }

                        return $query->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->whereRaw('LOWER(status) = ?', [$value]));
                    }),
            ])
            ->actions([
                Action::make('addSupplierOrder')
                    ->label('Add Stock On Order')->icon('heroicon-o-truck')
                    ->modalWidth(MaxWidth::Medium)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders'
                        && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                    ->form([
                        Forms\Components\TextInput::make('order_number')->label('Order ID')->required()->maxLength(255),
                        Forms\Components\TextInput::make('quantity_ordered')->label('Quantity Ordered')->integer()->minValue(1)->required(),
                        Forms\Components\DatePicker::make('eta_date')->label('ETA')->displayFormat('d/m/Y')->native(false)->required(),
                    ])
                    ->action(function (Variant $record, array $data): void {
                        app(SupplierOrderService::class)->createForVariant($record, $data['order_number'], $data['quantity_ordered'], $data['eta_date'], Auth::id());
                        try {
                            app(ProcurementSheetSyncService::class)->publishOperational([$record->id], includeHumanInputs: true);
                        } catch (\Throwable $e) {
                            Notification::make()->title('Order saved; Sheet update failed')->body($e->getMessage())->warning()->send();

                            return;
                        }
                        Notification::make()->title('Supplier order added')->body('The CMS ledger and procurement Sheets were updated.')->success()->send();
                    }),
                Action::make('receiveSupplierStock')
                    ->label('Receive Stock')->icon('heroicon-o-inbox-arrow-down')->color('success')
                    ->modalWidth(MaxWidth::Medium)
                    ->visible(fn (Model $record, $livewire): bool => $livewire->activeTab === 'orders'
                        && $record instanceof Variant
                        && app(InventoryAccessService::class)->canUpdateInventory(Auth::user())
                        && $record->supplierOrderLines->where('status', 'open')->contains(fn ($line) => $line->quantity_outstanding > 0))
                    ->form([
                        Forms\Components\Select::make('line_id')->label('Supplier Order')->required()
                            ->live()
                            ->options(fn (Variant $record): array => $record->supplierOrderLines->where('status', 'open')
                                ->filter(fn ($line) => $line->quantity_outstanding > 0)
                                ->mapWithKeys(fn ($line): array => [$line->id => (($line->order?->order_number ?: 'Legacy order').' — '.$line->quantity_outstanding.' outstanding')])->all()),
                        Forms\Components\TextInput::make('quantity_received')->label('Quantity Received')->integer()->minValue(1)->required(),
                        Forms\Components\Placeholder::make('out_of_sequence_warning')
                            ->label('Out-of-sequence warning')
                            ->content(function (Forms\Get $get): string {
                                $lineId = (int) ($get('line_id') ?? 0);
                                if ($lineId <= 0) {
                                    return 'Select a supplier order to check receipt sequence.';
                                }

                                $line = ProcurementSupplierOrderLine::query()->with('order')->find($lineId);
                                if (! $line instanceof ProcurementSupplierOrderLine) {
                                    return '-';
                                }

                                $warnings = app(SupplierReceiptService::class)->outOfSequenceWarningsForLine($line);
                                if ($warnings->isEmpty()) {
                                    return 'No earlier pending supplier orders were found for this SKU.';
                                }

                                return app(SupplierReceiptService::class)->warningMessage($line, $warnings);
                            }),
                        Forms\Components\Checkbox::make('confirm_out_of_sequence')
                            ->label('Confirm this receipt is correct if an earlier order exists'),
                        Forms\Components\Textarea::make('out_of_sequence_reason')
                            ->label('Out-of-sequence reason')
                            ->rows(3)
                            ->helperText('Required when this receipt is for a later ETA while an earlier order for the same SKU is still pending.'),
                        Forms\Components\Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                    ])
                    ->action(function (Variant $record, array $data): void {
                        $line = ProcurementSupplierOrderLine::query()->where('variant_id', $record->id)->findOrFail($data['line_id']);
                        $warnings = app(SupplierReceiptService::class)->outOfSequenceWarningsForLine($line);
                        if ($warnings->isNotEmpty() && ! (bool) ($data['confirm_out_of_sequence'] ?? false)) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'confirm_out_of_sequence' => 'Confirm that this out-of-sequence receipt is correct before continuing.',
                            ]);
                        }
                        app(SupplierReceiptService::class)->create(
                            $line,
                            $data['quantity_received'],
                            $data['idempotency_key'],
                            Auth::id(),
                            dispatch: false,
                            outOfSequenceReason: (string) ($data['out_of_sequence_reason'] ?? ''),
                            outOfSequenceConfirmedBy: Auth::id(),
                        );
                        Notification::make()->title('Receipt staged')->body('Review it with the Awaiting Shopify Push filter, then push the selected row.')->success()->send();
                    }),
                Action::make('viewSupplierOrders')
                    ->label('Order Details')->icon('heroicon-o-eye')->color('gray')
                    ->visible(fn (Model $record, $livewire): bool => $livewire->activeTab === 'orders' && $record instanceof Variant)
                    ->modalHeading(fn (Variant $record): string => 'Supplier orders — '.($record->sku ?: "Variant {$record->id}"))
                    ->modalContent(fn (Variant $record) => view('filament.inventory.supplier-orders', ['variant' => $record->load(['supplierOrderLines.order', 'supplierOrderLines.receipts'])]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('viewSupplierOrderReport')
                    ->label('Order Details')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (Model $record, $livewire): bool => $livewire->activeTab === 'supplier_reports' && $record instanceof ProcurementSupplierOrder)
                    ->modalHeading(fn (ProcurementSupplierOrder $record): string => 'Supplier order - '.($record->order_number ?: "Order {$record->id}"))
                    ->modalContent(fn (ProcurementSupplierOrder $record) => view('filament.inventory.supplier-order-report', [
                        'order' => $record->load(['createdBy', 'amendments.amendedBy', 'lines.variant.product', 'lines.draft', 'lines.receipts']),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('viewGrvReport')
                    ->label('GRV Details')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (Model $record, $livewire): bool => $livewire->activeTab === 'grv_reports' && $record instanceof ProcurementSupplierReceipt)
                    ->modalHeading(fn (ProcurementSupplierReceipt $record): string => 'GRV - '.($record->grv_number ?: "Receipt {$record->id}"))
                    ->modalContent(fn (ProcurementSupplierReceipt $record) => view('filament.inventory.grv-report', [
                        'receipt' => $record->load(['line.order', 'line.variant.product', 'createdBy']),
                        'receipts' => ProcurementSupplierReceipt::query()
                            ->with(['line.order', 'line.variant.product', 'createdBy'])
                            ->where('grv_number', $record->grv_number)
                            ->orderBy('id')
                            ->get(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('viewInventoryAdjustments')
                    ->label('Adjustment Details')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'inventory_adjustments')
                    ->modalHeading(fn (Variant $record): string => 'Inventory adjustments - '.($record->sku ?: "Variant {$record->id}"))
                    ->modalContent(fn (Variant $record) => view('filament.inventory.adjustments', [
                        'variant' => $record->load([
                            'inventoryAdjustmentRequestItems.request.requester',
                            'inventoryAdjustmentRequestItems.request.approver',
                            'inventoryAdjustmentRequestItems.request.reviewedBy',
                        ]),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('editInventory')
                    ->label('Update Inventory')
                    ->icon('heroicon-o-pencil-square')
                    ->modalWidth(MaxWidth::Medium)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'
                        && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                    ->form([
                        Forms\Components\Toggle::make('inventory_tracked')
                            ->label('Inventory tracked')
                            ->default(true)
                            ->live(),
                        Forms\Components\TextInput::make('on_hand_quantity')
                            ->label('On Hand quantity')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText('Enter the physical stock count. Shopify will calculate Available inventory.')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('inventory_tracked')),
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->rows(3),
                        Forms\Components\Select::make('approver_id')
                            ->label('Approver')
                            ->options(fn (): array => User::query()->whereKeyNot(Auth::id())->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->fillForm(fn (Variant $record): array => [
                        'inventory_tracked' => $record->inventory_tracked !== false,
                        'on_hand_quantity' => $record->current_on_hand_quantity ?? $record->inventory_qty,
                    ])
                    ->action(function (Variant $record, array $data): void {
                        app(InventoryAdjustmentApprovalService::class)->submit([[
                            'variant' => $record,
                            'inventory_tracked' => (bool) ($data['inventory_tracked'] ?? false),
                            'on_hand_quantity' => isset($data['on_hand_quantity']) ? (int) $data['on_hand_quantity'] : null,
                            'reason' => (string) ($data['reason'] ?? ''),
                        ]], (int) Auth::id(), (int) $data['approver_id'], 'single');

                        Notification::make()
                            ->title('Inventory approval requested')
                            ->body('The correction is pending approval and has not been pushed to Shopify.')
                            ->success()
                            ->send();
                    }),
                Action::make('updateStatus')
                    ->label('Update Status')
                    ->icon('heroicon-o-tag')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'
                        && app(InventoryAccessService::class)->canUpdateStatus(Auth::user()))
                    ->form([
                        Forms\Components\Select::make('status')
                            ->label('Product status')
                            ->options([
                                'active' => 'Active',
                                'draft' => 'Draft',
                                'archived' => 'Archived',
                            ])
                            ->required(),
                    ])
                    ->fillForm(fn (Variant $record): array => [
                        'status' => strtolower(trim((string) ($record->product?->status ?? 'draft'))),
                    ])
                    ->action(function (Variant $record, array $data): void {
                        $product = $record->product;
                        if (! $product instanceof Product) {
                            return;
                        }

                        InventoryOperationContext::run(function () use ($product, $data): void {
                            $product->status = (string) ($data['status'] ?? 'draft');
                            $product->save();
                        });

                        $product = $product->fresh(['variants']);
                        if ($product instanceof Product) {
                            app(ProductInventoryHistoryRecorder::class)->record(
                                $product,
                                Auth::id(),
                                ProductInventorySnapshot::SOURCE_LOCAL_UPDATE,
                            );
                        }

                        Notification::make()
                            ->title('Status updated locally')
                            ->success()
                            ->send();
                    }),
                Action::make('refreshFromShopify')
                    ->label('Read From Shopify')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders'], true)
                        && app(InventoryAccessService::class)->canAccess(Auth::user()))
                    ->requiresConfirmation()
                    ->modalHeading('Read Inventory From Shopify')
                    ->modalDescription('This will read the latest inventory and tracking state from Shopify for the selected variant.')
                    ->modalSubmitActionLabel('Confirm Read')
                    ->action(function (Variant $record): void {
                        InventorySyncJob::dispatch([$record->id], 'refresh', Auth::id());
                        Notification::make()
                            ->title('Inventory refresh queued')
                            ->body('Shopify inventory refresh is running in the background.')
                            ->success()
                            ->send();
                    }),
                Action::make('pushToShopify')
                    ->label('Push To Shopify')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'
                        && app(InventoryAccessService::class)->canAccess(Auth::user()))
                    ->requiresConfirmation()
                    ->modalHeading('Push Inventory To Shopify')
                    ->modalDescription('This will set Shopify On Hand to the staged physical count, update tracking and product status, then refresh Shopify inventory states.')
                    ->modalSubmitActionLabel('Confirm Push')
                    ->action(function (Variant $record): void {
                        InventorySyncJob::dispatch(
                            [$record->id],
                            'push',
                            Auth::id(),
                            'inventory_'.now()->format('YmdHis')
                        );

                        Notification::make()
                            ->title('Inventory push queued')
                            ->body('Shopify inventory sync is running in the background.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('exportSelectedInventory')
                        ->label('Export Selected Inventory CSV')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday')
                        ->action(fn (Collection $records) => response()->streamDownload(
                            fn () => print app(ProductInventoryCsvExporter::class)->exportToString($records->pluck('id')->map(fn ($id): int => (int) $id)->all()),
                            'selected-inventory-'.now()->format('Ymd_His').'.csv',
                        )),
                    BulkAction::make('exportSelectedOrders')
                        ->label('Export Selected Order CSV')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders')
                        ->action(fn (Collection $records) => response()->streamDownload(
                            fn () => print app(ProcurementSelectionCsvExporter::class)->pendingOrders($records),
                            'selected-pending-orders-'.now()->format('Ymd_His').'.csv',
                        )),
                    BulkAction::make('exportSelectedReceipts')
                        ->label('Export Selected Receipt CSV')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders')
                        ->action(fn (Collection $records) => response()->streamDownload(
                            fn () => print app(ProcurementSelectionCsvExporter::class)->receipts($records),
                            'selected-receipts-'.now()->format('Ymd_His').'.csv',
                        )),
                    BulkAction::make('exportSelectedOrderHistory')
                        ->label('Export Selected Order History')
                        ->icon('heroicon-o-document-arrow-down')
                        ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders')
                        ->action(fn (Collection $records) => response()->streamDownload(
                            fn () => print app(ProcurementSelectionCsvExporter::class)->orderHistory($records),
                            'selected-order-history-'.now()->format('Ymd_His').'.csv',
                        )),
                    BulkAction::make('pushReceivedToShopify')
                        ->label('Push Received To Shopify')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->color('success')
                        ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders'
                            && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                        ->requiresConfirmation()
                        ->modalDescription('Only staged receipts for the selected products will be pushed. Shopify inventory is increased by each received quantity, then remaining order quantities are recalculated.')
                        ->action(function (Collection $records): void {
                            $ids = ProcurementSupplierReceipt::query()
                                ->where('status', 'pending')
                                ->whereHas('line', fn (Builder $lineQuery): Builder => $lineQuery
                                    ->whereIn('variant_id', $records->pluck('id')))
                                ->pluck('id');
                            foreach ($ids as $id) {
                                ProcessSupplierReceiptJob::dispatch((int) $id)->onQueue('procurement');
                            }
                            Notification::make()->title('Received inventory queued')
                                ->body("{$ids->count()} staged receipt(s) will be pushed to Shopify and recalculated.")
                                ->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('startTracking')
                        ->label('Start Tracking Inventory')
                        ->icon('heroicon-o-check-circle')
                        ->color('warning')
                        ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'
                            && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                        ->requiresConfirmation()
                        ->modalHeading('Start tracking selected inventory?')
                        ->modalDescription('Only selected variants currently marked Not Tracked will change. Already tracked variants and variants with an unknown tracking state are skipped.')
                        ->modalSubmitActionLabel('Start Tracking')
                        ->form([
                            Forms\Components\TextInput::make('inventory_qty')
                                ->label('Starting On Hand quantity for every changed variant')
                                ->helperText('The same physical stock count is assigned to each selected Not Tracked variant.')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->default(0)
                                ->required(),
                            Forms\Components\Toggle::make('push_to_shopify')
                                ->label('Push changed variants to Shopify after updating')
                                ->helperText('Leave this off to review the local changes first. You can use the existing bulk Push To Shopify action later.')
                                ->default(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $result = app(BulkInventoryTrackingService::class)->startTracking(
                                $records,
                                (int) ($data['inventory_qty'] ?? 0),
                                Auth::id(),
                            );

                            if (($data['push_to_shopify'] ?? false) && ! empty($result['changed_variant_ids'])) {
                                InventorySyncJob::dispatch(
                                    $result['changed_variant_ids'],
                                    'push',
                                    Auth::id(),
                                    'inventory_'.now()->format('YmdHis'),
                                );
                            }

                            $body = "Updated {$result['updated']} variant(s).";
                            if ($result['already_tracked'] > 0) {
                                $body .= " Already tracked: {$result['already_tracked']}.";
                            }
                            if ($result['unknown_skipped'] > 0) {
                                $body .= " Unknown tracking state skipped: {$result['unknown_skipped']}.";
                            }
                            if (($data['push_to_shopify'] ?? false) && $result['updated'] > 0) {
                                $body .= ' Shopify push queued.';
                            }

                            $notification = Notification::make()
                                ->title($result['updated'] > 0
                                    ? 'Bulk inventory tracking updated'
                                    : 'No untracked variants changed')
                                ->body($body);

                            if ($result['updated'] > 0) {
                                $notification->success();
                            } else {
                                $notification->warning();
                            }

                            $notification->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('refreshFromShopify')
                        ->label('Read From Shopify')
                        ->visible(fn ($livewire): bool => in_array($livewire->activeTab, ['everyday', 'orders'], true)
                            && app(InventoryAccessService::class)->canAccess(Auth::user()))
                        ->requiresConfirmation()
                        ->modalHeading('Read Inventory From Shopify')
                        ->modalDescription('This will read the latest inventory and tracking state from Shopify for all selected variants.')
                        ->modalSubmitActionLabel('Confirm Read')
                        ->action(function (Collection $records): void {
                            InventorySyncJob::dispatch($records->pluck('id')->map(fn ($id): int => (int) $id)->all(), 'refresh', Auth::id());
                            Notification::make()
                                ->title('Inventory refresh queued')
                                ->body('Shopify inventory refresh is running in the background.')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('pushToShopify')
                        ->label('Push To Shopify')
                        ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'
                            && app(InventoryAccessService::class)->canAccess(Auth::user()))
                        ->requiresConfirmation()
                        ->modalHeading('Push Inventory To Shopify')
                        ->modalDescription('This will set Shopify On Hand to each staged physical count, update tracking and product status, then refresh Shopify inventory states.')
                        ->modalSubmitActionLabel('Confirm Push')
                        ->action(function (Collection $records): void {
                            InventorySyncJob::dispatch(
                                $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                                'push',
                                Auth::id(),
                                'inventory_'.now()->format('YmdHis')
                            );

                            Notification::make()
                                ->title('Inventory push queued')
                                ->body('Shopify inventory sync is running in the background.')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventories::route('/'),
        ];
    }

    private static function latestInventoryAdjustmentItem(Variant $record): ?InventoryAdjustmentRequestItem
    {
        return $record->inventoryAdjustmentRequestItems
            ->sortByDesc(fn (InventoryAdjustmentRequestItem $item): string => (string) ($item->request?->submitted_at ?? $item->created_at))
            ->first();
    }

    public static function canViewAny(): bool
    {
        return app(InventoryAccessService::class)->canAccess(Auth::user());
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
}
