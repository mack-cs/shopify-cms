<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\NewProductDraftResource\Widgets\QuickCreateNewProductDraft;
use Filament\Resources\Pages\ListRecords;

class ListNewProductDrafts extends ListRecords
{
    protected static string $resource = NewProductDraftResource::class;
    protected $listeners = ['draft-created' => '$refresh'];

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            QuickCreateNewProductDraft::class,
        ];
    }
}
