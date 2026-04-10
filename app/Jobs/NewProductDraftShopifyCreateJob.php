<?php

namespace App\Jobs;

use App\Models\NewProductDraft;
use App\Models\Import;
use App\Services\AdminNotification;
use App\Services\NewProductDraftShopifyCreator;
use App\Services\ShopifyApiImporter;
use App\Jobs\ShopifySyncJob;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NewProductDraftShopifyCreateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    /**
     * @param array<int, int> $draftIds
     */
    public function __construct(
        private readonly array $draftIds,
        private readonly int $userId,
    ) {
    }

    public function handle(NewProductDraftShopifyCreator $creator, ShopifyApiImporter $importer): void
    {
        set_time_limit(0);

        $drafts = NewProductDraft::whereIn('id', $this->draftIds)->get();
        if ($drafts->isEmpty()) {
            return;
        }

        try {
            $result = $creator->createApprovedDrafts($drafts);

            if (!empty($result['failures'])) {
                Log::warning('New product draft Shopify create failures.', [
                    'count' => count($result['failures']),
                    'failures' => $result['failures'],
                ]);
            }
            if (!empty($result['warnings'])) {
                Log::warning('New product draft Shopify create warnings.', [
                    'count' => count($result['warnings']),
                    'warnings' => $result['warnings'],
                ]);
            }

            $parts = [];
            if ($result['created'] > 0) {
                $parts[] = "Created {$result['created']}.";
            }
            if ($result['skipped_has_handle'] > 0) {
                $parts[] = "Already has handle: {$result['skipped_has_handle']}.";
            }
            if ($result['skipped_not_approved'] > 0) {
                $parts[] = "Not approved: {$result['skipped_not_approved']}.";
            }
            if ($result['failed'] > 0) {
                $parts[] = "Failed: {$result['failed']}.";
            }

            $failureSummary = null;
            if (!empty($result['failures'])) {
                $lines = array_slice($result['failures'], 0, 5);
                $failureSummary = collect($lines)
                    ->map(function (array $failure): string {
                        $label = $failure['title'] ?? ('Draft #' . ($failure['id'] ?? 'unknown'));
                        $details = $failure['details'] ? " ({$failure['details']})" : '';
                        return "{$label}: {$failure['reason']}{$details}";
                    })
                    ->implode(' | ');
            }

            $syncQueued = false;
            if ($result['created'] > 0) {
                $hasCredentials = config('services.shopify.shop') && config('services.shopify.admin_access_token');
                $current = Import::where('is_current', true)->first();
                $syncAlreadyRunning = $current && $current->status === 'processing';

                if ($hasCredentials && !$syncAlreadyRunning) {
                    $import = $importer->createOrReuseCurrentImport($this->userId);
                    ShopifySyncJob::dispatch($import->id);
                    $syncQueued = true;
                }
            }

            $bodyParts = [];
            $bodyParts[] = $parts ? implode(' ', $parts) : 'No drafts were created.';
            if ($syncQueued) {
                $bodyParts[] = 'Sync queued to refresh Products.';
            }
            if ($failureSummary !== null) {
                $bodyParts[] = $failureSummary;
            }
            $body = implode(' ', $bodyParts);

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Shopify create complete')
                    ->body($body)
                    ->when($result['failed'] > 0, fn (Notification $n) => $n->warning())
                    ->when($result['failed'] === 0, fn (Notification $n) => $n->success()),
                $this->userId
            );
        } catch (\Throwable $e) {
            Log::error('New product draft Shopify create job failed.', [
                'error' => $e->getMessage(),
                'draft_ids' => $this->draftIds,
            ]);

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Shopify create failed')
                    ->body($e->getMessage())
                    ->danger(),
                $this->userId
            );

            throw $e;
        }
    }
}
