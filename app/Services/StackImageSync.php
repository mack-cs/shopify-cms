<?php

namespace App\Services;

use App\Jobs\RebuildDependentStackImagesJob;
use App\Jobs\SynchronizeAuthorizedStackImagesJob;
use App\Models\Image;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use Illuminate\Support\Facades\DB;

/**
 * Local stack galleries, approval-scoped Shopify decisions, and the sync ledger.
 * Shopify publication stays in ProductShopifyUpdater and ComponentStackPropagation.
 */
final class StackImageSync
{
    public function localRebuildEnabled(): bool
    {
        return (bool) config('images.component_stack_local_rebuild', false);
    }

    public function blindPublicationEnabled(): bool
    {
        return (bool) config('images.component_stack_blind_publication', false);
    }

    public function queueLocalRebuild(int $componentProductId): void
    {
        if (! $this->localRebuildEnabled() || $componentProductId < 1) {
            return;
        }
        $product = Product::query()->find($componentProductId);
        if (! $product instanceof Product || $product->isStackProduct()) {
            return;
        }
        try {
            RebuildDependentStackImagesJob::dispatch($componentProductId);
        } catch (\Throwable $exception) {
            logger()->warning('Dependent stack rebuild was not queued.', [
                'product_id' => $componentProductId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function rebuildDependents(int $componentProductId): int
    {
        $count = 0;
        foreach (app(StackComponentCatalog::class)->stackIdsForComponent($componentProductId) as $stackId) {
            $stack = Product::query()->find($stackId);
            if (! $stack instanceof Product) {
                continue;
            }
            $result = app(ComponentStackPropagation::class)->stageStack($stack);
            $this->note($stack, null, null, 'local_rebuilt', $result['message'] ?? 'Local gallery rebuilt.', false);
            $count++;
        }

        return $count;
    }

    public function afterFullApproval(Product $product, ?int $userId): void
    {
        $product = $product->fresh() ?? $product;
        if (! $product->isApprovedByTwo()) {
            return;
        }
        $this->recordAuthorizations($product, 'full', null, $userId);
        $this->queueBlindPublication($product, null);
    }

    public function afterPartialApproval(ProductPartialApprovalRequest $request): void
    {
        $product = $request->product;
        if (! $product instanceof Product || $request->status !== ProductPartialApprovalRequest::STATUS_APPROVED) {
            return;
        }
        $scopes = is_array($request->scopes) ? $request->scopes : [];
        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_IMAGES, $scopes, true)) {
            $this->recordAuthorizations($product, 'images', (int) $request->id, (int) $request->approved_by);
        }
        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT, $scopes, true)) {
            $this->recordAuthorizations($product, 'image_alt', (int) $request->id, (int) $request->approved_by);
        }
        if (array_intersect($scopes, [ProductShopifyUpdater::SYNC_SCOPE_IMAGES, ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT]) !== []) {
            $this->queueBlindPublication($product, (int) $request->id);
        }
    }

    public function queueBlindPublication(Product $component, ?int $approvalRequestId): void
    {
        if (! $this->blindPublicationEnabled() || $component->isStackProduct()) {
            return;
        }
        foreach (app(StackComponentCatalog::class)->stackIdsForComponent((int) $component->id) as $stackId) {
            $this->queueStack((int) $stackId, $approvalRequestId);
        }
    }

    public function queueStack(int $stackId, ?int $approvalRequestId = null): void
    {
        $dispatch = false;
        DB::transaction(function () use ($stackId, $approvalRequestId, &$dispatch): void {
            DB::table('stack_image_sync_cursors')->insertOrIgnore([
                'stack_product_id' => $stackId,
                'desired_generation' => 0,
                'working_generation' => 0,
                'status' => 'idle',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stackId)->lockForUpdate()->first();
            $status = (string) $cursor->status;
            DB::table('stack_image_sync_cursors')->where('id', $cursor->id)->update([
                'desired_generation' => (int) $cursor->desired_generation + 1,
                'approval_request_id' => $approvalRequestId,
                'status' => in_array($status, ['queued', 'running'], true) ? $status : 'queued',
                'updated_at' => now(),
            ]);
            $dispatch = ! in_array($status, ['queued', 'running'], true);
        });
        if ($dispatch) {
            try {
                SynchronizeAuthorizedStackImagesJob::dispatch($stackId);
            } catch (\Throwable $exception) {
                DB::table('stack_image_sync_cursors')->where('stack_product_id', $stackId)->update([
                    'status' => 'idle',
                    'updated_at' => now(),
                ]);
                $stack = Product::query()->find($stackId);
                if ($stack instanceof Product) {
                    $this->note($stack, null, null, 'failed', 'Queue dispatch failed: '.$exception->getMessage(), false);
                }
            }
        }
    }

    /**
     * @return array{outcome:string, uploads:array<int, int>, alt_only:array<int, int>, retire:array<int, int>, reorder:bool, detail:string}
     */
    public function synchronizeStack(int $stackId): array
    {
        $target = 0;
        DB::transaction(function () use ($stackId, &$target): void {
            $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stackId)->lockForUpdate()->first();
            if (! $cursor) {
                return;
            }
            $target = (int) $cursor->desired_generation;
            DB::table('stack_image_sync_cursors')->where('id', $cursor->id)->update([
                'status' => 'running',
                'working_generation' => $target,
                'updated_at' => now(),
            ]);
        });

        $stack = Product::query()->useWritePdo()->find($stackId);
        $plan = ['outcome' => 'failed', 'uploads' => [], 'alt_only' => [], 'retire' => [], 'reorder' => false, 'detail' => 'The stack no longer exists.'];
        if ($stack instanceof Product) {
            app(ComponentStackPropagation::class)->stageStack($stack);
            $stack = $stack->fresh() ?? $stack;
            if (! $this->stackIsActive($stack)) {
                $plan = ['outcome' => 'deferred_inactive', 'uploads' => [], 'alt_only' => [], 'retire' => [], 'reorder' => false, 'detail' => 'Stack is draft or inactive. The local gallery was rebuilt and Shopify was not changed.'];
                $this->note($stack, null, null, $plan['outcome'], $plan['detail'], false);
            } elseif (! $this->blindPublicationEnabled() && ! $stack->isApprovedByTwo()) {
                $plan = ['outcome' => 'shopify_authorization_pending', 'uploads' => [], 'alt_only' => [], 'retire' => [], 'reorder' => false, 'detail' => 'Local gallery is current. Shopify publication is not authorized.'];
                $this->note($stack, null, null, $plan['outcome'], $plan['detail'], false);
            } else {
                $plan = $this->applyAuthorizedPlan($stack);
            }
        }

        $again = false;
        DB::transaction(function () use ($stackId, $target, &$again): void {
            $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stackId)->lockForUpdate()->first();
            if (! $cursor) {
                return;
            }
            $again = (int) $cursor->desired_generation > $target;
            DB::table('stack_image_sync_cursors')->where('id', $cursor->id)->update([
                'status' => $again ? 'queued' : 'idle',
                'updated_at' => now(),
            ]);
        });
        if ($again) {
            SynchronizeAuthorizedStackImagesJob::dispatch($stackId);
        }

        return $plan;
    }

    /**
     * @return array{outcome:string, uploads:array<int, int>, alt_only:array<int, int>, retire:array<int, int>, reorder:bool, detail:string}
     */
    public function plan(Product $stack): array
    {
        $stack = $stack->fresh() ?? $stack;
        if (! $this->stackIsActive($stack) && ! $stack->isApprovedByTwo()) {
            return $this->emptyPlan('deferred_inactive', 'Stack is draft or inactive and has no publication authorization.');
        }
        $uploads = [];
        $altOnly = [];
        $retire = [];
        $reorder = false;
        $images = $stack->allImages()->with('imageAsset')->orderBy('position')->orderBy('id')->get();
        foreach ($images as $image) {
            if (! ImageOwnership::isInherited($image)) {
                continue;
            }
            $source = Image::query()->with('imageAsset', 'product')->find((int) $image->source_image_id);
            $desired = $source instanceof Image && $this->sourceStillMember($stack, $source);
            if (! $desired || in_array($source->sync_state, [Image::SYNC_STATE_LOCAL_DELETED, Image::SYNC_STATE_REMOTE_DELETED], true) || $source->is_duplicate_hidden) {
                if (trim((string) $image->shopify_id) !== '' && $image->sync_state !== Image::SYNC_STATE_LOCAL_DELETED) {
                    $retire[] = (int) $image->id;
                }
                continue;
            }
            if (! $this->photographAuthorized($source) && ! $this->stackCapturedPhotograph($image)) {
                continue;
            }
            $photoVersion = $this->version($source, 'photograph_version');
            $altVersion = $this->version($source, 'alt_text_version');
            $verifiedPhoto = $this->verified($image, 'verified_photograph_version');
            $verifiedAlt = $this->verified($image, 'verified_alt_version');
            $photoChanged = $image->needs_shopify_image_sync
                || trim((string) $image->shopify_id) === ''
                || (int) $image->image_asset_id !== (int) $source->image_asset_id
                || $verifiedPhoto === null
                || $verifiedPhoto !== $photoVersion;
            $altAuthorized = $this->altAuthorized($source);
            $altChanged = $altAuthorized && ($verifiedAlt === null || $verifiedAlt !== $altVersion || trim((string) $image->shopify_alt_text) !== trim((string) $source->alt_text));
            if ($photoChanged && $this->photographAuthorized($source)) {
                $uploads[] = (int) $image->id;
            } elseif ($altChanged && trim((string) $image->shopify_id) !== '') {
                $altOnly[] = (int) $image->id;
            } elseif (! $photoChanged && (int) $image->position !== (int) $source->position && trim((string) $image->shopify_id) !== '') {
                $reorder = true;
            }
        }
        if ($uploads === [] && $altOnly === [] && $retire === [] && ! $reorder) {
            return $this->emptyPlan('no_changes_required', 'Shopify already has the latest authorized photograph and alt text versions.');
        }
        $outcome = $uploads !== [] ? 'shopify_changes_required' : ($altOnly !== [] ? 'alt_text_only' : ($reorder ? 'order_only' : 'retirement_required'));

        return [
            'outcome' => $outcome,
            'uploads' => $uploads,
            'alt_only' => $altOnly,
            'retire' => $retire,
            'reorder' => $reorder,
            'detail' => $outcome,
        ];
    }

    public function photographAuthorized(Image $source): bool
    {
        $source->loadMissing('imageAsset');
        if (! $source->imageAsset || $source->shopify_identity_unresolved) {
            return false;
        }
        $version = $this->version($source, 'photograph_version');

        return DB::table('component_image_authorizations')
            ->where('image_id', $source->id)
            ->where('image_asset_id', $source->image_asset_id)
            ->where('asset_sha256', $source->imageAsset->sha256)
            ->where('photograph_version', $version)
            ->whereIn('scope', ['images', 'full'])
            ->exists();
    }

    public function altAuthorized(Image $source): bool
    {
        $version = $this->version($source, 'alt_text_version');
        $text = trim((string) $source->alt_text);
        if ($text === '') {
            return false;
        }

        return DB::table('component_image_authorizations')
            ->where('image_id', $source->id)
            ->where('alt_text_version', $version)
            ->where('alt_text', $text)
            ->whereIn('scope', ['image_alt', 'full'])
            ->exists();
    }

    public function stackCapturedPhotograph(Image $copy): bool
    {
        $copy->loadMissing('imageAsset');
        if (! $copy->imageAsset) {
            return false;
        }

        return DB::table('component_image_authorizations')
            ->where('image_id', $copy->id)
            ->where('image_asset_id', $copy->image_asset_id)
            ->where('asset_sha256', $copy->imageAsset->sha256)
            ->where('photograph_version', $this->version($copy, 'photograph_version'))
            ->whereIn('scope', ['images', 'full'])
            ->exists();
    }

    public function stackIsActive(Product $stack): bool
    {
        $fresh = Product::query()->useWritePdo()->find($stack->id);

        return $fresh instanceof Product && strtolower(trim((string) $fresh->status)) === 'active';
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(Product $stack): array
    {
        $ledger = DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->orderByDesc('id')->first();
        $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
        $sources = DB::table('inherited_image_sources')
            ->whereIn('image_id', Image::query()->where('product_id', $stack->id)->pluck('id'))
            ->whereNull('removed_at')
            ->get();
        $phase = 'local_cms_rebuild_completed';
        $outcome = (string) ($ledger->outcome ?? '');
        if ($cursor && $cursor->status === 'queued') {
            $phase = 'shopify_synchronization_queued';
        } elseif ($cursor && $cursor->status === 'running') {
            $phase = 'shopify_synchronization_in_progress';
        } elseif ($outcome === 'no_changes_required') {
            $phase = 'no_changes_required';
        } elseif (in_array($outcome, ['verified', 'baseline_verified'], true)) {
            $phase = 'shopify_synchronization_verified';
        } elseif (in_array($outcome, ['failed', 'uncertain'], true)) {
            $phase = 'failed_or_uncertain_synchronization';
        } elseif ($outcome === 'shopify_authorization_pending' || $outcome === 'deferred_inactive') {
            $phase = 'shopify_update_pending_authorization';
        } elseif ($outcome === 'local_rebuilt') {
            $phase = 'local_cms_rebuild_completed';
        }

        return [
            'phase' => $phase,
            'outcome' => $outcome !== '' ? $outcome : null,
            'detail' => $ledger->detail ?? null,
            'verified_at' => $ledger->verified_at ?? null,
            'source_skus' => $sources->pluck('source_sku')->filter()->unique()->values()->all(),
            'cursor_status' => $cursor->status ?? 'idle',
            'approval_request_id' => $ledger->approval_request_id ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function outstandingReminderRows(): array
    {
        $cutoff = now()->subMinutes(90);
        $rows = [];
        $products = Product::query()->whereHas('images', function ($query) use ($cutoff): void {
            $query->whereNotNull('image_attention_since')->where('image_attention_since', '<=', $cutoff)
                ->where(function ($outstanding): void {
                    $outstanding->where('needs_shopify_image_sync', true)
                        ->orWhere('shopify_alt_pending', true)
                        ->orWhereIn('alt_text_status', [Image::ALT_PENDING_REVIEW, Image::ALT_FAILED, Image::ALT_AWAITING_CONFIRMATION])
                        ->orWhere('shopify_media_create_uncertain', true);
                });
        })->with(['images', 'variants'])->get();

        foreach ($products as $product) {
            $images = $product->images->filter(function (Image $image) use ($cutoff): bool {
                return $image->image_attention_since !== null
                    && $image->image_attention_since->lte($cutoff)
                    && ($image->needs_shopify_image_sync || $image->shopify_alt_pending || $image->shopify_media_create_uncertain
                        || in_array($image->alt_text_status, [Image::ALT_PENDING_REVIEW, Image::ALT_FAILED, Image::ALT_AWAITING_CONFIRMATION], true));
            });
            if ($images->isEmpty()) {
                continue;
            }
            $actions = [];
            if ($images->contains(fn (Image $image): bool => $image->needs_shopify_image_sync && ! $product->isApprovedByTwo())) {
                $actions[] = 'Approve the photograph change';
            }
            if ($images->contains(fn (Image $image): bool => (bool) $image->shopify_alt_pending || $image->alt_text_status === Image::ALT_PENDING_REVIEW)) {
                $actions[] = 'Approve or push the alt text';
            }
            if ($images->contains(fn (Image $image): bool => $image->alt_text_status === Image::ALT_FAILED || $image->shopify_media_create_uncertain || $image->needs_shopify_image_sync)) {
                $actions[] = 'Complete the Shopify push or recovery';
            }
            $since = $images->min(fn (Image $image) => $image->image_attention_since);
            $rows[(int) $product->id] = [
                'product_id' => (int) $product->id,
                'sku' => (string) ($product->variants->sortBy('id')->first()?->sku ?: $product->handle),
                'title' => (string) $product->title,
                'actions' => array_values(array_unique($actions)),
                'status' => $product->isApprovedByTwo() ? 'approved, publication outstanding' : 'updated in CMS, not approved',
                'reason' => $images->pluck('shopify_image_sync_error')->filter()->implode(' '),
                'attention_since' => $since,
            ];
        }

        $failedTasks = DB::table('stack_image_sync_tasks')
            ->whereIn('phase', ['failed', 'blocked', 'exhausted', 'confirming', 'alt_conflict'])
            ->where('updated_at', '<=', $cutoff)
            ->get();
        foreach ($failedTasks as $task) {
            $product = Product::query()->with('variants')->find($task->stack_product_id);
            if (! $product instanceof Product) {
                continue;
            }
            $existing = $rows[(int) $product->id] ?? [
                'product_id' => (int) $product->id,
                'sku' => (string) ($product->variants->sortBy('id')->first()?->sku ?: $product->handle),
                'title' => (string) $product->title,
                'actions' => [],
                'status' => 'stack synchronization outstanding',
                'reason' => '',
                'attention_since' => $task->updated_at,
            ];
            $existing['actions'][] = 'Recover the stack image synchronization';
            $existing['reason'] = trim($existing['reason'].' '.(string) $task->last_error);
            $existing['attention_since'] = min($existing['attention_since'], $task->updated_at);
            $rows[(int) $product->id] = $existing;
        }

        return array_values($rows);
    }

    public function note(Product $stack, ?Image $image, ?Image $source, string $outcome, string $detail, bool $verified, ?int $approvalRequestId = null): void
    {
        DB::table('stack_image_sync_ledger')->insert([
            'stack_product_id' => $stack->id,
            'image_id' => $image?->id,
            'source_component_product_id' => $source?->product_id,
            'source_image_id' => $source?->id,
            'cms_photograph_version' => $source ? $this->version($source, 'photograph_version') : ($image ? $this->version($image, 'photograph_version') : null),
            'cms_alt_version' => $source ? $this->version($source, 'alt_text_version') : ($image ? $this->version($image, 'alt_text_version') : null),
            'authorized_photograph_version' => $source && $this->photographAuthorized($source) ? $this->version($source, 'photograph_version') : null,
            'authorized_alt_version' => $source && $this->altAuthorized($source) ? $this->version($source, 'alt_text_version') : null,
            'verified_photograph_version' => $verified && $source ? $this->version($source, 'photograph_version') : null,
            'verified_alt_version' => $verified && $source && $this->altAuthorized($source) ? $this->version($source, 'alt_text_version') : null,
            'shopify_media_id' => $image ? trim((string) $image->shopify_id) : null,
            'verified_at' => $verified ? now() : null,
            'approval_request_id' => $approvalRequestId,
            'outcome' => $outcome,
            'detail' => $detail,
            'baseline_verified' => $outcome === 'baseline_verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function version(Image $image, string $column): int
    {
        $value = (int) ($image->{$column} ?? 0);

        return $value > 0 ? $value : 1;
    }

    private function recordAuthorizations(Product $product, string $scope, ?int $approvalRequestId, ?int $userId): void
    {
        foreach ($product->allImages()->active()->with('imageAsset')->get() as $image) {
            if ($scope === 'image_alt' && trim((string) $image->alt_text) === '') {
                continue;
            }
            if (in_array($scope, ['images', 'full'], true) && ! $image->imageAsset) {
                continue;
            }
            DB::table('component_image_authorizations')->insert([
                'product_id' => $product->id,
                'image_id' => $image->id,
                'image_asset_id' => $image->image_asset_id,
                'asset_sha256' => $image->imageAsset?->sha256,
                'photograph_version' => $this->version($image, 'photograph_version'),
                'alt_text_version' => $this->version($image, 'alt_text_version'),
                'alt_text' => $image->alt_text,
                'scope' => $scope,
                'approval_request_id' => $approvalRequestId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array{outcome:string, uploads:array<int, int>, alt_only:array<int, int>, retire:array<int, int>, reorder:bool, detail:string}
     */
    private function applyAuthorizedPlan(Product $stack): array
    {
        $plan = $this->plan($stack);
        if ($plan['outcome'] === 'no_changes_required') {
            $this->note($stack, null, null, 'no_changes_required', $plan['detail'], true);

            return $plan;
        }
        $updater = app(ProductShopifyUpdater::class);
        $writer = app(ImageAltTextBackfillService::class);
        foreach ($plan['uploads'] as $imageId) {
            $image = Image::query()->with('imageAsset')->find($imageId);
            $source = $image ? Image::query()->find((int) $image->source_image_id) : null;
            if (! $image instanceof Image || ! $this->stackIsActive($stack->fresh() ?? $stack)) {
                $this->note($stack, $image, $source, 'deferred_inactive', 'Stack status changed before the photograph mutation.', false);

                return $this->emptyPlan('deferred_inactive', 'Stack status changed before the photograph mutation.');
            }
            try {
                $result = $updater->synchronizeInheritedStackImage($stack, $imageId);
                $image->refresh();
                $confirmed = (bool) ($result['confirmed'] ?? false);
                $this->note(
                    $stack,
                    $image,
                    $source,
                    $confirmed ? 'verified' : 'uncertain',
                    $confirmed ? 'Photograph verified on Shopify.' : collect($result['warnings'] ?? [])->pluck('warning')->implode(' '),
                    $confirmed,
                );
                if (! $confirmed) {
                    $plan['outcome'] = 'uncertain';
                }
            } catch (\Throwable $exception) {
                $this->note($stack, $image, $source, 'failed', $exception->getMessage(), false);
                $plan['outcome'] = 'failed';
                $plan['detail'] = $exception->getMessage();
            }
        }
        foreach ($plan['alt_only'] as $imageId) {
            $image = Image::query()->find($imageId);
            $source = $image ? Image::query()->find((int) $image->source_image_id) : null;
            if (! $image instanceof Image) {
                continue;
            }
            $result = $writer->publishInheritedCopy($image);
            $image->refresh();
            $failed = ($result['failed'] ?? []) !== [];
            $verified = ! $failed && ((int) ($result['published'] ?? 0) > 0 || trim((string) $image->shopify_alt_text) === trim((string) $source?->alt_text));
            $this->note($stack, $image, $source, $verified ? 'verified' : 'failed', $verified ? 'Alt text verified without a new photograph upload.' : 'Alt text was not verified.', $verified);
            if (! $verified) {
                $plan['outcome'] = 'failed';
            }
        }
        if ($plan['reorder']) {
            $warnings = $updater->reorderStackMedia($stack->fresh() ?? $stack);
            $this->note($stack, null, null, $warnings === [] ? 'verified' : 'failed', $warnings === [] ? 'Image order verified.' : collect($warnings)->pluck('warning')->implode(' '), $warnings === []);
        }
        if ($plan['outcome'] === 'shopify_changes_required' || $plan['outcome'] === 'alt_text_only' || $plan['outcome'] === 'order_only') {
            $latest = DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->orderByDesc('id')->value('outcome');
            if ($latest === 'verified') {
                $plan['outcome'] = 'verified';
            }
        }

        return $plan;
    }

    private function sourceStillMember(Product $stack, Image $source): bool
    {
        return in_array((int) $source->product_id, app(StackComponentCatalog::class)->componentIds($stack), true);
    }

    private function verified(Image $image, string $column): ?int
    {
        $value = DB::table('stack_image_sync_ledger')
            ->where('image_id', $image->id)
            ->where('outcome', 'verified')
            ->orderByDesc('id')
            ->value($column);

        return $value === null ? null : (int) $value;
    }

    /**
     * @return array{outcome:string, uploads:array<int, int>, alt_only:array<int, int>, retire:array<int, int>, reorder:bool, detail:string}
     */
    private function emptyPlan(string $outcome, string $detail): array
    {
        return ['outcome' => $outcome, 'uploads' => [], 'alt_only' => [], 'retire' => [], 'reorder' => false, 'detail' => $detail];
    }
}
