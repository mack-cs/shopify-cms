<?php

namespace App\Filament\Resources\ChangeLogResource\Pages;

use App\Filament\Resources\ChangeLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChangeLogs extends ListRecords
{
    protected static string $resource = ChangeLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
