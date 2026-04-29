<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNewProductDraft extends EditRecord
{
    protected static string $resource = NewProductDraftResource::class;
    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return NewProductDraftResource::mutateDraftFormData($data);
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
}
