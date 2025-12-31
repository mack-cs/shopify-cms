<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Password;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => UserResource::canDelete($this->getRecord())),
            Actions\Action::make('forcePasswordReset')
                ->label('Force Password Reset')
                ->requiresConfirmation()
                ->visible(fn () => UserResource::canEdit($this->getRecord()))
                ->action(function (): void {
                    $record = $this->getRecord();
                    if (!$record) {
                        return;
                    }

                    $record->forceFill(['force_password_change' => true])->save();

                    $status = Password::sendResetLink(['email' => $record->email]);
                    if ($status === Password::RESET_LINK_SENT) {
                        Notification::make()
                            ->title('Reset email sent')
                            ->success()
                            ->send();
                        return;
                    }

                    Notification::make()
                        ->title('Failed to send reset email')
                        ->danger()
                        ->send();
                }),
        ];
    }
}
