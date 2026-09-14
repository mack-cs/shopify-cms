<?php

namespace App\Filament\Resources\ProcurementSupplierOrderResource\RelationManagers;

use App\Models\ProcurementSupplierOrderLine;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\Procurement\SupplierOrderSummaryService;
use App\Services\Procurement\SupplierOrderService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

final class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['variant.product', 'draft', 'receipts']))
            ->columns([
                TextColumn::make('sku')->searchable(),
                TextColumn::make('product_label')
                    ->label('Product')
                    ->state(fn (ProcurementSupplierOrderLine $record): string => trim((string) ($record->variant?->product?->title ?? ''))
                        ?: (trim((string) ($record->draft?->title ?? '')) ?: 'Draft #' . $record->new_product_draft_id))
                    ->wrap(),
                TextColumn::make('variant.product.vendor')->label('Vendor')->placeholder('Draft'),
                TextColumn::make('quantity_ordered')->label('Ordered')->numeric(),
                TextColumn::make('quantity_received')->label('Received')->numeric(),
                TextColumn::make('quantity_outstanding')->label('Outstanding')->numeric(),
                TextColumn::make('eta_date')->label('ETA')->date('d/m/Y')->placeholder('-'),
                TextColumn::make('status')->badge(),
                TextColumn::make('completed_at')->label('Completed')->dateTime('d/m/Y H:i')->placeholder('-'),
                TextColumn::make('latest_grv')
                    ->label('Latest GRV')
                    ->state(fn (ProcurementSupplierOrderLine $record): ?string => $record->receipts->sortByDesc('created_at')->first()?->grv_number)
                    ->copyable(),
            ])
            ->actions([
                Action::make('amend')->label('Amend')->icon('heroicon-o-pencil-square')
                    ->visible(fn (ProcurementSupplierOrderLine $record): bool => $record->status === 'open')
                    ->form([
                        Forms\Components\TextInput::make('quantity_ordered')->label('Quantity Ordered')->integer()->minValue(1)->required(),
                        Forms\Components\DatePicker::make('eta_date')->label('ETA')->native(false),
                        Forms\Components\Textarea::make('reason')->label('Reason')->rows(3),
                    ])
                    ->fillForm(fn (ProcurementSupplierOrderLine $record): array => [
                        'quantity_ordered' => $record->quantity_ordered,
                        'eta_date' => $record->eta_date?->toDateString(),
                    ])
                    ->action(function (ProcurementSupplierOrderLine $record, array $data): void {
                        app(SupplierOrderService::class)->amendLine($record, $data, Auth::id(), $data['reason'] ?? null);
                        Notification::make()->title('Order line amended')->success()->send();
                    }),
                Action::make('cancel')->label('Cancel')->color('danger')->requiresConfirmation()
                    ->visible(fn (ProcurementSupplierOrderLine $record): bool => $record->status === 'open')
                    ->action(function (ProcurementSupplierOrderLine $record): void {
                        $record->update([
                            'status' => 'cancelled',
                            'cancelled_at' => now(),
                            'updated_by' => Auth::id(),
                        ]);
                        $variant = $record->variant()->with(['product', 'procurementIncomingStock'])->first();
                        if ($variant === null) {
                            Notification::make()->title('Draft order line cancelled')->success()->send();

                            return;
                        }

                        app(SupplierOrderSummaryService::class)->refreshVariant($variant, Auth::id(), 'cms:order-cancelled');
                        try {
                            app(ProcurementSheetSyncService::class)->publishOperational([$variant->id]);
                        } catch (\Throwable $exception) {
                            Notification::make()->title('Order cancelled; Sheet update failed')->body($exception->getMessage())->warning()->send();

                            return;
                        }
                        Notification::make()->title('Order line cancelled')->success()->send();
                    }),
            ]);
    }
}
