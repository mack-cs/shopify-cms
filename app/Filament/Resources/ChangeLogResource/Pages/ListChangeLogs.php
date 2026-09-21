<?php

namespace App\Filament\Resources\ChangeLogResource\Pages;

use App\Filament\Resources\ChangeLogResource;
use Filament\Resources\Pages\ListRecords;

class ListChangeLogs extends ListRecords
{
    protected static string $resource = ChangeLogResource::class;
}
