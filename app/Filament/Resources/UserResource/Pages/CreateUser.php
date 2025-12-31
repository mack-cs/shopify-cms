<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['password'] = Str::random(40);
        $data['force_password_change'] = true;

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        if (!$record) {
            return;
        }

        $status = Password::sendResetLink(['email' => $record->email]);

        if ($status === Password::RESET_LINK_SENT) {
            Notification::make()
                ->title('Password reset email sent')
                ->success()
                ->send();
            return;
        }

        Notification::make()
            ->title('Failed to send password reset email')
            ->danger()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return UserResource::getUrl('index');
    }
}
