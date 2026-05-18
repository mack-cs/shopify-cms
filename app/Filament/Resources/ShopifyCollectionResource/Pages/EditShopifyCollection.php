<?php

namespace App\Filament\Resources\ShopifyCollectionResource\Pages;

use App\Filament\Resources\ShopifyCollectionResource;
use App\Models\ShopifyCollection;
use App\Services\CollectionApprovalRequestService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditShopifyCollection extends EditRecord
{
    protected static string $resource = ShopifyCollectionResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        if ($this->record instanceof ShopifyCollection && $this->record->isPendingApproval()) {
            Notification::make()
                ->title('Collection pending approval')
                ->body('This collection already has a pending approval request. Editing is disabled until that approval request is withdrawn or completed.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()
            ->disabled(fn (): bool => $this->isPendingApprovalLocked())
            ->tooltip(fn (): ?string => $this->isPendingApprovalLocked()
                ? 'This collection is already pending approval. Withdraw the pending approval before editing it again.'
                : null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('withdrawApproval')
                ->label('Withdraw Approval')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => $this->isPendingApprovalLocked())
                ->requiresConfirmation()
                ->action(function (): void {
                    if (!$this->record instanceof ShopifyCollection) {
                        return;
                    }

                    $requests = $this->record->approvalRequests()
                        ->where('approval_version', $this->record->approval_version)
                        ->where('status', \App\Models\CollectionApprovalRequest::STATUS_PENDING)
                        ->get();

                    $summary = app(CollectionApprovalRequestService::class)
                        ->deletePendingRequests($requests, (int) Auth::id());

                    $this->record = $this->record->fresh() ?? $this->record;

                    Notification::make()
                        ->title('Collection approval withdrawn')
                        ->body("Removed {$summary['deleted']} pending approval request(s).")
                        ->warning()
                        ->send();
                }),
            Actions\Action::make('requestApproval')
                ->label('Request Approval')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('success')
                ->visible(fn (): bool => !$this->isPendingApprovalLocked())
                ->requiresConfirmation()
                ->form(ShopifyCollectionResource::approvalRequestFormSchema())
                ->action(function (array $data): void {
                    ShopifyCollectionResource::requestApprovalForRecords(collect([$this->getRecord()]), $data);
                }),
            Actions\Action::make('pushToShopify')
                ->label('Push to Shopify')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('primary')
                ->disabled(fn (): bool => $this->isPendingApprovalLocked())
                ->requiresConfirmation()
                ->form(ShopifyCollectionResource::pushToShopifyFormSchema())
                ->action(function (array $data): void {
                    ShopifyCollectionResource::queuePushToShopify($this->getRecord(), $data);
                }),
            Actions\Action::make('approveDelete')
                ->label('Approve Delete')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn () => ShopifyCollectionResource::canDelete($this->getRecord()))
                ->action(function (): void {
                    ShopifyCollectionResource::approveDeletion($this->getRecord());
                }),
            Actions\Action::make('requestDelete')
                ->label('Request Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Reason')
                        ->rows(3)
                        ->maxLength(1000),
                ])
                ->visible(fn () => ShopifyCollectionResource::canDelete($this->getRecord()))
                ->action(function (array $data): void {
                    ShopifyCollectionResource::requestDeletion($this->getRecord(), $data['reason'] ?? null);
                }),
        ];
    }

    private function isPendingApprovalLocked(): bool
    {
        return $this->record instanceof ShopifyCollection
            && $this->record->isPendingApproval();
    }
}
