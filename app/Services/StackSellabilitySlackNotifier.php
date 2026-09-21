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
        $changes = collect($summary['changes'] ?? [])
            ->filter(fn ($change): bool => is_array($change))
            ->filter(fn (array $change): bool => in_array($change['action'] ?? null, ['disabled', 'restored'], true))
            ->values();

        if ($changes->isEmpty()) {
            return false;
        }

        $channel = trim((string) config('services.slack.channels.inventory'));
        if ($channel === '') {
            return false;
        }

        Notification::route('slack', $channel)
            ->notify(new StackSellabilityChangedSlackNotification(array_merge($summary, [
                'changes' => $changes->all(),
            ])));

        return true;
    }
}
