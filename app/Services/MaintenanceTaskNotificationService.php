<?php

namespace App\Services;

use App\Mail\MissingAltTextReportMail;
use App\Mail\Url404ReportMail;
use App\Models\Product;
use App\Models\SiteAuditResult;
use App\Models\SiteAuditRun;
use App\Notifications\MissingAltTextSlackReminderNotification;
use App\Notifications\Url404SlackReminderNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

final class MaintenanceTaskNotificationService
{
    /** @return array{missing_alt:int,url_404:int,slack_messages:int} */
    public function sendDailySlackReminders(): array
    {
        $messages = 0;
        $missingAlt = Product::query()->activeMissingImageAltText()->count();

        if ($missingAlt > 0) {
            $recipient = trim((string) config('services.slack.missing_alt_recipient_user_id'));
            if ($recipient !== '') {
                Notification::route('slack', $recipient)
                    ->notify(new MissingAltTextSlackReminderNotification());
                $messages++;
            }
        }

        $results = $this->latest404Results();
        if ($results !== []) {
            foreach ((array) config('services.slack.url_404_recipient_user_ids', []) as $recipient) {
                if (trim((string) $recipient) === '') {
                    continue;
                }

                Notification::route('slack', trim((string) $recipient))
                    ->notify(new Url404SlackReminderNotification($results));
                $messages++;
            }
        }

        return ['missing_alt' => $missingAlt, 'url_404' => count($results), 'slack_messages' => $messages];
    }

    /** @return array{missing_alt:int,url_404:int,missing_alt_email_sent:bool,url_404_email_sent:bool} */
    public function sendMonthlyReports(): array
    {
        $results = $this->latest404Results();
        $missingAltProducts = $this->missingAltTextProducts();
        $missingAltEmail = trim((string) config('services.slack.missing_alt_recipient_email'));
        $missingAltEmailSent = $missingAltProducts !== [] && $missingAltEmail !== '';

        if ($missingAltEmailSent) {
            Mail::to($missingAltEmail)->send(new MissingAltTextReportMail($missingAltProducts));
        }

        $emails = (array) config('services.slack.url_404_recipient_emails', []);
        $url404EmailSent = $results !== [] && $emails !== [];
        if ($url404EmailSent) {
            Mail::to($emails)->send(new Url404ReportMail($results));
        }

        return [
            'missing_alt' => count($missingAltProducts),
            'url_404' => count($results),
            'missing_alt_email_sent' => $missingAltEmailSent,
            'url_404_email_sent' => $url404EmailSent,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function missingAltTextProducts(): array
    {
        return Product::query()
            ->activeMissingImageAltText()
            ->withCount([
                'images as missing_image_alt_text_count' => fn (Builder $query): Builder => Product::applyMissingImageAltTextImageFilter($query),
            ])
            ->orderBy('title')
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'title' => trim((string) $product->title) ?: "Product #{$product->id}",
                'handle' => (string) ($product->handle ?? ''),
                'missing_image_alt_text_count' => (int) ($product->missing_image_alt_text_count ?? 0),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function latest404Results(): array
    {
        $run = SiteAuditRun::query()
            ->where('status', SiteAuditRun::STATUS_COMPLETED)
            ->latest('completed_at')
            ->latest('id')
            ->first();

        if (!$run instanceof SiteAuditRun) {
            return [];
        }

        return SiteAuditResult::query()
            ->with('siteAuditUrl')
            ->where('site_audit_run_id', $run->id)
            ->where('status_code', 404)
            ->get()
            ->map(fn (SiteAuditResult $result): array => [
                'url' => (string) ($result->siteAuditUrl?->url ?? $result->final_url ?? ''),
                'final_url' => (string) ($result->final_url ?? ''),
                'shopify_resource_status' => (string) ($result->shopify_resource_status ?? ''),
            ])
            ->filter(fn (array $result): bool => $result['url'] !== '')
            ->values()
            ->all();
    }
}
