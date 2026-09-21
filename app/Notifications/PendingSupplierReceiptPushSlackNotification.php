<?php

namespace App\Notifications;

use App\Filament\Resources\InventoryResource;
use App\Models\ProcurementSupplierReceipt;
use App\Services\SlackUserResolver;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class PendingSupplierReceiptPushSlackNotification extends Notification
{
    /**
     * @param Collection<int, ProcurementSupplierReceipt> $receipts
     */
    public function __construct(
        private readonly Collection $receipts,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $resolver = app(SlackUserResolver::class);
        $count = $this->receipts->count();
        $lines = $this->receipts
            ->take(12)
            ->map(function (ProcurementSupplierReceipt $receipt) use ($resolver): string {
                $line = $receipt->line;
                $order = $line?->order?->order_number ?: 'Legacy order';
                $sku = $line?->sku ?: '-';
                $creator = $resolver->mentionForUser($receipt->createdBy)
                    ?? $resolver->escape($receipt->createdBy?->name ?: $receipt->createdBy?->email ?: 'Unknown user');
                $age = $receipt->created_at?->diffForHumans(null, true) ?: 'unknown age';

                return '• *'.$resolver->escape($sku).'* | Order *'.$resolver->escape($order)
                    .'* | GRV *'.$resolver->escape($receipt->grv_number ?: '-')
                    .'* | Qty '.$receipt->quantity_received
                    ." | staged by {$creator} {$age} ago";
            })
            ->implode("\n");

        if ($count > 12) {
            $remaining = $count - 12;
            $lines .= "\n• plus {$remaining} more pending " . Str::plural('receipt', $remaining);
        }

        $url = $this->absoluteUrl(InventoryResource::getUrl('index', ['activeTab' => 'orders']));
        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => 'Supplier receipts pending Shopify push'],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*{$count}* supplier ".Str::plural('receipt', $count).' has been staged for more than 30 minutes and is still pending push to Shopify.',
                ],
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => Str::limit($lines, 2800)],
            ],
            [
                'type' => 'actions',
                'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Open Supplier Orders'],
                    'url' => $url,
                ]],
            ],
        ];

        return (new SlackMessage)
            ->text('Supplier receipts pending Shopify push')
            ->usingBlockKitTemplate(json_encode(['blocks' => $blocks], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    private function absoluteUrl(string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
    }
}
