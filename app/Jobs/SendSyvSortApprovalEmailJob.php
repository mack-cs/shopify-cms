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
        if (! $notification || ! in_array($notification->status, [SyvNotification::PENDING, SyvNotification::FAILED], true)) {
            return;
        }
        if (! (bool) config('shop_your_vibe.sort_approval_emails', true)) {
            return;
        }
        $reviewer = User::query()->find($notification->payload['reviewer_id'] ?? null);
        $email = trim((string) ($reviewer?->email ?? ''));
        if ($email === '') {
            SyvNotification::whereKey($notification->id)->whereIn('status',[SyvNotification::PENDING,SyvNotification::FAILED])->update([
                'status' => SyvNotification::FAILED,
                'attempts' => \Illuminate\Support\Facades\DB::raw('attempts + 1'),
                'last_error' => 'The assigned reviewer has no email address.',
            ]);
            throw new \RuntimeException('The assigned reviewer has no email address.');
        }
        $mail = new SyvSortApprovalMail($notification->payload ?? []);
        try {
            view($mail->content()->view, ['details'=>$mail->details])->render(); // Proven pre-transport failure.
        } catch (\Throwable $exception) {
            SyvNotification::whereKey($notification->id)->whereIn('status',[SyvNotification::PENDING,SyvNotification::FAILED])
                ->update(['status'=>SyvNotification::FAILED,'last_error'=>$exception->getMessage(),'attempts'=>\Illuminate\Support\Facades\DB::raw('attempts + 1')]);
            throw $exception;
        }
        $claimed = SyvNotification::whereKey($notification->id)->whereIn('status',[SyvNotification::PENDING,SyvNotification::FAILED])
            ->update(['status'=>'sending','attempts'=>\Illuminate\Support\Facades\DB::raw('attempts + 1'),'updated_at'=>now()]);
        if ($claimed !== 1) return;
        $notification->refresh();
        try {
            Mail::to($email)->send($mail);
            $notification->forceFill([
                'status' => SyvNotification::SENT,
                'sent_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $notification->forceFill([
                'status' => 'uncertain',
                'last_error' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }
    }
}
