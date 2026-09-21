<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryAdjustmentRequestResource\Pages;
use App\Models\InventoryAdjustmentRequest;
use App\Services\InventoryAccessService;
use App\Services\InventoryAdjustmentApprovalService;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class InventoryAdjustmentRequestResource extends Resource
{
    protected static ?string $model = InventoryAdjustmentRequest::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationLabel = 'Inventory Approvals';
    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['items.variant.product', 'requester', 'approver'])->withCount('items'))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('items_count')->label('SKUs')->numeric(),
                TextColumn::make('requester.name')->label('Requester')->placeholder('Unknown'),
                TextColumn::make('approver.name')->label('Approver')->placeholder('Unknown'),
                TextColumn::make('submitted_at')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('sync_batch_id')->label('Shopify Batch')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL => 'Pending',
                    InventoryAdjustmentRequest::STATUS_APPROVED => 'Approved',
                    InventoryAdjustmentRequest::STATUS_REJECTED => 'Rejected',
                    InventoryAdjustmentRequest::STATUS_APPLIED => 'Applied',
                    InventoryAdjustmentRequest::STATUS_FAILED => 'Failed',
                ]),
            ])
            ->actions([
                Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (InventoryAdjustmentRequest $record) => view('filament.inventory.approval-request', [
                        'request' => $record->load(['items.variant.product', 'requester', 'approver']),
                    ])),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (InventoryAdjustmentRequest $record): bool => $record->status === InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL && (int) $record->requester_id !== (int) Auth::id())
                    ->action(fn (InventoryAdjustmentRequest $record) => app(InventoryAdjustmentApprovalService::class)->approve($record, (int) Auth::id())),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->form([Forms\Components\Textarea::make('reason')->label('Rejection reason')->rows(3)])
                    ->visible(fn (InventoryAdjustmentRequest $record): bool => $record->status === InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL && (int) $record->requester_id !== (int) Auth::id())
                    ->action(fn (InventoryAdjustmentRequest $record, array $data) => app(InventoryAdjustmentApprovalService::class)->reject($record, (int) Auth::id(), $data['reason'] ?? null)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInventoryAdjustmentRequests::route('/')];
    }

    public static function canViewAny(): bool
    {
        return app(InventoryAccessService::class)->canAccess(Auth::user());
    }
}
