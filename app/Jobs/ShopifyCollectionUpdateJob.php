<?php

namespace App\Jobs;

use App\Models\ShopifyCollection;
use App\Models\User;
use App\Services\ShopifyCollectionUpdater;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ShopifyCollectionUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    /**
     * @param array<int, int> $collectionIds
     * @param array<int, string> $fieldNames
     */
    public function __construct(
        public array $collectionIds,
        public array $fieldNames,
        public ?int $userId = null,
        public ?string $handleOverride = null,
        public bool $hasDeindexOverride = false,
        public ?bool $deindexOverride = null,
    ) {}

    public function handle(ShopifyCollectionUpdater $updater): void
    {
        $collections = ShopifyCollection::query()
            ->whereIn('id', $this->collectionIds)
            ->get();

        $fields = array_fill_keys($this->fieldNames, true);
        $synced = 0;
        $skippedNotApproved = 0;
        $skippedNoChanges = 0;
        $failed = 0;
        $failureMessages = [];

        foreach ($collections as $collection) {
            if (!$collection->isApprovedByTwo()) {
                $skippedNotApproved++;
                continue;
            }

            if ($this->hasDeindexOverride) {
                ShopifyCollection::withoutEvents(function () use ($collection): void {
                    $collection->forceFill([
                        'deindex' => $this->deindexOverride,
                    ])->save();
                });

                $collection->refresh();
            }

            $payload = $this->collectionPayload($collection, $fields);
            if ($payload === []) {
                $skippedNoChanges++;
                continue;
            }

            try {
                $updater->update($collection, $payload);
                ShopifyCollection::withoutEvents(function () use ($collection): void {
                    $collection->forceFill([
                        'sync_status' => ShopifyCollection::SYNC_STATUS_SYNCED,
                        'last_synced_at' => now(),
                    ])->save();
                });
                $synced++;
            } catch (\Throwable $e) {
                $failed++;
                $failureMessages[] = $e->getMessage();

                Log::error('Queued collection push to Shopify failed.', [
                    'collection_id' => $collection->id,
                    'shopify_id' => $collection->shopify_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->notifyResult($synced, $skippedNotApproved, $skippedNoChanges, $failed, $failureMessages);
    }

    /**
     * @param array<string, bool> $fields
     * @return array<string, mixed>
     */
    private function collectionPayload(ShopifyCollection $collection, array $fields): array
    {
        $payload = [];

        if (!empty($fields['title'])) {
            $payload['title'] = $collection->title;
        }

        if (!empty($fields['description_html'])) {
            $payload['description_html'] = $collection->description_html;
        }

        if ($this->handleOverride !== null && trim($this->handleOverride) !== '') {
            $payload['handle'] = trim($this->handleOverride);
        }

        if (!empty($fields['seo_title'])) {
            $payload['seo_title'] = $collection->seo_title;
        }

        if (!empty($fields['seo_description'])) {
            $payload['seo_description'] = $collection->seo_description;
        }

        if (!empty($fields['deindex']) && $collection->deindex !== null) {
            $payload['deindex'] = (bool) $collection->deindex;
        }

        return $payload;
    }

    /**
     * @param array<int, string> $failureMessages
     */
    private function notifyResult(
        int $synced,
        int $skippedNotApproved,
        int $skippedNoChanges,
        int $failed,
        array $failureMessages,
    ): void {
        if (!$this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (!$user) {
            return;
        }

        $parts = ["Synced {$synced} collection(s)."];

        if ($skippedNotApproved > 0) {
            $parts[] = "Skipped not approved: {$skippedNotApproved}.";
        }

        if ($skippedNoChanges > 0) {
            $parts[] = "Skipped with no changes: {$skippedNoChanges}.";
        }

        if ($failed > 0) {
            $parts[] = "Failed: {$failed}.";

            $sampleFailures = collect($failureMessages)->filter()->unique()->take(2)->values();
            if ($sampleFailures->isNotEmpty()) {
                $parts[] = 'Errors: ' . $sampleFailures->implode(' | ');
            }
        }

        $notification = Notification::make()
            ->title('Collection sync complete')
            ->body(implode(' ', $parts));

        if ($failed > 0) {
            $notification->danger();
        } elseif ($synced === 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->sendToDatabase($user);
    }
}
