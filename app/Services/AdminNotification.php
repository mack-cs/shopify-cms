<?php

namespace App\Services;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

final class AdminNotification
{
    public static function send(Notification $notification, Authenticatable|User|null $user = null): void
    {
        $user = $user ?? Auth::user();

        if ($user instanceof User) {
            $notification->sendToDatabase($user);
        }

        $notification->send();
    }

    public static function sendToUserId(Notification $notification, ?int $userId): void
    {
        if (!$userId) {
            return;
        }

        $user = User::find($userId);
        if (!$user) {
            return;
        }

        $notification->sendToDatabase($user);
    }
}
