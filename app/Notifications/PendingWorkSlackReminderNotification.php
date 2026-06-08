<?php

namespace App\Notifications;

use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ShopifyAuditResource;
use App\Models\Image;
use App\Models\NewProductDraftAssignment;
use App\Models\Product;
use App\Models\ShopifyAudit;
use App\Models\User;
use App\Services\ComplementaryProductAuditService;
use App\Services\SlackUserResolver;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PendingWorkSlackReminderNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $resolver = app(SlackUserResolver::class);

        $openAssignmentCount = NewProductDraftAssignment::query()
            ->where('work_status', 'open')
            ->count();

        $assignments = NewProductDraftAssignment::query()
            ->where('work_status', 'open')
            ->latest()
            ->limit(10)
            ->get();

        $complementaryTarget = ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT;

        $complementaryGapCount = ShopifyAudit::query()
            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
            ->where('needs_attention', true)
            ->where('shopify_valid_count', '<', $complementaryTarget)
            ->count();

        $complementaryGaps = ShopifyAudit::query()
            ->with('product')
            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
            ->where('needs_attention', true)
            ->where('shopify_valid_count', '<', $complementaryTarget)
            ->orderBy('shopify_valid_count')
            ->orderByDesc('last_checked_at')
            ->limit(10)
            ->get();

        $productErrorCount = Product::query()
            ->where('has_errors', true)
            ->count();

        $imageErrorCount = Image::query()
            ->where(function ($query): void {
                $query
                    ->whereNotNull('backup_error')
                    ->orWhereNotNull('shopify_image_sync_error');
            })
            ->count();

        $failedJobCount = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->count()
            : 0;

        $assignmentLines = $assignments
            ->map(fn (NewProductDraftAssignment $assignment): string => $this->assignmentLine($assignment, $resolver))
            ->filter()
            ->implode("\n");

        $complementaryGapLines = $complementaryGaps
            ->map(fn (ShopifyAudit $audit): string => $this->complementaryGapLine($audit, $resolver))
            ->filter()
            ->implode("\n");

        $draftsUrl = $this->absoluteUrl(NewProductDraftResource::getUrl('index'));
        $auditUrl = $this->absoluteUrl(ShopifyAuditResource::getUrl('index'));
        $productUrl = $this->absoluteUrl(ProductResource::getUrl('index'));

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Shopify editor pending work',
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Open assignments:*\n{$openAssignmentCount}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Complementary gaps:*\n{$complementaryGapCount}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Product errors:*\n{$productErrorCount}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Image errors:*\n{$imageErrorCount}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Failed queue jobs:*\n{$failedJobCount}",
                    ],
                ],
            ],
        ];

        if ($assignmentLines !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Assignments waiting on people:*\n" . Str::limit($assignmentLines, 2800),
                ],
            ];
        }

        if ($complementaryGapLines !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Products below Shopify complementary target ({$complementaryTarget} active/sellable):*\n"
                        . Str::limit($complementaryGapLines, 2800),
                ],
            ];

            if ($complementaryGapCount > $complementaryGaps->count()) {
                $remaining = $complementaryGapCount - $complementaryGaps->count();

                $blocks[] = [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "Showing the first {$complementaryGaps->count()} complementary gap(s); open audits for {$remaining} more.",
                        ],
                    ],
                ];
            }
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
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Audits',
                    ],
                    'url' => $auditUrl,
                ],
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Products',
                    ],
                    'url' => $productUrl,
                ],
            ],
        ];

        return (new SlackMessage)
            ->text('Shopify editor pending work reminder')
            ->usingBlockKitTemplate(json_encode(['blocks' => $blocks], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    private function assignmentLine(NewProductDraftAssignment $assignment, SlackUserResolver $resolver): string
    {
        $mentions = $this->assignmentMentions($assignment, $resolver);
        $subject = $resolver->escape($assignment->subject ?: 'New product draft assignment');
        $age = $assignment->created_at?->diffForHumans() ?? 'unknown age';

        return "- #{$assignment->id} {$mentions} {$subject} ({$age})";
    }

    private function complementaryGapLine(ShopifyAudit $audit, SlackUserResolver $resolver): string
    {
        $product = $audit->product;
        $title = $resolver->escape($product?->title ?: 'Product #' . $audit->product_id);
        $handle = $resolver->escape($product?->handle ?: 'no-handle');
        $productUrl = $product instanceof Product
            ? $this->absoluteUrl(ProductResource::getUrl('edit', ['record' => $product]))
            : null;
        $label = $productUrl ? "<{$productUrl}|{$title}>" : $title;

        $target = ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT;
        $localTarget = ComplementaryProductAuditService::LOCAL_TARGET_COUNT;
        $shopifyValid = (int) ($audit->shopify_valid_count ?? 0);
        $shopifyCurrent = (int) ($audit->shopify_current_count ?? 0);
        $localValid = (int) ($audit->local_valid_count ?? 0);
        $missing = max(0, $target - $shopifyValid);

        $parts = [
            "Shopify {$shopifyValid}/{$target} active/sellable",
            "local {$localValid}/{$localTarget} eligible backups",
        ];

        if ($shopifyCurrent !== $shopifyValid) {
            $parts[] = "{$shopifyCurrent} Shopify ref(s) total";
        }

        if ($missing > 0) {
            $parts[] = 'needs ' . $missing . ' more active/sellable Shopify ' . Str::plural('ref', $missing);
        }

        $issues = $this->complementaryGapIssues($audit, $resolver);
        if ($issues !== '') {
            $parts[] = $issues;
        }

        return "- {$label} ({$handle}) - " . implode('; ', $parts);
    }

    private function complementaryGapIssues(ShopifyAudit $audit, SlackUserResolver $resolver): string
    {
        $details = is_array($audit->details) ? $audit->details : [];
        $issues = [];

        foreach (array_slice($details['shopify_ineligible'] ?? [], 0, 2) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = $this->complementaryStateLabel($item, $resolver);
            if ($label !== '') {
                $issues[] = 'invalid Shopify ref: ' . $label;
            }
        }

        if ($issues === []) {
            return '';
        }

        return 'issues: ' . implode(', ', $issues);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function complementaryStateLabel(array $item, SlackUserResolver $resolver): string
    {
        $label = trim((string) ($item['title'] ?? ''))
            ?: trim((string) ($item['handle'] ?? ''))
            ?: trim((string) ($item['gid'] ?? ''));

        if ($label === '') {
            return '';
        }

        $reason = trim((string) ($item['reason'] ?? ''));
        if ($reason !== '') {
            $label .= " ({$reason})";
        }

        return $resolver->escape($label);
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

    private function absoluteUrl(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
