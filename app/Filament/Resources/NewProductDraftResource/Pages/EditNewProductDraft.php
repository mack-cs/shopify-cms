<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\ProductResource;
use Filament\Notifications\Notification;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditNewProductDraft extends EditRecord
{
    protected static string $resource = NewProductDraftResource::class;
    protected ?bool $hasUnsavedDataChangesAlert = true;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        if ($this->hasBlockingShopifyWarnings()) {
            Notification::make()
                ->title('Resolve Shopify conflicts first')
                ->body('This draft has unresolved Shopify sync warnings. Use Keep Draft Values or Use Shopify Values at the top before saving.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return NewProductDraftResource::mutateDraftFormData($data);
    }

    protected function beforeSave(): void
    {
        if (!$this->hasBlockingShopifyWarnings()) {
            return;
        }

        Notification::make()
            ->title('Resolve Shopify conflicts first')
            ->body('Saving is blocked until the Shopify sync warnings at the top of the draft are resolved.')
            ->danger()
            ->persistent()
            ->send();

        throw new Halt();
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()
            ->disabled(fn (): bool => $this->hasBlockingShopifyWarnings())
            ->tooltip(fn (): ?string => $this->hasBlockingShopifyWarnings()
                ? 'Resolve the Shopify sync warnings at the top of the draft before saving.'
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
