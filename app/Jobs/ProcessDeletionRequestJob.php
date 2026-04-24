<?php

namespace App\Jobs;

use App\Models\ChangeLog;
use App\Models\DeletionRequest;
use App\Models\NewProductDraft;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Services\AdminNotification;
use App\Services\ShopifyDeletionService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDeletionRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public int $deletionRequestId,
        public ?int $completedByUserId = null,
    ) {}

    public function handle(ShopifyDeletionService $shopifyDeletionService): void
    {
        $request = DeletionRequest::query()
            ->with('approvals')
            ->find($this->deletionRequestId);

        if (!$request) {
            return;
        }

        if ($request->status === DeletionRequest::STATUS_COMPLETED) {
            return;
        }

        try {
            $record = $this->findRecord($request);
            $shopifyId = trim((string) ($request->shopify_id ?: $record?->shopify_id ?? ''));
            if ($shopifyId !== '') {
                match ($request->entity_type) {
                    'product', 'draft' => $shopifyDeletionService->deleteProduct($shopifyId),
                    'collection' => $shopifyDeletionService->deleteCollection($shopifyId),
                    default => throw new \RuntimeException('Unsupported deletion target.'),
                };
            }

            $this->cleanupLocalData($request, $record);

            $this->createAuditLog($request, 'deletion_completed', $this->completedByUserId, [
                'status' => 'completed',
                'entity_type' => $request->entity_type,
                'title' => $request->entity_title,
                'handle' => $request->entity_handle,
                'shopify_id' => $request->shopify_id,
            ]);

            $request->forceFill([
                'status' => DeletionRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'completed_by' => $this->completedByUserId,
                'failure_message' => null,
            ])->save();

            $this->notify($request, true);
        } catch (\Throwable $e) {
            Log::error('Deletion request failed.', [
                'deletion_request_id' => $request->id,
                'entity_type' => $request->entity_type,
                'entity_handle' => $request->entity_handle,
                'message' => $e->getMessage(),
            ]);

            $request->forceFill([
                'status' => DeletionRequest::STATUS_FAILED,
                'failure_message' => $e->getMessage(),
            ])->save();

            $this->createAuditLog($request, 'deletion_failed', $this->completedByUserId, [
                'status' => 'failed',
                'message' => $e->getMessage(),
                'entity_type' => $request->entity_type,
                'title' => $request->entity_title,
                'handle' => $request->entity_handle,
                'shopify_id' => $request->shopify_id,
            ]);

            $this->notify($request, false, $e->getMessage());

            throw $e;
        }
    }

    private function findRecord(DeletionRequest $request): ?Model
    {
        $modelClass = $request->deletable_type;
        if (!is_string($modelClass) || !class_exists($modelClass)) {
            return null;
        }

        $model = new $modelClass();
        if (!$model instanceof Model) {
            return null;
        }

        return $modelClass::query()->find($request->deletable_id);
    }

    private function cleanupLocalData(DeletionRequest $request, ?Model $record): void
    {
        if (in_array($request->entity_type, ['product', 'draft'], true)) {
            $importId = $request->import_id;
            $handle = trim((string) ($request->entity_handle ?? ''));

            if ($importId && $handle !== '') {
                ShopifyRow::query()
                    ->where('import_id', $importId)
                    ->where('handle', $handle)
                    ->delete();

                ShopifyMetafield::query()
                    ->where('import_id', $importId)
                    ->where('handle', $handle)
                    ->delete();
            }
        }

        if ($request->entity_type === 'draft') {
            $product = $record instanceof NewProductDraft
                ? $record->product()->first()
                : null;

            if ($product) {
                $product->delete();
            }
        }

        if ($record) {
            $record->delete();
        }
    }

    private function createAuditLog(DeletionRequest $request, string $field, ?int $changedBy, array $payload): void
    {
        ChangeLog::create([
            'import_id' => $request->import_id,
            'product_id' => $request->entity_type === 'product' ? (int) $request->deletable_id : null,
            'changed_by' => $changedBy,
            'model_type' => $request->deletable_type,
            'model_id' => $request->deletable_id,
            'field' => $field,
            'old_value' => null,
            'new_value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function notify(DeletionRequest $request, bool $success, ?string $error = null): void
    {
        $recipientIds = $request->approvals()
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($recipientIds as $userId) {
            $notification = Notification::make()
                ->title($success ? 'Deletion complete' : 'Deletion failed')
                ->body($success
                    ? ucfirst($request->entity_type) . ' deletion completed for ' . ($request->entity_title ?: $request->entity_handle ?: 'record') . '.'
                    : 'Deletion failed: ' . ($error ?: 'Unknown error.'));

            $success ? $notification->success() : $notification->danger();

            AdminNotification::sendToUserId($notification, (int) $userId);
        }
    }
}
