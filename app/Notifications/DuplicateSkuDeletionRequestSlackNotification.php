<?php

namespace App\Notifications;

use App\Filament\Resources\DeletionRequestResource;
use App\Models\DeletionRequest;
use App\Models\User;
use App\Services\SlackUserResolver;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;

final class DuplicateSkuDeletionRequestSlackNotification extends Notification
{
    /**
     * @param array<int, int> $requestIds
     */
    public function __construct(
        private readonly array $requestIds,
        private readonly int $targetApproverId,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $resolver = app(SlackUserResolver::class);
        $approver = User::query()->find($this->targetApproverId);
        $mention = $approver instanceof User
            ? ($resolver->mentionForUser($approver) ?? $resolver->mentionOrEmailForEmail($approver->email))
            : 'Assigned approver';

        $requests = DeletionRequest::query()
            ->whereIn('id', $this->requestIds)
            ->where('target_approver_id', $this->targetApproverId)
            ->where('status', DeletionRequest::STATUS_PENDING)
            ->orderBy('id')
            ->get();

        $lines = $requests
            ->take(10)
            ->map(function (DeletionRequest $request) use ($resolver): string {
                $title = $resolver->escape(
                    $request->entity_title ?: $request->entity_handle ?: "Request #{$request->id}"
                );
                $handle = trim((string) $request->entity_handle);
                $handleText = $handle === '' ? '' : ' — ' . $resolver->escape($handle);

                return "• *{$title}*{$handleText}";
            })
            ->implode("\n");

        if ($requests->count() > 10) {
            $remaining = $requests->count() - 10;
            $lines .= "\n• plus {$remaining} more " . Str::plural('request', $remaining);
        }

        $queueUrl = DeletionRequestResource::getUrl('index', [
            'activeTab' => 'assigned_to_me',
        ]);
        if (!Str::startsWith($queueUrl, ['http://', 'https://'])) {
            $queueUrl = url($queueUrl);
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Archived product deletions awaiting review',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "{$mention}, *{$requests->count()}* archived duplicate-SKU "
                        . Str::plural('product deletion request', $requests->count())
                        . ' were assigned to you. Review them when ready; no product is deleted unless you approve.',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $lines,
                ],
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'Open My Pending Deletions',
                        ],
                        'url' => $queueUrl,
                    ],
                ],
            ],
        ];

        return (new SlackMessage)
            ->text('Archived product deletion requests are awaiting review')
            ->usingBlockKitTemplate(json_encode(['blocks' => $blocks], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }
}
