<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Models\Import;
use App\Filament\Resources\ImportResource;
use App\Services\AdminNotification;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use App\Services\ShopifyCsvValidator;

class CreateImport extends CreateRecord
{
    protected static string $resource = ImportResource::class;



    protected function mutateFormDataBeforeCreate(array $data): array
    {
        Import::query()->update(['is_current' => false]);

        $data['created_by'] = auth()->id(); // fixes NOT NULL constraint
        $data['is_current'] = true;

        // If you store path like "imports/abc123.csv"
        if (!empty($data['stored_path'])) {
            $data['filename'] = basename($data['stored_path']); // required NOT NULL
        }

        // optional: keep original name if you have column for it
        if (!empty($data['upload']) && empty($data['original_filename'])) {
            $data['original_filename'] = $data['upload']; // see form below for better way
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return ImportResource::getUrl('index');
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        if (!$record) {
            return;
        }

        $validator = app(ShopifyCsvValidator::class);
        $result = ImportResource::validateImportRecord($record, $validator);

        if ($result['valid']) {
            AdminNotification::send(
                Notification::make()
                    ->title('CSV looks valid')
                    ->success()
            );
            return;
        }

        $body = ImportResource::formatValidationErrors($result['errors']);

        AdminNotification::send(
            Notification::make()
                ->title('CSV validation failed')
                ->body($body)
                ->danger()
        );
    }

}
