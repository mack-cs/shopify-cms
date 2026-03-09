<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Filament\Resources\NewProductDraftResource;
use App\Services\NewProductDraftProductSync;
use Filament\Resources\Pages\EditRecord;

class EditNewProductDraft extends EditRecord
{
    protected static string $resource = NewProductDraftResource::class;

    protected function afterSave(): void
    {
        /** @var \App\Models\NewProductDraft $draft */
        $draft = $this->record;

        app(NewProductDraftProductSync::class)->syncToExistingProduct(
            $draft,
            ensureApprovalReset: false
        );
    }
}
