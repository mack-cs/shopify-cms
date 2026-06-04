<?php

namespace App\Notifications;

use App\Filament\Resources\NewProductDraftResource;
use App\Models\NewProductDraftAssignment;
use App\Models\User;
use App\Services\NewProductDraftAssignmentService;
use App\Services\SlackUserResolver;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;

class NewProductDraftAssignmentSlackNotification extends Notification
{
    public function __construct(
        private readonly int $assignmentId,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $assignment = NewProductDraftAssignment::query()
            ->with(['items', 'sender'])
            ->find($this->assignmentId);

        if (!$assignment instanceof NewProductDraftAssignment) {
            return (new SlackMessage)->text("Assignment #{$this->assignmentId} could not be loaded.");
        }

        $resolver = app(SlackUserResolver::class);
        $columnService = app(NewProductDraftAssignmentService::class);
        $mentions = $this->assignmentMentions($assignment, $resolver);
        $subject = $resolver->escape($assignment->subject ?: 'New product draft assignment');
        $body = $resolver->escape($assignment->body ?? '');

        $selectedColumns = collect($assignment->selected_columns ?? [])
            ->map(fn (string $key): string => $columnService->labelForColumn($key))
            ->implode(', ');

        $draftLines = $assignment->items
            ->take(8)
            ->map(function ($item) use ($resolver): string {
                $title = $resolver->escape($item->title ?: 'Untitled draft');
                $handle = $resolver->escape($item->handle ?: 'no-handle');

                return "- {$title} ({$handle})";
            })
            ->implode("\n");

        if ($assignment->items->count() > 8) {
            $remaining = $assignment->items->count() - 8;
            $draftLines .= "\n- plus {$remaining} more";
        }

        $draftsUrl = NewProductDraftResource::getUrl('index');
        if (!str_starts_with($draftsUrl, 'http')) {
            $draftsUrl = url($draftsUrl);
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'New product draft assignment',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => trim("{$mentions}\n*{$subject}*"),
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Assignment ID:*\n#{$assignment->id}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Drafts:*\n{$assignment->items->count()}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Columns:*\n" . Str::limit($resolver->escape($selectedColumns ?: 'Not specified'), 900),
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Sent by:*\n" . $resolver->escape($assignment->sender?->name ?: $assignment->from_name ?: 'Shopify Editor'),
                    ],
                ],
            ],
        ];

        if ($body !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Message:*\n" . Str::limit($body, 1800),
                ],
            ];
        }

        if ($draftLines !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Drafts to update:*\n" . Str::limit($draftLines, 2500),
                ],
            ];
        }

        $blocks[] = [
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Drafts',
                    ],
                    'url' => $draftsUrl,
                ],
            ],
        ];

        return (new SlackMessage)
            ->text("New product draft assignment #{$assignment->id}")
            ->usingBlockKitTemplate(json_encode(['blocks' => $blocks], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    private function assignmentMentions(NewProductDraftAssignment $assignment, SlackUserResolver $resolver): string
    {
        $assignedUserIds = collect($assignment->assigned_user_ids ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($assignedUserIds !== []) {
            $mentions = User::query()
                ->whereIn('id', $assignedUserIds)
                ->get()
                ->map(fn (User $user): string => $resolver->mentionForUser($user) ?? $resolver->mentionOrEmailForEmail($user->email))
                ->filter()
                ->values();

            if ($mentions->isNotEmpty()) {
                return $mentions->implode(' ');
            }
        }

        return collect($assignment->to_emails ?? [])
            ->map(fn (string $email): string => $resolver->mentionOrEmailForEmail($email))
            ->filter()
            ->implode(' ');
    }
}
