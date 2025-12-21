<?php

namespace App\Filament\Resources\ChangeLogResource\Pages;

use App\Filament\Resources\ChangeLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewChangeLog extends ViewRecord
{
    protected static string $resource = ChangeLogResource::class;

    // protected function getHeaderActions(): array
    // {
    //     return [
    //         Actions\EditAction::make(),
    //     ];
    // }
}
