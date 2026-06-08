<?php

namespace App\Notifications;

use App\Filament\Resources\ProductPartialApprovalRequestResource;
use App\Filament\Resources\ProductResource;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use App\Services\ProductPartialApprovalService;
use App\Services\SlackUserResolver;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;

class ProductPartialApprovalSlackReminderNotification extends Notification
{
    /**
     * @param array<int, int> $requestIds
     */
    public function __construct(
        private readonly ?int $targetApproverId,
        private readonly array $requestIds,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $resolver = app(SlackUserResolver::class);
        $service = app(ProductPartialApprovalService::class);

        $requests = ProductPartialApprovalRequest::query()
            ->with(['product', 'requester', 'targetApprover'])
            ->whereIn('id', $this->requestIds)
            ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->get();

        if ($requests->isEmpty()) {
            return (new SlackMessage)->text('There are no pending partial approval requests.');
        }

        $requestCount = $requests->count();
        $targetApprover = $this->targetApproverId
            ? User::query()->find($this->targetApproverId)
            : null;

        $mention = $targetApprover instanceof User
            ? ($resolver->mentionForUser($targetApprover) ?? $resolver->escape($targetApprover->name ?: $targetApprover->email))
            : 'Any eligible reviewer';

        $queueUrl = $this->absoluteUrl(ProductPartialApprovalRequestResource::getUrl('index'));

        $requestLines = $requests
            ->take(12)
            ->map(function (ProductPartialApprovalRequest $request) use ($resolver, $service): string {
                $product = $request->product;
                $title = $resolver->escape($product?->title ?: 'Product #' . $request->product_id);
                $handle = $resolver->escape($product?->handle ?: 'no-handle');
                $fields = $resolver->escape(implode(', ', $service->requestFieldLabels(
                    is_array($request->scopes) ? $request->scopes : [],
                    is_array($request->core_fields) ? $request->core_fields : [],
                )) ?: 'Selected fields');
                $requester = $resolver->escape($request->requester?->name ?: 'Unknown requester');
                $productUrl = $product ? $this->absoluteUrl(ProductResource::getUrl('edit', ['record' => $product])) : null;
                $label = $productUrl ? "<{$productUrl}|{$title}>" : $title;

                return "- {$label} ({$handle}) - {$fields} - requested by {$requester}";
            })
            ->implode("\n");

        if ($requests->count() > 12) {
            $remaining = $requestCount - 12;
            $requestLines .= "\n- plus {$remaining} more";
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Partial approvals waiting',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "{$mention}, there " . ($requestCount === 1 ? 'is' : 'are') . " *{$requestCount}* partial approval " . Str::plural('request', $requestCount) . ' waiting for review.',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => Str::limit($requestLines, 2800),
                ],
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'Open Partial Approval Queue',
                        ],
                        'url' => $queueUrl,
                    ],
                ],
            ],
        ];

        return (new SlackMessage)
            ->text('Partial approvals waiting for review')
            ->usingBlockKitTemplate(json_encode(['blocks' => $blocks], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    private function absoluteUrl(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
