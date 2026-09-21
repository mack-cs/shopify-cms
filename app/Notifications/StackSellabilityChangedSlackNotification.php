<?php

namespace App\Notifications;

use App\Filament\Resources\InventoryResource;
use App\Services\SlackUserResolver;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;

class StackSellabilityChangedSlackNotification extends Notification
{
    /**
     * @param array<string, mixed> $summary
     */
    public function __construct(
        private readonly array $summary,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $resolver = app(SlackUserResolver::class);
        $changes = collect($this->summary['changes'] ?? [])
            ->filter(fn ($change): bool => is_array($change))
            ->values();

        $disabledCount = $changes->where('action', 'disabled')->count();
        $restoredCount = $changes->where('action', 'restored')->count();
        $pushProducts = (int) ($this->summary['shopify_push_queued_products'] ?? 0);
        $pushVariants = (int) ($this->summary['shopify_push_queued_variants'] ?? 0);
        $source = $resolver->escape($this->summary['source'] ?? 'Inventory update');
        $inventoryUrl = $this->absoluteUrl(InventoryResource::getUrl('index'));
        $pushLabel = $pushVariants > 0
            ? "Queued ({$pushProducts} " . Str::plural('product', $pushProducts) . ', ' . $pushVariants . ' ' . Str::plural('variant', $pushVariants) . ')'
            : 'Not queued';

        $lines = $changes
            ->take(8)
            ->map(fn (array $change): string => $this->changeLine($change, $resolver))
            ->filter()
            ->implode("\n");

        if ($changes->count() > 8) {
            $remaining = $changes->count() - 8;
            $lines .= "\n- plus {$remaining} more stack " . Str::plural('change', $remaining);
        }

        $header = $disabledCount > 0
            ? 'Stack inventory action needed'
            : 'Stack sellability restored';

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => $header,
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Source:*\n{$source}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Turned off:*\n{$disabledCount}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Restored:*\n{$restoredCount}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Shopify push:*\n{$pushLabel}",
                    ],
                ],
            ],
        ];

        if ($lines !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => Str::limit($lines, 2800),
                ],
            ];
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => $pushVariants > 0
                        ? 'The stack was changed locally in Shopify Editor and a Shopify inventory push was queued. Open Inventory to review or adjust stock.'
                        : 'The stack was changed locally in Shopify Editor. Open Inventory to review and push the local change to Shopify.',
                ],
            ],
        ];

        $blocks[] = [
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Inventory',
                    ],
                    'url' => $inventoryUrl,
                ],
            ],
        ];

        return (new SlackMessage)
            ->text($header)
            ->usingBlockKitTemplate(json_encode(['blocks' => $blocks], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    /**
     * @param array<string, mixed> $change
     */
    private function changeLine(array $change, SlackUserResolver $resolver): string
    {
        $stack = is_array($change['stack'] ?? null) ? $change['stack'] : [];
        $component = is_array($change['component'] ?? null) ? $change['component'] : null;
        $stackName = $resolver->escape($stack['title'] ?? 'Unknown stack');
        $stackSku = trim((string) ($stack['sku'] ?? ''));
        $stackLabel = $stackSku !== '' ? "{$stackName} ({$resolver->escape($stackSku)})" : $stackName;

        if (($change['action'] ?? null) === 'restored') {
            return "- *Stack restored:* {$stackLabel}. All associated products are now sellable; the stack has been returned to untracked inventory locally.";
        }

        if ($component === null) {
            return "- *Stack turned off:* {$stackLabel}. A linked component is not sellable.";
        }

        $componentName = $resolver->escape($component['title'] ?? 'Unknown component');
        $componentSku = trim((string) ($component['sku'] ?? ''));
        $componentLabel = $componentSku !== ''
            ? "{$componentName} ({$resolver->escape($componentSku)})"
            : $componentName;
        $reason = $resolver->escape($component['reason'] ?? 'Component is not sellable');
        $stock = array_key_exists('current_stock', $component) && $component['current_stock'] !== null
            ? ' Current stock: ' . (int) $component['current_stock'] . '.'
            : '';

        return "- *Stack turned off:* {$stackLabel}. Reason: {$componentLabel} is not sellable. {$reason}.{$stock}";
    }

    private function absoluteUrl(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
