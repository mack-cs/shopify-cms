<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use App\Jobs\DispatchComponentPublicationOutboxJob;
use App\Jobs\SynchronizeStackInheritedImagesJob;
use App\Models\Image;
use App\Models\ImageAsset;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a confirmed component photograph and gives every dependent stack its own recoverable task.
 * Automatic fan-out runs only when images.component_stack_propagation is enabled.
 */
final class ComponentStackPropagation
{
    public function __construct(
        private readonly StackComponentCatalog $catalog,
        private readonly ComponentPhotographSelector $selector,
        private readonly ProductShopifyUpdater $updater,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('images.component_stack_propagation', false);
    }

    public function recordConfirmedPhotograph(Image $image, bool $shopifyEvidenceVerified = false, ?string $verifiedMediaId = null): void
    {
        if (! $this->enabled() || ! $shopifyEvidenceVerified) {
            return;
        }
        $image = Image::query()->useWritePdo()->with('imageAsset')->find($image->id);
        if (! $image instanceof Image) {
            return;
        }
        $product = Product::query()->useWritePdo()->find($image->product_id);
        $mediaId = trim((string) ($verifiedMediaId ?? $image->shopify_id));
        if ($mediaId === '' || $mediaId !== trim((string) $image->shopify_id)) {
            return;
        }
        if (! $product instanceof Product || $product->isStackProduct() || ! $this->selector->confirmed($image) || ! $product->isApprovedByTwo()) {
            return;
        }
        $selected = $this->selector->selected($product);
        if (! $selected instanceof Image || (int) $selected->id !== (int) $image->id) {
            return;
        }
        $asset = $image->imageAsset;
        if (! $asset instanceof ImageAsset || $asset->sha256 === '') {
            return;
        }

        $generationId = DB::transaction(function () use ($image, $product, $asset): int {
            $currentProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $candidates = $currentProduct->allImages()->active()->where('position', 1)->orderBy('id')->lockForUpdate()->get();
            $current = $candidates->count() === 1 ? $candidates->first() : null;
            if (! $current || (int) $current->id !== (int) $image->id || (int) $current->image_asset_id !== (int) $asset->id
                || $current->shopify_id !== $image->shopify_id || ! $this->selector->confirmed($current) || ! $currentProduct->isApprovedByTwo()) {
                throw new RuntimeException('Component selection changed before its publication revision could be recorded.');
            }
            $revision = $this->revisionForSelection((int) $product->id, (int) $image->id, (int) $asset->id);
            $existing = DB::table('component_photograph_generations')
                ->where('component_image_id', $image->id)
                ->where('image_asset_id', $asset->id)
                ->where('selection_revision', $revision)
                ->first();
            if ($existing) {
                DB::table('component_photograph_generations')->where('id', $existing->id)->update([
                    'status' => 'confirmed',
                    'shopify_media_id' => $image->shopify_id,
                    'asset_sha256' => $asset->sha256,
                    'confirmed_at' => $existing->confirmed_at ?? now(),
                    'updated_at' => now(),
                ]);
                $id = (int) $existing->id;
            } else {
                DB::table('component_photograph_generations')->insertOrIgnore([
                    'component_product_id' => $product->id,
                    'component_image_id' => $image->id,
                    'image_asset_id' => $asset->id,
                    'asset_sha256' => $asset->sha256,
                    'shopify_media_id' => $image->shopify_id,
                    'selection_revision' => $revision,
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $id = (int) DB::table('component_photograph_generations')
                    ->where('component_image_id', $image->id)
                    ->where('image_asset_id', $asset->id)
                    ->where('selection_revision', $revision)
                    ->value('id');
            }
            DB::table('component_photograph_generations')
                ->where('component_product_id', $product->id)
                ->where('id', '!=', $id)
                ->where('status', 'confirmed')
                ->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('component_publication_outbox')->insertOrIgnore([
                'generation_id' => $id,
                'component_product_id' => $product->id,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });

        $outboxId = (int) DB::table('component_publication_outbox')->where('generation_id', $generationId)->value('id');
        DB::afterCommit(function () use ($outboxId): void {
            $this->dispatchOutbox($outboxId);
        });
    }

    public function reconcileComponentSelection(int $productId): void
    {
        if (! $this->enabled() || $productId < 1) {
            return;
        }
        $product = Product::query()->find($productId);
        if (! $product instanceof Product || $product->isStackProduct()) {
            return;
        }
        $selected = $this->selector->selected($product);
        if (! $selected instanceof Image || ! $this->selector->confirmed($selected)) {
            DB::table('component_photograph_generations')
                ->where('component_product_id', $product->id)
                ->where('status', 'confirmed')
                ->update(['status' => 'superseded', 'updated_at' => now()]);

            return;
        }
        DB::table('component_photograph_generations')
            ->where('component_product_id', $product->id)
            ->where('status', 'confirmed')
            ->where(function ($query) use ($selected): void {
                $query->where('component_image_id', '!=', $selected->id)
                    ->orWhere('image_asset_id', '!=', $selected->image_asset_id);
            })
            ->update(['status' => 'superseded', 'updated_at' => now()]);
        $this->recordConfirmedPhotograph($selected->fresh(['product', 'imageAsset']) ?? $selected);
    }

    public function dispatchOutbox(int $outboxId): void
    {
        try {
            DispatchComponentPublicationOutboxJob::dispatch($outboxId);
        } catch (\Throwable $exception) {
            DB::table('component_publication_outbox')->where('id', $outboxId)->update([
                'status' => 'pending',
                'last_error' => 'Queue dispatch failed: '.$exception->getMessage(),
                'available_at' => now()->addSeconds(30),
                'updated_at' => now(),
            ]);
        }
    }

    public function fanOut(int $outboxId): void
    {
        if (! $this->enabled()) {
            return;
        }
        $outbox = DB::table('component_publication_outbox')->where('id', $outboxId)->first();
        if (! $outbox || $outbox->status === 'dispatched') {
            return;
        }
        $generation = DB::table('component_photograph_generations')->where('id', $outbox->generation_id)->first();
        if (! $generation || $generation->status !== 'confirmed') {
            DB::table('component_publication_outbox')->where('id', $outboxId)->update([
                'status' => 'dispatched', 'dispatched_at' => now(), 'last_error' => 'Generation is no longer confirmed.', 'updated_at' => now(),
            ]);

            return;
        }
        $dispatchFailed = false;
        foreach ($this->catalog->stackIdsForComponent((int) $generation->component_product_id) as $stackId) {
            $stack = Product::query()->find((int) $stackId);
            $membership = $stack instanceof Product
                ? $this->membershipRevision($stack, $this->catalog->componentIds($stack), 'publication')
                : 0;
            $taskId = $this->ensureStackTask((int) $stackId, (int) $generation->id, 'publish', $generation, $membership);
            try {
                SynchronizeStackInheritedImagesJob::dispatch($taskId);
            } catch (\Throwable $exception) {
                $dispatchFailed = true;
                $this->deferTask($taskId, 'Queue dispatch failed: '.$exception->getMessage(), 'pending');
            }
        }
        DB::table('component_publication_outbox')->where('id', $outboxId)->update([
            'status' => $dispatchFailed ? 'pending' : 'dispatched',
            'attempts' => (int) $outbox->attempts + 1,
            'last_error' => $dispatchFailed ? 'One or more stack jobs were not dispatched. The task rows remain recoverable.' : null,
            'available_at' => $dispatchFailed ? now()->addSeconds(30) : null,
            'dispatched_at' => $dispatchFailed ? null : now(),
            'updated_at' => now(),
        ]);
    }

    public function synchronizeTask(int $taskId): void
    {
        if (! $this->enabled()) {
            return;
        }
        $task = DB::table('stack_image_sync_tasks')->where('id', $taskId)->first();
        if (! $task || in_array($task->phase, ['verified', 'superseded'], true)) {
            return;
        }
        if (($task->purpose ?? 'publish') === 'remove') {
            $this->removeInheritedComponent($task);

            return;
        }
        if ((int) $task->attempts >= 8) {
            $this->deferTask($taskId, $task->last_error ?: 'Retries exhausted. The task remains recoverable.', 'exhausted');

            return;
        }
        $generation = DB::table('component_photograph_generations')->where('id', $task->generation_id)->first();
        $latest = $this->latestConfirmed((int) $task->component_product_id);
        if ($latest && (int) $latest->id !== (int) $task->generation_id) {
            DB::table('stack_image_sync_tasks')->where('id', $taskId)->update([
                'phase' => 'superseded', 'last_error' => 'A newer confirmed component generation superseded this task.', 'updated_at' => now(),
            ]);
            $membership = (int) ($task->membership_revision ?? 0);
            $this->ensureStackTask((int) $task->stack_product_id, (int) $latest->id, 'publish', $latest, $membership);
            $this->dispatchTaskForGeneration((int) $task->stack_product_id, (int) $latest->id, $membership);

            return;
        }
        if (! $generation || $generation->status !== 'confirmed') {
            $this->deferTask($taskId, 'Component publication is not confirmed. The inherited image was not published.', 'blocked');

            return;
        }

        $stack = Product::query()->find($task->stack_product_id);
        $component = Product::query()->find($task->component_product_id);
        if (! $stack instanceof Product || ! $component instanceof Product) {
            $this->deferTask($taskId, 'The stack or component no longer exists.', 'failed');

            return;
        }
        $selected = $this->selector->selected($component);
        if (! $selected instanceof Image
            || (int) $selected->id !== (int) $generation->component_image_id
            || (int) $selected->image_asset_id !== (int) $generation->image_asset_id) {
            DB::table('stack_image_sync_tasks')->where('id', $taskId)->update([
                'phase' => 'superseded',
                'last_error' => 'The component photograph is no longer the current primary. This task was not published.',
                'updated_at' => now(),
            ]);

            return;
        }
        if (! in_array((int) $component->id, $this->catalog->componentIds($stack), true)) {
            $this->removeInheritedComponent($task);

            return;
        }
        if (! $this->selector->publicationAllowed($selected)) {
            $this->deferTask($taskId, 'The component generation is not approved and confirmed on Shopify. The inherited image was not published.', 'blocked');

            return;
        }
        $mutex = new ImageSyncMutex;
        if (! $mutex->acquire((int) $stack->id)) {
            $this->deferTask($taskId, 'Another image operation is running.', 'pending', 30);
            throw new RuntimeException('Another image operation is running for stack '.$stack->id.'.');
        }
        try {
            $mutex->assertOwned();
            $latest = $this->latestConfirmed((int) $task->component_product_id);
            if ($latest && (int) $latest->id !== (int) $generation->id) {
                DB::table('stack_image_sync_tasks')->where('id', $taskId)->update(['phase' => 'superseded', 'updated_at' => now()]);

                return;
            }
            DB::table('stack_image_sync_tasks')->where('id', $taskId)->update(['phase' => 'staging', 'updated_at' => now()]);
            $staged = $this->stageComponentOnStack($stack, $component, $generation);
            if ($staged['alt_conflict']) {
                $this->deferTask($taskId, 'Inherited alt text conflicts across components that share this photograph. No alt text was chosen.', 'alt_conflict');

                return;
            }
            $image = $staged['image'];
            DB::table('stack_image_sync_tasks')->where('id', $taskId)->update(['phase' => 'uploading', 'attempts' => (int) $task->attempts + 1, 'updated_at' => now()]);
        } finally {
            $mutex->release();
        }

        $result = $this->updater->synchronizeInheritedStackImage($stack, (int) $image->id);
        if (! $result['confirmed']) {
            $warning = collect($result['warnings'])->pluck('warning')->implode(' ');
            $phase = ProductShopifyUpdater::imageSyncWarningIsRetryable($warning) ? 'confirming' : 'failed';
            $this->deferTask($taskId, $warning !== '' ? $warning : 'Shopify did not confirm the inherited photograph.', $phase, 45);
            if ($phase === 'confirming') {
                throw new RuntimeException($warning !== '' ? $warning : 'Inherited photograph synchronization is not confirmed.');
            }

            return;
        }
        $altResult = app(ImageAltTextBackfillService::class)->publishInheritedCopy($image->fresh() ?? $image);
        if (($altResult['failed'] ?? []) !== []) {
            $warning = collect($altResult['failed'])->pluck('reason')->implode(' ');
            $this->deferTask($taskId, $warning !== '' ? $warning : 'The approved inherited description was not published.', 'failed', 45);

            return;
        }

        $mutex = new ImageSyncMutex;
        if (! $mutex->acquire((int) $stack->id)) {
            $this->deferTask($taskId, 'Another image operation is running.', 'pending', 30);
            throw new RuntimeException('Another image operation is running for stack '.$stack->id.'.');
        }
        try {
            $mutex->assertOwned();
            $latest = $this->latestConfirmed((int) $task->component_product_id);
            if (! $latest || (int) $latest->id !== (int) $generation->id) {
                DB::table('stack_image_sync_tasks')->where('id', $taskId)->update([
                    'phase' => 'superseded', 'last_error' => 'A newer generation arrived before completion. This task was not marked current.', 'updated_at' => now(),
                ]);
                if ($latest) {
                    $this->ensureStackTask((int) $stack->id, (int) $latest->id, 'publish', $latest, (int) ($task->membership_revision ?? 0));
                }

                return;
            }
            $image->refresh();
            DB::table('stack_image_sync_tasks')->where('id', $taskId)->update(['phase' => 'retiring', 'updated_at' => now()]);
        } finally {
            $mutex->release();
        }
        $this->retireOlderInherited($stack, $image, (int) $component->id, (string) $image->shopify_id, (int) $generation->id);
        $absent = DB::table('inherited_media_retirements')
            ->where('stack_product_id', $stack->id)
            ->where('generation_id', $generation->id)
            ->where('status', 'confirmed')
            ->pluck('old_media_id')
            ->filter()
            ->all();
        $mutex = new ImageSyncMutex;
        if (! $mutex->acquire((int) $stack->id)) {
            $this->deferTask($taskId, 'Another image operation is running.', 'pending', 30);
            throw new RuntimeException('Another image operation is running for stack '.$stack->id.'.');
        }
        try {
            $mutex->assertOwned();
            $latest = $this->latestConfirmed((int) $task->component_product_id);
            if (! $latest || (int) $latest->id !== (int) $generation->id) {
                DB::table('stack_image_sync_tasks')->where('id', $taskId)->update([
                    'phase' => 'superseded', 'last_error' => 'A newer generation arrived before completion. This task was not marked current.', 'updated_at' => now(),
                ]);

                return;
            }
            $this->assertGalleryVerified($stack, $image->fresh() ?? $image, $absent, (int) $generation->id);
            DB::table('stack_image_sync_tasks')->where('id', $taskId)->where('generation_id', $generation->id)->update([
                'phase' => 'verified',
                'applied_generation_id' => $generation->id,
                'last_error' => null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $retryable = str_contains($exception->getMessage(), 'PROCESSING')
                || str_contains($exception->getMessage(), 'incomplete')
                || str_contains($exception->getMessage(), 'uncertain')
                || str_contains($exception->getMessage(), 'not READY');
            $this->deferTask($taskId, $exception->getMessage(), $retryable ? 'confirming' : 'failed', 60);
            if ($retryable) {
                throw $exception;
            }
        } finally {
            $mutex->release();
        }
    }

    /**
     * Local staging used by the manual rebuild and by automatic propagation.
     * It does not upload, delete, or renumber stack-owned images.
     *
     * @return array{rebuilt:bool, message:string, created:array<int, int>}
     */
    public function stageStack(Product $stack): array
    {
        $componentIds = $this->catalog->componentIds($stack);
        if ($componentIds === []) {
            return ['rebuilt' => false, 'message' => "{$stack->handle}: no linked components found.", 'created' => []];
        }
        $reasons = [];
        $ready = [];
        foreach ($componentIds as $componentId) {
            $component = Product::query()->find($componentId);
            if (! $component instanceof Product) {
                $reasons[] = 'Component #'.$componentId.' was not found.';

                continue;
            }
            $selected = $this->selector->selected($component);
            if (! $selected instanceof Image) {
                $reasons[] = ($component->handle ?: 'Product #'.$component->id).' has no single current primary photograph with a backup asset.';

                continue;
            }
            $selected->loadMissing('imageAsset');
            if (! $selected->imageAsset instanceof ImageAsset) {
                $reasons[] = ($component->handle ?: 'Product #'.$component->id).' image '.$selected->id.' has no retrievable managed asset.';

                continue;
            }
            $ready[] = [$component, $selected];
        }
        if ($reasons !== []) {
            return ['rebuilt' => false, 'message' => "{$stack->handle}: rebuild stopped before replacing images. ".implode(' ', $reasons), 'created' => []];
        }
        $created = [];
        $conflicts = [];
        foreach ($ready as [$component, $selected]) {
            $generation = (object) [
                'id' => null,
                'component_image_id' => $selected->id,
                'component_product_id' => $component->id,
                'image_asset_id' => $selected->image_asset_id,
                'asset_sha256' => $selected->imageAsset->sha256,
                'status' => $this->selector->confirmed($selected) ? 'confirmed' : 'staged',
            ];
            $staged = $this->stageComponentOnStack($stack, $component, $generation);
            $created[] = (int) $staged['image']->id;
            if ($staged['alt_conflict']) {
                $conflicts[] = $selected->imageAsset->sha256;
            }
        }

        $conflictNote = $conflicts !== [] ? ' Conflicting inherited alt text needs review; previous descriptions were preserved.' : '';

        return [
            'rebuilt' => true,
            'message' => "{$stack->handle}: staged ".count(array_unique($created)).' inherited photograph(s). Stack-owned images were left in place. Shopify sync waits for a confirmed component publication.'.$conflictNote,
            'created' => array_values(array_unique($created)),
        ];
    }

    public function recoverPending(): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $this->reconcileObservedMembership();
        $dispatched = 0;
        $outbox = DB::table('component_publication_outbox')
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(50)
            ->get();
        foreach ($outbox as $row) {
            $this->fanOut((int) $row->id);
            $dispatched++;
        }
        $tasks = DB::table('stack_image_sync_tasks')
            ->whereIn('phase', ['pending', 'staging', 'uploading', 'confirming', 'retiring', 'failed'])
            ->where('attempts', '<', 8)
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(50)
            ->get();
        foreach ($tasks as $task) {
            try {
                SynchronizeStackInheritedImagesJob::dispatch((int) $task->id);
                $dispatched++;
            } catch (\Throwable $exception) {
                $this->deferTask((int) $task->id, 'Queue dispatch failed: '.$exception->getMessage(), (string) $task->phase);
            }
        }

        return $dispatched;
    }

    /**
     * @return array{image: Image, alt_conflict: bool}
     */
    private function stageComponentOnStack(Product $stack, Product $component, object $generation): array
    {
        $asset = ImageAsset::query()->find($generation->image_asset_id);
        if (! $asset instanceof ImageAsset) {
            throw new RuntimeException('The component backup asset is missing.');
        }
        $componentImage = Image::query()->find($generation->component_image_id);
        $sources = $this->sameAssetComponentImages($stack, $asset);
        if ($componentImage instanceof Image && $sources->doesntContain(fn (Image $image): bool => (int) $image->id === (int) $componentImage->id)) {
            $sources->push($componentImage);
        }
        $approved = $sources->map(fn (Image $image): string => trim((string) app(ProductPartialApprovalService::class)->approvedAlt($image)))->filter()->unique()->values();
        $raw = $sources->map(fn (Image $image): string => trim((string) $image->alt_text))->filter()->unique()->values();
        $altConflict = $approved->count() > 1 || ($approved->isEmpty() && $raw->count() > 1);
        $alts = $approved->count() === 1 ? $approved : ($raw->count() === 1 ? $raw : collect());
        $existing = $stack->allImages()->active()
            ->where('ownership', ImageOwnership::COMPONENT_INHERITED)
            ->where('image_asset_id', $asset->id)
            ->orderBy('id')
            ->first();
        if (! $existing instanceof Image) {
            $position = ((int) $stack->allImages()->active()->max('position')) + 1;
            $existing = new Image;
            Image::withoutEvents(function () use ($existing, $stack, $asset, $componentImage, $position, $altConflict, $alts): void {
                $existing->forceFill([
                    'product_id' => $stack->id,
                    'ownership' => ImageOwnership::COMPONENT_INHERITED,
                    'source_image_id' => $componentImage?->id,
                    'image_asset_id' => $asset->id,
                    'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
                    'local_dirty' => true,
                    'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
                    'backup_completed_at' => now(),
                    'needs_shopify_image_sync' => true,
                    'position' => max(1, $position),
                    'alt_text' => $altConflict ? null : ($alts->first() ?: null),
                    'approved_filename' => $componentImage?->preferredFilename(),
                    'filename_mode' => Image::FILENAME_MODE_MANUAL,
                ])->save();
            });
        }
        foreach ($sources as $source) {
            $sourceGenerationId = DB::table('component_photograph_generations')
                ->where('component_image_id', $source->id)
                ->where('image_asset_id', $asset->id)
                ->where('status', 'confirmed')
                ->orderByDesc('selection_revision')
                ->orderByDesc('id')
                ->value('id');
            $sourceGenerationId = $sourceGenerationId ?: ($source->id === ($componentImage?->id) ? $generation->id : null);
            $match = DB::table('inherited_image_sources')
                ->where('image_id', $existing->id)
                ->where('component_image_id', $source->id)
                ->where('image_asset_id', $asset->id)
                ->first();
            if ($match) {
                $updates = [];
                if ($sourceGenerationId && (int) $match->generation_id !== (int) $sourceGenerationId) {
                    $updates['generation_id'] = $sourceGenerationId;
                }
                if ($match->removed_at !== null) {
                    $updates['removed_at'] = null;
                }
                if ($updates !== []) {
                    app(ImagePhotographEvidence::class)->archiveSource($match, 'restage_source');
                    $updates['updated_at'] = now();
                    DB::table('inherited_image_sources')->where('id', $match->id)->update($updates);
                }

                continue;
            }
            DB::table('inherited_image_sources')->insertOrIgnore([
                'image_id' => $existing->id,
                'component_image_id' => $source->id,
                'component_product_id' => $source->product_id,
                'generation_id' => $sourceGenerationId,
                'image_asset_id' => $asset->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($existing->alt_inheritance_mode === 'independent') {
            return ['image' => $existing, 'alt_conflict' => false];
        }
        $resolution = app(InheritedAltResolution::class)->current($existing);
        if (is_string($resolution) && $resolution !== '' && $approved->contains($resolution)) {
            $altConflict = false;
            if (trim((string) $existing->alt_text) !== $resolution) {
                if (trim((string) $existing->alt_text) !== '') {
                    app(ImagePhotographEvidence::class)->archive($existing, 'Restored an operator alt resolution');
                }
                Image::withoutEvents(fn () => $existing->forceFill([
                    'alt_text' => $resolution,
                    'alt_text_status' => Image::ALT_APPROVED,
                ])->save());
            }
        } elseif ($altConflict && trim((string) $existing->alt_text) !== '') {
            app(ImagePhotographEvidence::class)->archive($existing, 'Inherited alt conflict preserved the previous description');
        }
        $this->copyInheritedAlt($existing, $approved, $raw, $altConflict, is_string($resolution) && $resolution !== '');

        return ['image' => $existing->fresh() ?? $existing, 'alt_conflict' => $altConflict];
    }

    /**
     * Copies the source sentence onto the stack record. It does not approve the
     * stack image independently and does not mark the photograph for re-upload.
     *
     * @param  Collection<int, string>  $approved
     * @param  Collection<int, string>  $raw
     */
    private function copyInheritedAlt(Image $existing, Collection $approved, Collection $raw, bool $altConflict, bool $hasResolution): void
    {
        if ($altConflict || $hasResolution || $existing->alt_inheritance_mode === 'independent' || $existing->alt_confirmation_media_id !== null) {
            return;
        }
        $sentence = null;
        $clearApproval = false;
        if ($approved->count() === 1) {
            $sentence = (string) $approved->first();
        } elseif ($approved->count() === 0 && $raw->count() === 1 && trim((string) $existing->alt_text) === '') {
            $sentence = (string) $raw->first();
            $clearApproval = true;
        }
        if ($sentence === null || trim((string) $existing->alt_text) === $sentence) {
            return;
        }
        if (trim((string) $existing->alt_text) !== '') {
            app(ImagePhotographEvidence::class)->archive($existing, 'Inherited alt text followed its component description');
        }
        Image::withoutEvents(function () use ($existing, $sentence, $clearApproval): void {
            $updates = ['alt_text' => $sentence];
            if ($clearApproval) {
                $updates['alt_text_status'] = null;
            }
            $existing->forceFill($updates)->save();
        });
    }

    /**
     * @return Collection<int, Image>
     */
    private function sameAssetComponentImages(Product $stack, ImageAsset $asset): Collection
    {
        $ids = $this->catalog->componentIds($stack);
        if ($ids === []) {
            return collect();
        }

        return Image::query()->active()
            ->whereIn('product_id', $ids)
            ->where('image_asset_id', $asset->id)
            ->where('position', 1)
            ->orderBy('id')
            ->get();
    }

    private function retireOlderInherited(Product $stack, Image $replacement, int $componentProductId, string $replacementMediaId, int $generationId): void
    {
        if (! $this->generationIsCurrent($componentProductId, $generationId)) {
            return;
        }
        $olderIds = DB::table('inherited_image_sources')
            ->where('component_product_id', $componentProductId)
            ->where('image_id', '!=', $replacement->id)
            ->pluck('image_id');
        $older = Image::query()->active()
            ->where('product_id', $stack->id)
            ->where('ownership', ImageOwnership::COMPONENT_INHERITED)
            ->whereIn('id', $olderIds)
            ->get();
        foreach ($older as $image) {
            if (DB::table('inherited_media_retirements')->where('image_id', $image->id)->where('old_media_id', $image->shopify_id)->where('status', 'retained')->exists()) {
                continue;
            }
            if ((int) $image->image_asset_id === (int) $replacement->image_asset_id) {
                continue;
            }
            if (! $this->generationIsCurrent($componentProductId, $generationId)) {
                return;
            }
            $stillShared = DB::table('inherited_image_sources')
                ->where('image_id', $image->id)
                ->where('component_product_id', '!=', $componentProductId)
                ->whereNull('removed_at')
                ->exists();
            if ($stillShared) {
                $this->closeComponentProvenance($image, $componentProductId);

                continue;
            }
            $oldMedia = trim((string) $image->shopify_id);
            if ($oldMedia !== '' && $oldMedia !== $replacementMediaId) {
                if ($this->anotherActiveImageUsesMedia($stack, $image, $oldMedia)) {
                    continue;
                }
                $this->persistRetirementIntent($stack, $image, $oldMedia, $replacementMediaId, (int) $replacement->id, $generationId);
                $this->updater->retireStackProductMedia($stack, $oldMedia, $replacementMediaId, function () use ($componentProductId, $generationId, $replacement): void {
                    if (! $this->generationIsCurrent($componentProductId, $generationId)) {
                        throw new RuntimeException('A newer component generation superseded retirement. The newer photograph was kept.');
                    }
                    $current = Image::query()->find($replacement->id);
                    if ($current instanceof Image && trim((string) $current->shopify_id) !== '' && trim((string) $current->shopify_id) === trim((string) $replacement->shopify_id)) {
                        return;
                    }
                    throw new RuntimeException('The replacement photograph changed before the old association could be removed.');
                }, (int) $image->id);
                DB::table('inherited_media_retirements')
                    ->where('image_id', $image->id)
                    ->where('old_media_id', $oldMedia)
                    ->where('generation_id', $generationId)
                    ->whereIn('status', ['pending', 'mutating'])
                    ->update(['status' => 'confirmed', 'resolution' => 'shopify_absent', 'last_error' => null, 'updated_at' => now()]);
            }
            $this->closeComponentProvenance($image, $componentProductId);
            app(ImagePhotographEvidence::class)->archive($image, 'Superseded inherited stack photograph');
            Variant::query()->where('product_id', $stack->id)->where('image_id', $image->id)->update(['image_id' => $replacement->id]);
            Image::withoutEvents(fn () => $image->forceFill([
                'sync_state' => Image::SYNC_STATE_LOCAL_DELETED,
                'needs_shopify_image_sync' => false,
            ])->save());
        }
    }

    private function persistRetirementIntent(Product $stack, Image $image, string $oldMedia, string $replacementMediaId, int $replacementImageId, int $generationId): void
    {
        DB::table('inherited_media_retirements')->insertOrIgnore([
            'stack_product_id' => $stack->id,
            'image_id' => $image->id,
            'generation_id' => $generationId,
            'old_media_id' => $oldMedia,
            'replacement_media_id' => $replacementMediaId !== '' ? $replacementMediaId : null,
            'replacement_image_id' => $replacementImageId > 0 ? $replacementImageId : null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function generationIsCurrent(int $componentProductId, int $generationId): bool
    {
        $latest = $this->latestConfirmed($componentProductId);

        return $latest !== null && (int) $latest->id === $generationId;
    }

    private function assertGalleryVerified(Product $stack, Image $image, array $absentMediaIds = [], ?int $generationId = null): void
    {
        $productId = trim((string) $stack->shopify_id);
        $mediaId = trim((string) $image->shopify_id);
        $nodes = collect((new ShopifyProductMediaReader(app(ShopifyGraphqlGateway::class)))->media($productId, 'VerifyInheritedStackGallery'));
        $node = $nodes->first(fn (array $node): bool => ($node['id'] ?? null) === $mediaId);
        if (! is_array($node) || ! ShopifyProductMediaReader::ready($node) || ! app(ImagePhotographEvidence::class)->matchesMedia($image, $node)) {
            throw new RuntimeException('The inherited photograph is not attached as READY media with matching bytes.');
        }
        foreach ($absentMediaIds as $absentId) {
            if ($nodes->contains(fn (array $node): bool => ($node['id'] ?? null) === $absentId)) {
                throw new RuntimeException('The superseded stack photograph is still attached.');
            }
        }
        $present = $nodes->pluck('id')->filter()->values()->all();
        $absentIntents = DB::table('inherited_media_retirements')
            ->where('stack_product_id', $stack->id)
            ->whereIn('status', ['pending', 'mutating']);
        if ($present !== []) {
            $absentIntents->whereNotIn('old_media_id', $present);
        }
        $absentIntents->update([
            'status' => 'confirmed',
            'resolution' => 'observed_absent',
            'last_error' => null,
            'updated_at' => now(),
        ]);
        if ($generationId) {
            $pending = DB::table('inherited_media_retirements')
                ->where('stack_product_id', $stack->id)
                ->where('generation_id', $generationId)
                ->whereIn('status', ['pending', 'mutating'])
                ->exists();
            if ($pending) {
                throw new RuntimeException('A stack retirement is still unconfirmed.');
            }
        }
    }

    private function ensureTask(int $stackId, object $generation, string $purpose = 'publish', int $membershipRevision = 0): int
    {
        return $this->ensureStackTask($stackId, (int) $generation->id, $purpose, $generation, $membershipRevision);
    }

    public function ensureStackTask(int $stackId, int $generationId, string $purpose = 'publish', ?object $generation = null, int $membershipRevision = 0): int
    {
        $generation ??= DB::table('component_photograph_generations')->where('id', $generationId)->first();
        if (! $generation) {
            throw new RuntimeException('The component generation does not exist.');
        }
        DB::table('stack_image_sync_tasks')->insertOrIgnore([
            'stack_product_id' => $stackId,
            'component_product_id' => $generation->component_product_id,
            'component_image_id' => $generation->component_image_id,
            'generation_id' => $generation->id,
            'purpose' => $purpose,
            'membership_revision' => $membershipRevision,
            'phase' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('stack_image_sync_tasks')
            ->where('stack_product_id', $stackId)
            ->where('generation_id', $generation->id)
            ->where('purpose', $purpose)
            ->where('membership_revision', $membershipRevision)
            ->value('id');
    }

    private function dispatchTaskForGeneration(int $stackId, int $generationId, int $membershipRevision = 0, string $purpose = 'publish'): void
    {
        $taskId = DB::table('stack_image_sync_tasks')
            ->where('stack_product_id', $stackId)
            ->where('generation_id', $generationId)
            ->where('purpose', $purpose)
            ->where('membership_revision', $membershipRevision)
            ->value('id');
        if ($taskId) {
            try {
                SynchronizeStackInheritedImagesJob::dispatch((int) $taskId);
            } catch (\Throwable $exception) {
                $this->deferTask((int) $taskId, 'Queue dispatch failed: '.$exception->getMessage(), 'pending');
            }
        }
    }

    private function latestConfirmed(int $componentProductId): ?object
    {
        $product = Product::query()->find($componentProductId);
        if (! $product instanceof Product) {
            return null;
        }
        $selected = $this->selector->selected($product);
        if (! $selected instanceof Image) {
            return null;
        }

        return DB::table('component_photograph_generations')
            ->where('component_image_id', $selected->id)
            ->where('image_asset_id', $selected->image_asset_id)
            ->where('status', 'confirmed')
            ->orderByDesc('selection_revision')
            ->orderByDesc('id')
            ->first();
    }

    private function deferTask(int $taskId, string $error, string $phase, int $seconds = 60): void
    {
        DB::table('stack_image_sync_tasks')->where('id', $taskId)->whereNotIn('phase', ['verified', 'superseded'])->update([
            'phase' => $phase,
            'last_error' => $error,
            'available_at' => now()->addSeconds($seconds),
            'updated_at' => now(),
        ]);
    }

    private function revisionForSelection(int $productId, int $imageId, int $assetId): int
    {
        DB::table('component_selection_revisions')->insertOrIgnore([
            'component_product_id' => $productId,
            'revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $state = DB::table('component_selection_revisions')->where('component_product_id', $productId)->lockForUpdate()->first();
        $max = (int) DB::table('component_photograph_generations')->where('component_product_id', $productId)->max('selection_revision');
        if ((int) $state->revision < $max) {
            DB::table('component_selection_revisions')->where('id', $state->id)->update([
                'revision' => $max,
                'updated_at' => now(),
            ]);
            $state->revision = $max;
        }
        $latest = DB::table('component_photograph_generations')
            ->where('component_product_id', $productId)
            ->where('status', 'confirmed')
            ->orderByDesc('selection_revision')
            ->orderByDesc('id')
            ->first();
        if ($latest && (int) $latest->component_image_id === $imageId && (int) $latest->image_asset_id === $assetId) {
            return (int) $latest->selection_revision;
        }
        $revision = (int) $state->revision + 1;
        DB::table('component_selection_revisions')->where('id', $state->id)->update([
            'revision' => $revision,
            'updated_at' => now(),
        ]);

        return $revision;
    }

    public function reconcileDraftMembership(NewProductDraft $draft): void
    {
        if (! $this->enabled()) {
            return;
        }
        $shopifyId = trim((string) $draft->shopify_id);
        $handle = trim((string) $draft->handle);
        if ($shopifyId === '' && $handle === '') {
            return;
        }
        $stack = Product::query()
            ->where(function ($query) use ($shopifyId, $handle): void {
                if ($shopifyId !== '') {
                    $query->where('shopify_id', $shopifyId);
                }
                if ($handle !== '') {
                    $shopifyId !== '' ? $query->orWhere('handle', $handle) : $query->where('handle', $handle);
                }
            })
            ->orderBy('id')
            ->first();
        if (! $stack instanceof Product || ! $stack->isStackProduct()) {
            return;
        }
        $componentIds = $this->catalog->componentIds($stack);
        $membership = $this->membershipRevision($stack, $componentIds, 'reconcile');
        foreach ($componentIds as $componentId) {
            $latest = $this->latestConfirmed((int) $componentId);
            if (! $latest) {
                continue;
            }
            $taskId = $this->ensureStackTask((int) $stack->id, (int) $latest->id, 'publish', $latest, $membership);
            $phase = DB::table('stack_image_sync_tasks')->where('id', $taskId)->value('phase');
            $visible = $this->stackShowsInheritedAsset($stack, (int) $latest->image_asset_id);
            if ($phase === 'verified' && ! $visible) {
                DB::table('stack_image_sync_tasks')->where('id', $taskId)->update([
                    'phase' => 'pending',
                    'attempts' => 0,
                    'last_error' => null,
                    'available_at' => now(),
                    'updated_at' => now(),
                ]);
                $phase = 'pending';
            }
            if ($phase !== 'verified') {
                $this->dispatchTaskForGeneration((int) $stack->id, (int) $latest->id, $membership);
            }
        }
        $removed = DB::table('inherited_image_sources')
            ->whereIn('image_id', Image::query()->where('product_id', $stack->id)->pluck('id'))
            ->whereNull('removed_at')
            ->whereNotIn('component_product_id', $componentIds === [] ? [0] : $componentIds)
            ->pluck('component_product_id')
            ->unique();
        foreach ($removed as $componentId) {
            $generation = DB::table('component_photograph_generations')
                ->where('component_product_id', $componentId)
                ->orderByDesc('selection_revision')
                ->orderByDesc('id')
                ->first();
            if (! $generation) {
                continue;
            }
            $taskId = $this->ensureStackTask((int) $stack->id, (int) $generation->id, 'remove', $generation, $membership);
            $phase = DB::table('stack_image_sync_tasks')->where('id', $taskId)->value('phase');
            if ($phase !== 'verified') {
                try {
                    SynchronizeStackInheritedImagesJob::dispatch($taskId);
                } catch (\Throwable $exception) {
                    $this->deferTask($taskId, 'Queue dispatch failed: '.$exception->getMessage(), 'pending');
                }
            }
        }
    }

    public function reopenTask(int $taskId, string $reason, ?int $userId): void
    {
        $reason = trim($reason);
        if (strlen($reason) < 20) {
            throw new RuntimeException('Recovery requires a reason of at least 20 characters.');
        }
        $operator = $this->operator((int) $userId);
        $task = DB::table('stack_image_sync_tasks')->where('id', $taskId)->first();
        if (! $task || ! in_array($task->phase, ['exhausted', 'blocked', 'failed', 'alt_conflict'], true)) {
            throw new RuntimeException('Only exhausted, blocked, failed, or alt-conflict tasks can be reopened.');
        }
        if ($this->taskHasUncertainMutation($task)) {
            throw new RuntimeException('Reconcile the uncertain Shopify upload before retrying this task.');
        }
        DB::transaction(function () use ($taskId, $task, $reason, $operator): void {
            DB::table('stack_image_sync_task_audits')->insert([
                'task_id' => $taskId,
                'previous_phase' => $task->phase,
                'action' => 'reopen',
                'reason' => $reason,
                'user_id' => $operator->id,
                'created_at' => now(),
            ]);
            DB::table('stack_image_sync_tasks')->where('id', $taskId)->update([
                'phase' => 'pending',
                'attempts' => 0,
                'last_error' => null,
                'available_at' => now(),
                'updated_at' => now(),
            ]);
        });
        if ($this->enabled()) {
            SynchronizeStackInheritedImagesJob::dispatch($taskId);
        }
    }

    public function resolveInheritedAlt(int $taskId, string $alt, string $reason, ?int $userId): void
    {
        $alt = trim($alt);
        $reason = trim($reason);
        if ($alt === '' || strlen($reason) < 20) {
            throw new RuntimeException('Conflict resolution requires an approved sentence and a reason of at least 20 characters.');
        }
        $operator = $this->operator((int) $userId);
        $task = DB::table('stack_image_sync_tasks')->where('id', $taskId)->first();
        if (! $task || $task->phase !== 'alt_conflict') {
            throw new RuntimeException('Only an alt-conflict task can accept an inherited description.');
        }
        $generation = DB::table('component_photograph_generations')->where('id', $task->generation_id)->first();
        $image = Image::query()->active()
            ->where('product_id', $task->stack_product_id)
            ->where('ownership', ImageOwnership::COMPONENT_INHERITED)
            ->where('image_asset_id', $generation->image_asset_id ?? 0)
            ->first();
        if (! $image instanceof Image) {
            throw new RuntimeException('The inherited photograph for this conflict was not found.');
        }
        $sourceIds = DB::table('inherited_image_sources')->where('image_id', $image->id)->whereNull('removed_at')->pluck('component_image_id');
        $allowed = Image::query()->whereIn('id', $sourceIds)->get()
            ->map(fn (Image $source): string => trim((string) app(ProductPartialApprovalService::class)->approvedAlt($source)))
            ->filter()
            ->unique()
            ->all();
        if (! in_array($alt, $allowed, true)) {
            throw new RuntimeException('The sentence is not an approved description of a source component.');
        }
        $generationIds = [];
        foreach (Image::query()->whereIn('id', $sourceIds)->get() as $source) {
            $sourceGenerationId = DB::table('component_photograph_generations')
                ->where('component_image_id', $source->id)
                ->where('image_asset_id', $image->image_asset_id)
                ->where('status', 'confirmed')
                ->orderByDesc('selection_revision')
                ->value('id');
            if ($sourceGenerationId) {
                $generationIds[] = (int) $sourceGenerationId;
                foreach (DB::table('inherited_image_sources')->where('image_id', $image->id)->where('component_image_id', $source->id)->whereNull('removed_at')->get() as $link) {
                    app(ImagePhotographEvidence::class)->archiveSource($link, 'resolve_alt_source');
                }
                DB::table('inherited_image_sources')->where('image_id', $image->id)->where('component_image_id', $source->id)
                    ->whereNull('removed_at')->update(['generation_id' => $sourceGenerationId, 'updated_at' => now()]);
            }
        }
        $context = app(InheritedAltResolution::class)->context($image);
        DB::transaction(function () use ($image, $alt, $taskId, $reason, $operator, $generationIds, $context): void {
            Image::query()->whereKey($image->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($context, app(InheritedAltResolution::class)->context($image->fresh()))) {
                throw new RuntimeException('The conflict changed during review. Reopen the current conflict.');
            }
            app(ImagePhotographEvidence::class)->archive($image, 'Operator resolved an inherited alt conflict');
            Image::withoutEvents(fn () => $image->forceFill([
                'alt_text' => $alt,
                'alt_text_status' => Image::ALT_APPROVED,
            ])->save());
            DB::table('inherited_alt_resolutions')->insert([
                'image_id' => $image->id,
                'image_asset_id' => $image->image_asset_id,
                'alt_text' => $alt,
                'source_generation_ids' => json_encode($generationIds),
                'context_hash' => $context,
                'user_id' => $operator->id,
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('stack_image_sync_task_audits')->insert([
                'task_id' => $taskId,
                'previous_phase' => 'alt_conflict',
                'action' => 'resolve_alt',
                'reason' => $reason,
                'user_id' => $operator->id,
                'created_at' => now(),
            ]);
            DB::table('stack_image_sync_tasks')->where('id', $taskId)->update([
                'phase' => 'pending',
                'attempts' => 0,
                'last_error' => null,
                'available_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function taskHasUncertainMutation(object $task): bool
    {
        $imageIds = DB::table('inherited_image_sources')
            ->where('component_product_id', $task->component_product_id)
            ->pluck('image_id');
        $images = Image::query()->where('product_id', $task->stack_product_id)->whereIn('id', $imageIds)->get();
        foreach ($images as $image) {
            if (DB::table('inherited_media_retirements')->where('image_id', $image->id)->where('old_media_id', $image->shopify_id)->where('status', 'retained')->exists()) {
                continue;
            }
            if ($image->shopify_media_create_uncertain) {
                return true;
            }
            $operation = DB::table('image_upload_operations')->where('active_image_id', $image->id)->first();
            if ($operation && in_array($operation->status, ['submitting', 'processing', 'needs_review'], true)) {
                return true;
            }
        }

        return false;
    }

    private function removeInheritedComponent(object $task): void
    {
        $stack = Product::query()->find($task->stack_product_id);
        if (! $stack instanceof Product) {
            $this->deferTask((int) $task->id, 'The stack no longer exists.', 'failed');

            return;
        }
        if (in_array((int) $task->component_product_id, $this->catalog->componentIds($stack), true)) {
            DB::table('stack_image_sync_tasks')->where('id', $task->id)->update([
                'phase' => 'superseded',
                'last_error' => 'The component is a current stack member. Removal was not applied.',
                'updated_at' => now(),
            ]);

            return;
        }
        $imageIds = DB::table('inherited_image_sources')
            ->where('component_product_id', $task->component_product_id)
            ->whereNull('removed_at')
            ->pluck('image_id');
        $images = Image::query()->active()
            ->where('product_id', $stack->id)
            ->where('ownership', ImageOwnership::COMPONENT_INHERITED)
            ->whereIn('id', $imageIds)
            ->get();
        foreach ($images as $image) {
            $stillShared = DB::table('inherited_image_sources')
                ->where('image_id', $image->id)
                ->where('component_product_id', '!=', $task->component_product_id)
                ->whereNull('removed_at')
                ->exists();
            if ($stillShared) {
                $this->closeComponentProvenance($image, (int) $task->component_product_id);

                continue;
            }
            $oldMedia = trim((string) $image->shopify_id);
            if ($oldMedia !== '' && ! $this->anotherActiveImageUsesMedia($stack, $image, $oldMedia)) {
                $this->persistRetirementIntent($stack, $image, $oldMedia, '', 0, (int) $task->generation_id);
                $this->updater->retireStackProductMedia($stack, $oldMedia, '', function () use ($stack, $task): void {
                    $current = Product::query()->find($stack->id);
                    if ($current instanceof Product && in_array((int) $task->component_product_id, $this->catalog->componentIds($current), true)) {
                        throw new RuntimeException('The component returned to the stack. The photograph was kept.');
                    }
                }, (int) $image->id);
                DB::table('inherited_media_retirements')
                    ->where('image_id', $image->id)
                    ->where('old_media_id', $oldMedia)
                    ->whereIn('status', ['pending', 'mutating'])
                    ->update(['status' => 'confirmed', 'resolution' => 'shopify_absent', 'last_error' => null, 'updated_at' => now()]);
            }
            $this->closeComponentProvenance($image, (int) $task->component_product_id);
            app(ImagePhotographEvidence::class)->archive($image, 'Component removed from the stack');
            Variant::query()->where('product_id', $stack->id)->where('image_id', $image->id)->update(['image_id' => null]);
            Image::withoutEvents(fn () => $image->forceFill([
                'sync_state' => Image::SYNC_STATE_LOCAL_DELETED,
                'needs_shopify_image_sync' => false,
            ])->save());
        }
        if (DB::table('inherited_media_retirements')->where('stack_product_id', $stack->id)->where('generation_id', $task->generation_id)->whereIn('status', ['pending', 'mutating'])->exists()) {
            $this->deferTask((int) $task->id, 'A stack retirement is still unconfirmed.', 'failed');

            return;
        }
        DB::table('stack_image_sync_tasks')->where('id', $task->id)->update([
            'phase' => 'verified',
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    private function anotherActiveImageUsesMedia(Product $stack, Image $image, string $mediaId): bool
    {
        return Image::query()->active()
            ->where('product_id', $stack->id)
            ->where('id', '!=', $image->id)
            ->where('shopify_id', $mediaId)
            ->exists();
    }

    private function stackShowsInheritedAsset(Product $stack, int $assetId): bool
    {
        return $assetId > 0 && Image::query()->active()
            ->where('product_id', $stack->id)
            ->where('ownership', ImageOwnership::COMPONENT_INHERITED)
            ->where('image_asset_id', $assetId)
            ->exists();
    }

    /**
     * @param  array<int, int>  $componentIds
     */
    private function membershipRevision(Product $stack, array $componentIds, string $source): int
    {
        return DB::transaction(function () use ($stack, $componentIds, $source): int {
            Product::query()->whereKey($stack->id)->lockForUpdate()->firstOrFail();
            $currentMembers = $this->catalog->componentIds($stack);
            sort($currentMembers);
            $requestedMembers = $componentIds;
            sort($requestedMembers);
            if ($currentMembers !== $requestedMembers) {
                throw new RuntimeException('Stack membership changed before revision allocation; retry reconciliation.');
            }
            $canonical = collect($componentIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $latest = DB::table('stack_membership_revisions')->where('stack_product_id', $stack->id)->orderByDesc('revision')->first();
            $previous = $latest ? json_decode((string) $latest->component_ids, true) : null;
            $previousNorm = is_array($previous)
                ? collect($previous)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all()
                : null;
            if ($latest && $previousNorm === $canonical) {
                return (int) $latest->revision;
            }
            $revision = (int) ($latest->revision ?? 0) + 1;
            try {
                DB::table('stack_membership_revisions')->insert([
                    'stack_product_id' => $stack->id,
                    'revision' => $revision,
                    'component_ids' => json_encode($canonical),
                    'source' => $source,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                $again = DB::table('stack_membership_revisions')->where('stack_product_id', $stack->id)->orderByDesc('revision')->first();
                $decoded = $again ? json_decode((string) $again->component_ids, true) : null;
                $norm = is_array($decoded) ? collect($decoded)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all() : null;
                if ($again && $norm === $canonical) {
                    return (int) $again->revision;
                }
                throw $exception;
            }

            return $revision;
        });
    }

    private function reconcileObservedMembership(): void
    {
        $mutex = new ImageSyncMutex;
        if (! $mutex->acquireRelationshipScan()) {
            return;
        }
        try {
            DB::table('image_relationship_scan_state')->insertOrIgnore([
                'name' => 'draft_membership', 'last_id' => 0, 'upper_id' => 0,
                'completed_passes' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $state = DB::table('image_relationship_scan_state')->where('name', 'draft_membership')->first();
            if ((int) $state->upper_id <= (int) $state->last_id) {
                DB::table('image_relationship_scan_state')->where('name', 'draft_membership')->update([
                    'last_id' => 0, 'upper_id' => (int) NewProductDraft::max('id'), 'updated_at' => now(),
                ]);
                $state = DB::table('image_relationship_scan_state')->where('name', 'draft_membership')->first();
            }
            // Failed drafts have a separate bounded retry lane and cannot starve older drafts.
            $retryIds = DB::table('image_relationship_scan_failures')->orderBy('updated_at')->limit(10)->pluck('draft_id');
            foreach ($retryIds as $id) {
                $mutex->assertOwned();
                $this->inspectMembershipDraft((int) $id);
            }
            $drafts = NewProductDraft::query()->where('id', '>', $state->last_id)
                ->where('id', '<=', $state->upper_id)->orderBy('id')->limit(40)->get();
            foreach ($drafts as $draft) {
                $mutex->assertOwned();
                $this->inspectMembershipDraft((int) $draft->id);
                // A crash replays at most one idempotent reconciliation.
                DB::table('image_relationship_scan_state')->where('name', 'draft_membership')->update([
                    'last_id' => $draft->id, 'updated_at' => now(),
                ]);
            }
            if ($drafts->count() < 40 || (int) $drafts->last()?->id >= (int) $state->upper_id) {
                DB::table('image_relationship_scan_state')->where('name', 'draft_membership')->update([
                    'last_id' => $state->upper_id, 'completed_passes' => DB::raw('completed_passes + 1'), 'updated_at' => now(),
                ]);
            }
        } finally {
            $mutex->release();
        }
    }

    private function inspectMembershipDraft(int $id): void
    {
        try {
            $draft = NewProductDraft::find($id);
            if ($draft) {
                $this->reconcileDraftMembership($draft);
            }
            DB::table('image_relationship_scan_failures')->where('draft_id', $id)->delete();
        } catch (\Throwable $exception) {
            DB::table('image_relationship_scan_failures')->upsert([
                ['draft_id' => $id, 'attempts' => 1, 'error' => $exception->getMessage(), 'created_at' => now(), 'updated_at' => now()],
            ], ['draft_id'], ['attempts' => DB::raw('attempts + 1'), 'error', 'updated_at']);
            logger()->warning('Stack membership reconciliation failed.', ['draft_id' => $id, 'error' => $exception->getMessage()]);
        }
    }

    private function closeComponentProvenance(Image $image, int $componentProductId): void
    {
        foreach (DB::table('inherited_image_sources')->where('image_id', $image->id)->where('component_product_id', $componentProductId)->whereNull('removed_at')->get() as $link) {
            app(ImagePhotographEvidence::class)->archiveSource($link, 'remove_source');
        }
        DB::table('inherited_image_sources')
            ->where('image_id', $image->id)
            ->where('component_product_id', $componentProductId)
            ->whereNull('removed_at')
            ->update(['removed_at' => now(), 'updated_at' => now()]);
        $survivor = DB::table('inherited_image_sources')
            ->where('image_id', $image->id)
            ->whereNull('removed_at')
            ->orderBy('id')
            ->first();
        if ($survivor && (int) $image->source_image_id !== (int) $survivor->component_image_id) {
            Image::withoutEvents(fn () => $image->forceFill(['source_image_id' => $survivor->component_image_id])->save());
        }
    }

    private function operator(int $userId): User
    {
        try {
            return app(ImageRecoveryOperator::class)->authorize($userId);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Recovery requires an active authorized operator with Admin or SuperAdmin access.', previous: $exception);
        }
    }
}
