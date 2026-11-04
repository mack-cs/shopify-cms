<?php

namespace App\Jobs;

use App\Mail\SyvSortApprovalMail;
use App\Models\SyvNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendSyvSortApprovalEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId)
    {
        $this->onConnection('database')->onQueue('syv-notifications');
    }

    public function handle(): void
    {
        $notification = SyvNotification::query()->find($this->notificationId);
        if (! $notification || in_array($notification->status, [SyvNotification::SENT, SyvNotification::SUPPRESSED], true)) {
            return;
        }
        if (! (bool) config('shop_your_vibe.sort_approval_emails', true)) {
            return;
        }
        $reviewer = User::query()->find($notification->payload['reviewer_id'] ?? null);
        $email = trim((string) ($reviewer?->email ?? ''));
        if ($email === '') {
            $notification->forceFill([
                'status' => SyvNotification::FAILED,
                'attempts' => $notification->attempts + 1,
                'last_error' => 'The assigned reviewer has no email address.',
            ])->save();
            throw new \RuntimeException('The assigned reviewer has no email address.');
        }
        try {
            Mail::to($email)->send(new SyvSortApprovalMail($notification->payload ?? []));
            $notification->forceFill([
                'status' => SyvNotification::SENT,
                'sent_at' => now(),
                'last_error' => null,
                'attempts' => $notification->attempts + 1,
            ])->save();
        } catch (\Throwable $exception) {
            $notification->forceFill([
                'status' => SyvNotification::FAILED,
                'attempts' => $notification->attempts + 1,
                'last_error' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }
    }
}
