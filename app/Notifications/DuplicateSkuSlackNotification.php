<?php

namespace App\Notifications;

use App\Filament\Resources\ProductResource;
use App\Services\SlackUserResolver;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;

final class DuplicateSkuSlackNotification extends Notification
{
    /**
     * @param array<int, array<string, mixed>> $conflicts
     */
    public function __construct(
        private readonly array $conflicts,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $resolver = app(SlackUserResolver::class);
        $mention = $this->recipientMention($resolver);
        $productCount = collect($this->conflicts)
            ->flatMap(fn (array $conflict): array => array_column($conflict['products'], 'id'))
            ->unique()
            ->count();

        $lines = collect($this->conflicts)
            ->take(12)
            ->map(function (array $conflict) use ($resolver): string {
                $products = collect($conflict['products'])
                    ->map(function (array $product) use ($resolver): string {
                        $title = $resolver->escape($product['title']);
                        $status = $resolver->escape($product['status']);

                        return "{$title} [{$status}]";
                    })
                    ->implode(' ↔ ');

                return '• *' . $resolver->escape($conflict['sku']) . "* — {$products}";
            })
            ->implode("\n");

        if (count($this->conflicts) > 12) {
            $remaining = count($this->conflicts) - 12;
            $lines .= "\n• plus {$remaining} more " . Str::plural('SKU conflict', $remaining);
        }

        $productsUrl = $this->absoluteUrl(ProductResource::getUrl('index'));
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Duplicate product SKUs need attention',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "{$mention}, the daily audit found *" . count($this->conflicts)
                        . '* duplicate ' . Str::plural('SKU', count($this->conflicts))
                        . "* across *{$productCount} products*. Please check which product should own each SKU.",
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => Str::limit($lines, 2800),
                ],
            ],
            [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => 'This is a read-only daily reminder. It will stop automatically when no SKU is shared by multiple products.',
                    ],
                ],
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'Open Products',
                        ],
                        'url' => $productsUrl,
                    ],
                ],
            ],
        ];

        return (new SlackMessage)
            ->text('Duplicate product SKUs need attention')
            ->usingBlockKitTemplate(json_encode(['blocks' => $blocks], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    private function recipientMention(SlackUserResolver $resolver): string
    {
        $slackUserId = trim((string) config('services.slack.duplicate_sku_recipient_user_id'));

        if (preg_match('/^[UW][A-Z0-9]+$/', $slackUserId)) {
            return "<@{$slackUserId}>";
        }

        return $resolver->mentionOrEmailForEmail(
            (string) config('services.slack.duplicate_sku_recipient_email')
        );
    }

    private function absoluteUrl(string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
    }
}
