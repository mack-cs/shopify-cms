<?php

namespace App\Filament\Resources\ShopifyCollectionResource\Pages;

use App\Filament\Resources\ShopifyCollectionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShopifyCollection extends EditRecord
{
    protected static string $resource = ShopifyCollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
            Actions\Action::make('approveDelete')
                ->label('Approve Delete')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn () => ShopifyCollectionResource::canDelete($this->getRecord()))
                ->action(function (): void {
                    ShopifyCollectionResource::approveDeletion($this->getRecord());
                }),
            Actions\Action::make('pushToShopify')
                ->label('Push to Shopify')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('primary')
                ->requiresConfirmation()
                ->form(ShopifyCollectionResource::pushToShopifyFormSchema())
                ->action(function (array $data): void {
                    ShopifyCollectionResource::queuePushToShopify($this->getRecord(), $data);
                }),
            Actions\Action::make('requestApproval')
                ->label('Request Approval')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('warning')
                ->requiresConfirmation()
                ->form(ShopifyCollectionResource::approvalRequestFormSchema())
                ->action(function (array $data): void {
                    ShopifyCollectionResource::requestApprovalForRecords(collect([$this->getRecord()]), $data);
                }),
        ];
    }
}
