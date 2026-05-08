<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\ProductResource;
use Filament\Notifications\Notification;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNewProductDraft extends EditRecord
{
    protected static string $resource = NewProductDraftResource::class;
    protected ?bool $hasUnsavedDataChangesAlert = true;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        if ($this->hasBlockingShopifyWarnings()) {
            Notification::make()
                ->title('Shopify conflicts detected')
                ->body('This draft has unresolved Shopify sync warnings. Non-conflicting changes can still be saved, but conflicting field changes will not be applied until you resolve them at the top.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return NewProductDraftResource::mutateDraftFormData($data, $this->record);
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()
            ->tooltip(fn (): ?string => $this->hasBlockingShopifyWarnings()
                ? 'Conflicting field changes will not be applied until you resolve the Shopify warnings at the top. Non-conflicting changes can still be saved.'
                : null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('editImagesAndVariants')
                ->label('Edit Product')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->visible(fn (): bool => $this->record?->product !== null)
                ->url(fn (): ?string => $this->record?->product
                    ? ProductResource::getUrl('edit', [
                        'record' => $this->record->product,
                        'activeRelationManager' => '1',
                    ]) . '#relationManager1'
                    : null),
        ];
    }

    private function hasBlockingShopifyWarnings(): bool
    {
        return (($this->record?->shopifySyncWarningCount() ?? 0) > 0);
    }
}
