<?php

namespace App\Filament\Resources\DropdownOptionResource\Pages;

use App\Filament\Resources\DropdownOptionResource;
use App\Jobs\RecalculateDropdownOptionProductsJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDropdownOptions extends ListRecords
{
    protected static string $resource = DropdownOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add dropdown value'),
            Actions\Action::make('revalidateProducts')
                ->label('Revalidate product dropdowns')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription('Queues a fresh dropdown validation for existing products, including products with stale Materials and Dimensions errors.')
                ->action(function (): void {
                    RecalculateDropdownOptionProductsJob::dispatch(null, null);

                    Notification::make()
                        ->title('Product dropdown revalidation queued')
                        ->success()
                        ->send();
                }),
        ];
    }
}
