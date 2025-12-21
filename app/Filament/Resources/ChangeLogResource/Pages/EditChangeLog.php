<?php

namespace App\Filament\Resources\ChangeLogResource\Pages;

use App\Filament\Resources\ChangeLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChangeLog extends EditRecord
{
    protected static string $resource = ChangeLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
