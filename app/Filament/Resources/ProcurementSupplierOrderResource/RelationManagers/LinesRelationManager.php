<?php

namespace App\Filament\Resources\ProcurementSupplierOrderResource\RelationManagers;

use App\Models\ProcurementSupplierOrderLine;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\Procurement\SupplierOrderSummaryService;
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
            ->modifyQueryUsing(fn ($query) => $query->with(['variant.product', 'receipts']))
            ->columns([
                TextColumn::make('sku')->searchable(),
                TextColumn::make('variant.product.title')->label('Product')->wrap(),
                TextColumn::make('variant.product.vendor')->label('Vendor'),
                TextColumn::make('quantity_ordered')->label('Ordered')->numeric(),
                TextColumn::make('quantity_received')->label('Received')->numeric(),
                TextColumn::make('quantity_outstanding')->label('Outstanding')->numeric(),
                TextColumn::make('eta_date')->label('ETA')->date('d/m/Y')->placeholder('-'),
                TextColumn::make('status')->badge(),
                TextColumn::make('completed_at')->label('Completed')->dateTime('d/m/Y H:i')->placeholder('-'),
            ])
            ->actions([
                Action::make('cancel')->label('Cancel')->color('danger')->requiresConfirmation()
                    ->visible(fn (ProcurementSupplierOrderLine $record): bool => $record->status === 'open')
                    ->action(function (ProcurementSupplierOrderLine $record): void {
                        $record->update([
                            'status' => 'cancelled',
                            'cancelled_at' => now(),
                            'updated_by' => Auth::id(),
                        ]);
                        $variant = $record->variant()->with(['product', 'procurementIncomingStock'])->firstOrFail();
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
