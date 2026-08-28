<?php

namespace App\Notifications;

use App\Filament\Resources\SiteAuditResultResource;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;

final class Url404SlackReminderNotification extends Notification
{
    /** @param array<int, array<string, mixed>> $results */
    public function __construct(private readonly array $results)
    {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $count = count($this->results);
        $lines = collect($this->results)
            ->take(20)
            ->map(fn (array $result): string => '- <' . $result['url'] . '|' . $result['url'] . '>')
            ->implode("\n");
        $auditUrl = $this->absoluteUrl(SiteAuditResultResource::getUrl('latest'));

        return (new SlackMessage)
            ->text('404 URLs need attention')
            ->usingBlockKitTemplate(json_encode(['blocks' => [
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => '404 URLs need attention'],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "Your daily task reminder: *{$count} URLs* returned HTTP 404. Only 404s are included.\n" . Str::limit($lines, 2600),
                    ],
                ],
                [
                    'type' => 'actions',
                    'elements' => [[
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Open Site Audit'],
                        'url' => $auditUrl,
                    ]],
                ],
            ]], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    private function absoluteUrl(string $url): string
    {
        return Str::startsWith($url, ['http://', 'https://'])
            ? $url
            : rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
    }
}
