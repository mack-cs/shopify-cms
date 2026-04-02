<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\ProductResource;
use App\Services\NewProductDraftProductSync;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNewProductDraft extends EditRecord
{
    protected static string $resource = NewProductDraftResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return NewProductDraftResource::mutateDraftFormData($data);
    }

    protected function afterSave(): void
    {
        /** @var \App\Models\NewProductDraft $draft */
        $draft = $this->record;

        app(NewProductDraftProductSync::class)->syncToExistingProduct(
            $draft,
            ensureApprovalReset: false
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('editImagesAndVariants')
                ->label('Edit Images / Variants')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->visible(fn (): bool => $this->record?->product !== null)
                ->url(fn (): ?string => $this->record?->product
                    ? ProductResource::getUrl('edit', ['record' => $this->record->product])
                    : null),
        ];
    }
}
