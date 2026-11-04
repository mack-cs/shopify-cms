<?php

namespace App\Jobs;

use App\Mail\SyvLaunchSortWarningMail;
use App\Models\SyvNotification;
use App\Services\ShopYourVibeSorting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendSyvLaunchSortWarningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId)
    {
        $this->onConnection('database')->onQueue('syv-notifications');
    }

    public function handle(ShopYourVibeSorting $sorting): void
    {
        $notification = SyvNotification::query()->find($this->notificationId);
        if (! $notification || in_array($notification->status, [SyvNotification::SENT, SyvNotification::SUPPRESSED], true)) {
            return;
        }
        $gid = (string) ($notification->payload['product_gid'] ?? '');
        $outstanding = $gid === '' ? [] : $sorting->outstandingForProduct($gid);
        if ($outstanding === []) {
            $notification->forceFill([
                'status' => SyvNotification::SUPPRESSED,
                'payload' => array_replace($notification->payload ?? [], ['collections' => []]),
                'last_error' => null,
            ])->save();

            return;
        }
        $recipients = array_values(array_filter(array_map(
            'trim',
            (array) config('shop_your_vibe.launch_sort_notify_to', []),
        )));
        $payload = array_replace($notification->payload ?? [], ['collections' => $outstanding]);
        if ($recipients === []) {
            $notification->forceFill([
                'status' => SyvNotification::FAILED,
                'attempts' => $notification->attempts + 1,
                'last_error' => 'No launch sorting recipients are configured.',
                'payload' => $payload,
            ])->save();
            throw new \RuntimeException('No launch sorting recipients are configured.');
        }
        try {
            Mail::to($recipients)->send(new SyvLaunchSortWarningMail($payload));
            $notification->forceFill([
                'status' => SyvNotification::SENT,
                'payload' => $payload,
                'sent_at' => now(),
                'last_error' => null,
                'attempts' => $notification->attempts + 1,
            ])->save();
        } catch (\Throwable $exception) {
            $notification->forceFill([
                'status' => SyvNotification::FAILED,
                'payload' => $payload,
                'attempts' => $notification->attempts + 1,
                'last_error' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }
    }
}
