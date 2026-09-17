<?php

namespace App\Services\Procurement;

use App\Mail\PendingSupplierReceiptPushReminderMail;
use App\Models\ProcurementSupplierReceipt;
use App\Notifications\PendingSupplierReceiptPushSlackNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

final class PendingSupplierReceiptPushReminderService
{
    /**
     * @return array{pending_count:int,email_sent:int,slack_sent:bool,errors:array<int,string>}
     */
    public function sendDueReminders(): array
    {
        $delayMinutes = max(1, (int) config('procurement.pending_receipt_push_reminder_minutes', 30));
        $receipts = ProcurementSupplierReceipt::query()
            ->with(['createdBy', 'line.order', 'line.variant.product'])
            ->where('status', 'pending')
            ->whereNull('pending_push_reminded_at')
            ->where('created_at', '<=', now()->subMinutes($delayMinutes))
            ->orderBy('created_at')
            ->get();

        $result = [
            'pending_count' => $receipts->count(),
            'email_sent' => 0,
            'slack_sent' => false,
            'errors' => [],
        ];

        if ($receipts->isEmpty()) {
            return $result;
        }

        foreach ($receipts as $receipt) {
            $email = trim((string) $receipt->createdBy?->email);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result['errors'][] = "Receipt {$receipt->id}: no valid creator email.";
                continue;
            }

            try {
                Mail::to($email)->send(new PendingSupplierReceiptPushReminderMail($receipt));
                $result['email_sent']++;
            } catch (\Throwable $exception) {
                $result['errors'][] = "Receipt {$receipt->id} email: ".$exception->getMessage();
                Log::error('Pending supplier receipt push email reminder failed.', [
                    'receipt_id' => $receipt->id,
                    'email' => $email,
                    'exception' => $exception,
                ]);
            }
        }

        $channel = trim((string) config('services.slack.channels.inventory'));
        if ($channel !== '') {
            try {
                Notification::route('slack', $channel)
                    ->notify(new PendingSupplierReceiptPushSlackNotification($receipts));
                $result['slack_sent'] = true;
            } catch (\Throwable $exception) {
                $result['errors'][] = 'Slack: '.$exception->getMessage();
                Log::error('Pending supplier receipt push Slack reminder failed.', [
                    'channel' => $channel,
                    'receipt_ids' => $receipts->pluck('id')->all(),
                    'exception' => $exception,
                ]);
            }
        } else {
            $result['errors'][] = 'Slack: inventory channel is not configured.';
        }

        $this->markReminded($receipts);

        return $result;
    }

    /**
     * @param Collection<int, ProcurementSupplierReceipt> $receipts
     */
    private function markReminded(Collection $receipts): void
    {
        ProcurementSupplierReceipt::query()
            ->whereIn('id', $receipts->pluck('id'))
            ->whereNull('pending_push_reminded_at')
            ->update(['pending_push_reminded_at' => now()]);
    }
}
