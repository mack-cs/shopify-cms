<?php

namespace App\Filament\Resources\ChangeLogResource\Pages;

use App\Filament\Resources\ChangeLogResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateChangeLog extends CreateRecord
{
    protected static string $resource = ChangeLogResource::class;
}
