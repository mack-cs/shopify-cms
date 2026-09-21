<?php

namespace App\Services;

use App\Mail\DuplicateSkuReminderMail;
use App\Notifications\DuplicateSkuSlackNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class DuplicateSkuReminderService
{
    public function __construct(
        private readonly DuplicateSkuAuditService $auditService,
    ) {
    }

    /**
     * @return array{
     *     conflict_count:int,
     *     product_count:int,
     *     email_sent:bool,
     *     slack_sent:bool,
     *     errors:array<int, string>
     * }
     */
    public function sendDailyReminder(): array
    {
        $conflicts = $this->auditService->findConflicts();
        $productCount = collect($conflicts)
            ->flatMap(fn (array $conflict): array => array_column($conflict['products'], 'id'))
            ->unique()
            ->count();

        $result = [
            'conflict_count' => count($conflicts),
            'product_count' => $productCount,
            'email_sent' => false,
            'slack_sent' => false,
            'errors' => [],
        ];

        if ($conflicts === []) {
            return $result;
        }

        $email = trim((string) config('services.slack.duplicate_sku_recipient_email'));
        $channel = trim((string) config('services.slack.channels.duplicate_skus'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::to($email)->send(new DuplicateSkuReminderMail($conflicts));
                $result['email_sent'] = true;
            } catch (Throwable $exception) {
                $result['errors'][] = 'Email: ' . $exception->getMessage();
                Log::error('Duplicate SKU reminder email failed.', [
                    'recipient' => $email,
                    'exception' => $exception,
                ]);
            }
        } else {
            $result['errors'][] = 'Email: DUPLICATE_SKU_RECIPIENT_EMAIL is not a valid address.';
        }

        if ($channel !== '') {
            try {
                Notification::route('slack', $channel)
                    ->notify(new DuplicateSkuSlackNotification($conflicts));
                $result['slack_sent'] = true;
            } catch (Throwable $exception) {
                $result['errors'][] = 'Slack: ' . $exception->getMessage();
                Log::error('Duplicate SKU Slack reminder failed.', [
                    'channel' => $channel,
                    'exception' => $exception,
                ]);
            }
        } else {
            $result['errors'][] = 'Slack: SLACK_DUPLICATE_SKU_CHANNEL, SLACK_AUDIT_CHANNEL, or SLACK_BOT_USER_DEFAULT_CHANNEL must be configured.';
        }

        return $result;
    }
}
