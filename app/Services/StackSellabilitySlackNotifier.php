<?php

namespace App\Services;

use App\Notifications\StackSellabilityChangedSlackNotification;
use Illuminate\Support\Facades\Notification;

final class StackSellabilitySlackNotifier
{
    /**
     * @param array<string, mixed> $summary
     */
    public function notifyIfChanged(array $summary): bool
    {
        if (empty($summary['changes']) || !is_array($summary['changes'])) {
            return false;
        }

        $channel = trim((string) config('services.slack.channels.inventory'));
        if ($channel === '') {
            return false;
        }

        Notification::route('slack', $channel)
            ->notify(new StackSellabilityChangedSlackNotification($summary));

        return true;
    }
}
