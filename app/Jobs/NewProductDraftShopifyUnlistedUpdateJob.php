<?php

namespace App\Jobs;

use App\Models\NewProductDraft;
use App\Services\AdminNotification;
use App\Services\AsyncJobStateService;
use App\Services\NewProductDraftProductSync;
use App\Services\NewProductDraftShopifyCreator;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NewProductDraftShopifyUnlistedUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    /**
     * @param  array<int, int>  $draftIds
     */
    public function __construct(
        private readonly array $draftIds,
        private readonly int $userId,
    ) {}

    public function handle(
        NewProductDraftShopifyCreator $creator,
        NewProductDraftProductSync $draftProductSync,
    ): void {
        try {
            set_time_limit(0);

            $drafts = NewProductDraft::query()->whereIn('id', $this->draftIds)->get();
            if ($drafts->isEmpty()) {
                return;
            }

            $result = $creator->updateUnlistedDrafts($drafts);
            if ($result['updated'] > 0) {
                $draftProductSync->syncApprovedDrafts(
                    NewProductDraft::query()->whereIn('id', $this->draftIds)->get()
                );
            }

            $parts = [];
            if ($result['updated'] > 0) {
                $parts[] = "Updated {$result['updated']}.";
            }
            if ($result['skipped_not_unlisted'] > 0) {
                $parts[] = "Not an unlisted launch: {$result['skipped_not_unlisted']}.";
            }
            if ($result['skipped_not_in_shopify'] > 0) {
                $parts[] = "Not in Shopify yet: {$result['skipped_not_in_shopify']}.";
            }
            if ($result['failed'] > 0) {
                $parts[] = "Failed: {$result['failed']}.";
            }

            $details = collect(array_merge($result['failures'], $result['warnings']))
                ->take(5)
                ->map(function (array $row): string {
                    $label = $row['title'] ?? ('Draft #'.($row['id'] ?? 'unknown'));
                    $extra = $row['details'] ? " ({$row['details']})" : '';

                    return "{$label}: {$row['reason']}{$extra}";
                })
                ->implode(' | ');

            $body = $parts === [] ? 'No drafts were updated.' : implode(' ', $parts);
            if ($details !== '') {
                $body .= ' '.$details;
            }

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Unlisted update complete')
                    ->body($body)
                    ->when($result['failed'] > 0, fn (Notification $notification) => $notification->warning())
                    ->when($result['failed'] === 0, fn (Notification $notification) => $notification->success()),
                $this->userId
            );
        } catch (\Throwable $e) {
            Log::error('Unlisted Shopify update job failed.', [
                'error' => $e->getMessage(),
                'draft_ids' => $this->draftIds,
            ]);

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Unlisted update failed')
                    ->body($e->getMessage())
                    ->danger(),
                $this->userId
            );

            throw $e;
        } finally {
            app(AsyncJobStateService::class)->markFinished(AsyncJobStateService::NEW_PRODUCT_SHOPIFY_UNLISTED_UPDATE);
        }
    }
}
