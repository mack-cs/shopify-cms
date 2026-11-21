<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use App\Jobs\RebuildDependentStackImagesJob;
use App\Jobs\SynchronizeAuthorizedStackImagesJob;
use App\Models\Approval;
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
        $snapshot = $this->publicationSnapshot($product);
        if (! is_array($snapshot)) {
            return;
        }
        $this->authorizePublicationSnapshot($product, $snapshot, $userId);
        $this->queueBlindPublication($product, null);
    }

    /**
     * The first approval of a product version is the publication request.
     * Later edits must not rewrite it.
     */
    public function capturePublicationSnapshot(Product $product, Approval $approval): void
    {
        $version = (int) ($approval->approval_version ?? $product->approval_version ?? 1);
        if (DB::table('publication_authorization_snapshots')
            ->where('product_id', $product->id)
            ->where('approval_version', $version)
            ->exists()) {
            return;
        }
        $images = [];
        foreach ($product->allImages()->active()->with('imageAsset')->orderBy('id')->get() as $image) {
            $image->loadMissing('imageAsset');
            $images[] = [
                'image_id' => (int) $image->id,
                'product_id' => (int) $product->id,
                'image_asset_id' => (int) ($image->image_asset_id ?? 0),
                'asset_sha256' => (string) ($image->imageAsset->sha256 ?? ''),
                'photograph_version' => $this->version($image, 'photograph_version'),
                'alt_text' => (string) $image->alt_text,
                'alt_text_version' => $this->version($image, 'alt_text_version'),
            ];
        }
        DB::table('publication_authorization_snapshots')->insertOrIgnore([
            'product_id' => $product->id,
            'approval_version' => $version,
            'approval_id' => $approval->id,
            'snapshot' => json_encode([
                'product_id' => (int) $product->id,
                'approval_version' => $version,
                'images' => $images,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publicationSnapshot(Product $product): ?array
    {
        $row = DB::table('publication_authorization_snapshots')
            ->where('product_id', $product->id)
            ->where('approval_version', (int) ($product->approval_version ?? 1))
            ->first();
        if ($row === null) {
            return null;
        }
        $decoded = json_decode((string) $row->snapshot, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Closes the publication cycle for one target image after the authorized
     * content was verified. Authorization rows and other stacks are left intact.
     */
    public function completeVerifiedPublication(Image $published, ?Image $source, string $scope): void
    {
        $published = $published->fresh() ?? $published;
        $updates = [];
        if (in_array($scope, ['images', 'full'], true)
            && $source instanceof Image
            && $this->photographAuthorized($source)
            && (int) $published->image_asset_id === (int) $source->image_asset_id
            && (int) $published->image_asset_id > 0) {
            $updates['needs_shopify_image_sync'] = false;
        }
        if (in_array($scope, ['image_alt', 'full'], true)) {
            $target = $this->authorizedAltTarget($published, $source);
            if ($target !== null
                && trim((string) $published->alt_text) === $target
                && trim((string) $published->shopify_alt_text) === $target) {
                $updates['shopify_alt_pending'] = false;
                $updates['alt_text_status'] = Image::ALT_PUBLISHED;
                $updates['alt_confirmation_media_id'] = null;
                $updates['alt_confirmation_text'] = null;
            }
        }
        if ($updates === []) {
            return;
        }
        Image::withoutEvents(fn () => $published->forceFill($updates)->save());
    }

    public function afterPartialApproval(ProductPartialApprovalRequest $request): void
    {
        $product = $request->product;
        if (! $product instanceof Product || $request->status !== ProductPartialApprovalRequest::STATUS_APPROVED) {
            return;
        }
        $scopes = is_array($request->scopes) ? $request->scopes : [];
        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_IMAGES, $scopes, true)) {
            $this->recordAuthorizations($product, 'images', (int) $request->id, (int) $request->approved_by, $request);
        }
        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT, $scopes, true)) {
            $this->recordAuthorizations($product, 'image_alt', (int) $request->id, (int) $request->approved_by, $request);
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
            if (app(StackComponentCatalog::class)->membershipState($stack) === 'unknown') {
                $plan = $this->emptyPlan('uncertain', 'Stack membership is unknown. Inherited photographs were left in place and Shopify was not changed.');
                $this->note($stack, null, null, $plan['outcome'], $plan['detail'], false);
            } elseif (! $this->stackIsActive($stack)) {
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
        if (app(StackComponentCatalog::class)->membershipState($stack) === 'unknown') {
            return $this->emptyPlan('uncertain', 'Stack membership is unknown. Inherited photographs were left in place and Shopify was not changed.');
        }
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
            if ((int) $image->image_asset_id !== (int) $source->image_asset_id) {
                if (trim((string) $image->shopify_id) !== '' && $image->sync_state !== Image::SYNC_STATE_LOCAL_DELETED) {
                    $retire[] = (int) $image->id;
                }
                continue;
            }
            $photoAuthorized = $this->photographAuthorized($source) || $this->stackCapturedPhotograph($image);
            $publishedHere = trim((string) $image->shopify_id) !== ''
                && (int) $image->image_asset_id === (int) $source->image_asset_id
                && ! $image->needs_shopify_image_sync;
            if (! $photoAuthorized && ! $publishedHere) {
                continue;
            }
            $photoVersion = $this->version($source, 'photograph_version');
            $verifiedPhoto = $this->verified($image, 'verified_photograph_version');
            $photoChanged = $image->needs_shopify_image_sync
                || trim((string) $image->shopify_id) === ''
                || (int) $image->image_asset_id !== (int) $source->image_asset_id
                || $verifiedPhoto === null
                || $verifiedPhoto !== $photoVersion;
            $altTarget = $this->authorizedAltTarget($image, $source);
            if ($altTarget === null && $this->altAuthorized($source)) {
                $altTarget = trim((string) $source->alt_text);
            }
            $altChanged = $altTarget !== null && trim((string) $image->shopify_alt_text) !== $altTarget;
            if ($photoChanged && $photoAuthorized) {
                $uploads[] = (int) $image->id;
            } elseif ($altChanged && trim((string) $image->shopify_id) !== '') {
                $altOnly[] = (int) $image->id;
            } elseif (! $photoChanged && (int) $image->position !== (int) $source->position && trim((string) $image->shopify_id) !== '') {
                $reorder = true;
            }
        }
        if ($uploads === [] && $altOnly === [] && $retire === [] && ! $reorder) {
            return $this->emptyPlan('no_changes_required', 'Local authorization receipts match the current CMS photograph and alt versions. Shopify has not been read.');
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
        if (! $source->imageAsset) {
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

    /**
     * The sentence this inherited row may publish. Following rows use the
     * approved source sentence. Independent rows use their own approval.
     */
    public function authorizedAltTarget(Image $image, ?Image $source = null): ?string
    {
        $approvals = app(ProductPartialApprovalService::class);
        if ($image->alt_inheritance_mode === 'independent') {
            return $approvals->approvedAlt($image);
        }
        if (! $source instanceof Image) {
            $source = Image::query()->find((int) $image->source_image_id);
        }
        if (! $source instanceof Image) {
            return null;
        }
        $approved = $approvals->approvedAlt($source);

        return $approved !== null && trim($approved) === trim((string) $source->alt_text) ? $approved : null;
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
        } elseif (in_array($outcome, ['shopify_authorization_pending', 'deferred_inactive', 'no_eligible_work', 'retirement_pending'], true)) {
            $phase = 'shopify_update_pending_authorization';
        } elseif ($outcome === 'retirement_confirmed') {
            $phase = 'retirement_confirmed';
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

    public function note(Product $stack, ?Image $image, ?Image $source, string $outcome, string $detail, bool $verified, ?int $approvalRequestId = null, bool $photographVerified = false, bool $altVerified = false): void
    {
        $altImage = $image instanceof Image && $image->alt_inheritance_mode === 'independent' ? $image : $source;
        DB::table('stack_image_sync_ledger')->insert([
            'stack_product_id' => $stack->id,
            'image_id' => $image?->id,
            'source_component_product_id' => $source?->product_id,
            'source_image_id' => $source?->id,
            'cms_photograph_version' => $source ? $this->version($source, 'photograph_version') : ($image ? $this->version($image, 'photograph_version') : null),
            'cms_alt_version' => $source ? $this->version($source, 'alt_text_version') : ($image ? $this->version($image, 'alt_text_version') : null),
            'authorized_photograph_version' => $source && $this->photographAuthorized($source) ? $this->version($source, 'photograph_version') : null,
            'authorized_alt_version' => $source && $this->altAuthorized($source) ? $this->version($source, 'alt_text_version') : null,
            'verified_photograph_version' => $verified && $photographVerified && $source ? $this->version($source, 'photograph_version') : null,
            'verified_alt_version' => $verified && $altVerified && $altImage ? $this->version($altImage, 'alt_text_version') : null,
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

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function authorizePublicationSnapshot(Product $product, array $snapshot, ?int $userId): void
    {
        $rows = $snapshot['images'] ?? null;
        if (! is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $image = Image::query()->with('imageAsset')->find((int) ($row['image_id'] ?? 0));
            if (! $image instanceof Image || (int) $image->product_id !== (int) $product->id) {
                continue;
            }
            $photoMatches = (int) ($row['image_asset_id'] ?? 0) > 0
                && (string) ($row['asset_sha256'] ?? '') !== ''
                && (int) $image->image_asset_id === (int) $row['image_asset_id']
                && (string) ($image->imageAsset->sha256 ?? '') === (string) $row['asset_sha256']
                && $this->version($image, 'photograph_version') === (int) ($row['photograph_version'] ?? 0);
            $altMatches = trim((string) ($row['alt_text'] ?? '')) !== ''
                && trim((string) $image->alt_text) === trim((string) $row['alt_text'])
                && $this->version($image, 'alt_text_version') === (int) ($row['alt_text_version'] ?? 0);
            if ($photoMatches && $altMatches) {
                $this->insertAuthorization($product, $image, 'full', null, $userId, trim((string) $row['alt_text']));

                continue;
            }
            if ($photoMatches) {
                $this->insertAuthorization($product, $image, 'images', null, $userId, (string) $image->alt_text);
            }
            if ($altMatches) {
                $this->insertAuthorization($product, $image, 'image_alt', null, $userId, trim((string) $row['alt_text']));
            }
        }
    }

    private function recordAuthorizations(Product $product, string $scope, ?int $approvalRequestId, ?int $userId, ?ProductPartialApprovalRequest $request = null): void
    {
        if ($request instanceof ProductPartialApprovalRequest && $scope === 'image_alt') {
            foreach ($this->reviewedAltRows($request) as $row) {
                $image = Image::query()->with('imageAsset')->find((int) ($row['image_id'] ?? 0));
                if (! $image instanceof Image || ! app(ImageAltApprovalEvidence::class)->snapshotMatches($image, $row, $request)) {
                    continue;
                }
                $this->insertAuthorization($product, $image, $scope, $approvalRequestId, $userId, trim((string) ($row['proposed_alt'] ?? '')));
            }

            return;
        }
        if ($request instanceof ProductPartialApprovalRequest && $scope === 'images') {
            $rows = is_array($request->image_alt_snapshot) ? ($request->image_alt_snapshot['photographs'] ?? null) : null;
            if (! is_array($rows)) {
                return;
            }
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $image = Image::query()->with('imageAsset')->find((int) ($row['image_id'] ?? 0));
                    if (! $image instanceof Image || ! $image->imageAsset) {
                        continue;
                    }
                    if ((int) $image->image_asset_id !== (int) ($row['image_asset_id'] ?? 0)
                        || (string) $image->imageAsset->sha256 !== (string) ($row['asset_sha256'] ?? '')
                        || $this->version($image, 'photograph_version') !== (int) ($row['photograph_version'] ?? 0)) {
                        continue;
                    }
                    $this->insertAuthorization($product, $image, $scope, $approvalRequestId, $userId, (string) $image->alt_text);
                }

                return;
            }
        }
        foreach ($product->allImages()->active()->with('imageAsset')->get() as $image) {
            if ($scope === 'image_alt' && trim((string) $image->alt_text) === '') {
                continue;
            }
            if (in_array($scope, ['images', 'full'], true) && ! $image->imageAsset) {
                continue;
            }
            $this->insertAuthorization($product, $image, $scope, $approvalRequestId, $userId, (string) $image->alt_text);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reviewedAltRows(ProductPartialApprovalRequest $request): array
    {
        $images = $request->image_alt_snapshot['images'] ?? null;
        if (! is_array($images)) {
            return [];
        }

        return array_values(array_filter($images, function ($row): bool {
            return is_array($row) && empty($row['inherited']) && ! empty($row['changed']);
        }));
    }

    private function insertAuthorization(Product $product, Image $image, string $scope, ?int $approvalRequestId, ?int $userId, string $altText): void
    {
        $image->loadMissing('imageAsset');
        DB::table('component_image_authorizations')->insert([
            'product_id' => $product->id,
            'image_id' => $image->id,
            'image_asset_id' => $image->image_asset_id,
            'asset_sha256' => $image->imageAsset?->sha256,
            'photograph_version' => $this->version($image, 'photograph_version'),
            'alt_text_version' => $this->version($image, 'alt_text_version'),
            'alt_text' => $altText,
            'scope' => $scope,
            'approval_request_id' => $approvalRequestId,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{outcome:string, uploads:array<int, int>, alt_only:array<int, int>, retire:array<int, int>, reorder:bool, detail:string}
     */
    private function applyAuthorizedPlan(Product $stack): array
    {
        $plan = $this->plan($stack);
        if ($plan['outcome'] === 'no_changes_required') {
            return $this->verifyUnchangedGallery($stack);
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
            $source?->loadMissing('imageAsset');
            if (! $source instanceof Image || ! $source->imageAsset?->isAvailable() || (int) $image->image_asset_id !== (int) $source->image_asset_id) {
                $reason = (int) $image->image_asset_id !== (int) ($source?->image_asset_id ?? 0)
                    ? 'The stack photograph is not the authorized component photograph. Shopify was not changed.'
                    : 'The authorized photograph backup is missing. Shopify was not changed.';
                $this->note($stack, $image, $source, 'failed', $reason, false);
                $plan['outcome'] = 'failed';
                $plan['detail'] = $reason;

                continue;
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
                    null,
                    $confirmed,
                    false,
                );
                if ($confirmed) {
                    $this->completeVerifiedPublication($image, $source, 'images');
                }
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
            $target = $this->authorizedAltTarget($image, $source instanceof Image ? $source : null);
            $verified = ! $failed && $target !== null && trim((string) $image->shopify_alt_text) === $target;
            $this->note($stack, $image, $source, $verified ? 'verified' : 'failed', $verified ? 'Alt text verified without a new photograph upload.' : 'Alt text was not verified.', $verified, null, false, $verified);
            if ($verified) {
                $this->completeVerifiedPublication($image, $source instanceof Image ? $source : null, 'image_alt');
            }
            if (! $verified) {
                $plan['outcome'] = 'failed';
            }
        }
        if ($plan['reorder']) {
            $warnings = $updater->reorderStackMedia($stack->fresh() ?? $stack);
            $this->note($stack, null, null, $warnings === [] ? 'verified' : 'failed', $warnings === [] ? 'Image order verified.' : collect($warnings)->pluck('warning')->implode(' '), $warnings === []);
            if ($warnings !== []) {
                $plan['outcome'] = 'failed';
            }
        }
        [$retirement, $retiredMediaIds] = $this->applyPlannedRetirements($stack, $plan['retire']);
        if ($retirement === 'failed' || $plan['outcome'] === 'failed') {
            $plan['outcome'] = 'failed';
        } elseif ($retirement === 'uncertain' || $plan['outcome'] === 'uncertain') {
            $plan['outcome'] = 'uncertain';
        } elseif ($retirement === 'deferred') {
            $plan['outcome'] = 'retirement_pending';
        } elseif ($retirement === 'confirmed' && ! in_array($plan['outcome'], ['failed', 'uncertain'], true) && $plan['uploads'] === [] && $plan['alt_only'] === []) {
            $plan['outcome'] = 'retirement_confirmed';
        } elseif (in_array($plan['outcome'], ['shopify_changes_required', 'alt_text_only', 'order_only'], true)) {
            $latest = DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->orderByDesc('id')->value('outcome');
            if ($latest === 'verified') {
                $plan['outcome'] = 'verified';
            }
        }
        if (in_array($plan['outcome'], ['verified', 'retirement_confirmed'], true)) {
            $plan = $this->requireCompleteGallery($stack->fresh() ?? $stack, $plan, $retiredMediaIds);
        }

        return $plan;
    }

    /**
     * @param  array<int, int>  $imageIds
     * @return array{0: 'none'|'confirmed'|'deferred'|'failed'|'uncertain'|'preserved', 1: array<int, string>}
     */
    private function applyPlannedRetirements(Product $stack, array $imageIds): array
    {
        $result = 'none';
        $retiredMediaIds = [];
        $propagation = app(ComponentStackPropagation::class);
        foreach ($imageIds as $imageId) {
            $image = Image::query()->find($imageId);
            if (! $image instanceof Image) {
                continue;
            }
            $source = Image::query()->find((int) $image->source_image_id);
            $mediaId = trim((string) $image->shopify_id);
            $status = $propagation->retirePlannedAssociation($stack, $image);
            if ($status === 'confirmed' && $mediaId !== '') {
                $retiredMediaIds[] = $mediaId;
            }
            $this->note(
                $stack,
                $image->fresh() ?? $image,
                $source instanceof Image ? $source : null,
                $status === 'confirmed' ? 'retirement_confirmed' : ($status === 'uncertain' ? 'uncertain' : ($status === 'preserved' ? 'preserved' : ($status === 'deferred' ? 'retirement_pending' : 'failed'))),
                match ($status) {
                    'confirmed' => 'Shopify read-back confirmed the obsolete association is gone.',
                    'uncertain' => 'Retirement mutation is uncertain and will not be replayed.',
                    'preserved' => 'A current component still requires this photograph. It was not removed.',
                    'deferred' => 'Retirement waited for a confirmed replacement or an active stack.',
                    default => 'Shopify retirement did not complete. Published media was left unchanged.',
                },
                $status === 'confirmed',
            );
            if ($status === 'failed') {
                $result = 'failed';
            } elseif ($status === 'uncertain' && $result !== 'failed') {
                $result = 'uncertain';
            } elseif ($status === 'deferred' && ! in_array($result, ['failed', 'uncertain'], true)) {
                $result = 'deferred';
            } elseif ($status === 'confirmed' && ! in_array($result, ['failed', 'uncertain', 'deferred'], true)) {
                $result = 'confirmed';
            }
        }

        return [$result, $retiredMediaIds];
    }

    /**
     * A photograph receipt or retirement mutation is not a verified gallery.
     * Overall success waits for one complete remote read.
     *
     * @param  array{outcome:string, uploads:array<int, int>, alt_only:array<int, int>, retire:array<int, int>, reorder:bool, detail:string}  $plan
     * @param  array<int, string>  $retiredMediaIds
     * @return array{outcome:string, uploads:array<int, int>, alt_only:array<int, int>, retire:array<int, int>, reorder:bool, detail:string}
     */
    private function requireCompleteGallery(Product $stack, array $plan, array $retiredMediaIds): array
    {
        $check = $this->verifyRequiredGallery($stack, $retiredMediaIds);
        if ($check['outcome'] === 'uncertain') {
            $plan['outcome'] = 'uncertain';
            $plan['detail'] = $check['detail'];

            return $plan;
        }
        if ($check['outcome'] === 'failed') {
            $plan['outcome'] = 'failed';
            $plan['detail'] = $check['detail'];

            return $plan;
        }
        if ($plan['outcome'] === 'verified' && $check['outcome'] !== 'verified') {
            $plan['outcome'] = 'failed';
            $plan['detail'] = 'Shopify read-back did not confirm the authorized photograph and alt text.';
        }

        return $plan;
    }

    /**
     * @param  array<int, string>  $retiredMediaIds
     * @return array{outcome:string, uploads:array<int, int>, alt_only:array<int, int>, retire:array<int, int>, reorder:bool, detail:string}
     */
    private function verifyRequiredGallery(Product $stack, array $retiredMediaIds): array
    {
        $productId = trim((string) $stack->shopify_id);
        try {
            if ($productId === '') {
                throw new \RuntimeException('The stack has no Shopify product id to read.');
            }
            $nodes = (new ShopifyProductMediaReader(app(ShopifyGraphqlGateway::class)))->media($productId, 'StackGalleryVerification');
        } catch (\Throwable $exception) {
            $plan = $this->emptyPlan('uncertain', 'Shopify gallery evidence is incomplete. Nothing was marked verified. '.$exception->getMessage());
            $this->note($stack, null, null, $plan['outcome'], $plan['detail'], false);

            return $plan;
        }
        foreach ($retiredMediaIds as $mediaId) {
            if (collect($nodes)->contains(fn (array $node): bool => ($node['id'] ?? null) === $mediaId)) {
                $plan = $this->emptyPlan('failed', 'Shopify still shows media that was supposed to be retired. The gallery was not marked verified.');
                $this->note($stack, null, null, $plan['outcome'], $plan['detail'], false);

                return $plan;
            }
        }
        $images = $stack->allImages()->with('imageAsset')->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->orderBy('id')->get()
            ->filter(function (Image $image): bool {
                $source = Image::query()->find((int) $image->source_image_id);

                return ($source instanceof Image && $this->photographAuthorized($source)) || $this->stackCapturedPhotograph($image);
            })
            ->values();
        if ($images->isEmpty()) {
            return $this->emptyPlan('read_complete', 'Shopify gallery read completed. No authorized photograph remained to verify.');
        }
        $matched = true;
        foreach ($images as $image) {
            $source = Image::query()->find((int) $image->source_image_id);
            $node = collect($nodes)->first(fn (array $candidate): bool => ($candidate['id'] ?? null) === $image->shopify_id);
            $photographMatches = is_array($node)
                && ShopifyProductMediaReader::ready($node)
                && app(ImagePhotographEvidence::class)->matchesMedia($image, $node);
            $altTarget = $source instanceof Image || $image->alt_inheritance_mode === 'independent'
                ? $this->authorizedAltTarget($image, $source instanceof Image ? $source : null)
                : null;
            if ($altTarget === null && $source instanceof Image && $this->altAuthorized($source)) {
                $altTarget = trim((string) $source->alt_text);
            }
            $altRequired = $image->alt_inheritance_mode === 'independent'
                ? $altTarget !== null
                : ($source instanceof Image && $this->altAuthorized($source));
            $altMatches = ! $altRequired || (is_array($node) && trim((string) ($node['alt'] ?? '')) === $altTarget);
            if (! $photographMatches || ! $altMatches) {
                $matched = false;
                if (! $photographMatches) {
                    Image::withoutEvents(fn () => $image->forceFill(['needs_shopify_image_sync' => true])->save());
                } elseif (is_array($node)) {
                    Image::withoutEvents(fn () => $image->forceFill([
                        'shopify_alt_text' => trim((string) ($node['alt'] ?? '')),
                        'shopify_alt_pending' => true,
                    ])->save());
                }
                $this->note($stack, $image, $source instanceof Image ? $source : null, 'failed', 'Shopify read-back did not match the authorized photograph and alt text. The gallery was not marked verified.', false, null, $photographMatches, false);

                continue;
            }
            $this->note(
                $stack,
                $image,
                $source instanceof Image ? $source : null,
                'verified',
                $altRequired ? 'Shopify read-back matched the authorized photograph and alt text.' : 'Shopify read-back matched the authorized photograph. Alt text was not part of this verification.',
                true,
                null,
                true,
                $altRequired && $altMatches,
            );
        }
        if (! $matched) {
            return $this->emptyPlan('failed', 'Shopify read-back did not match the authorized photograph and alt text.');
        }

        return $this->emptyPlan('verified', 'Shopify read-back matched the authorized gallery.');
    }

    /**
     * @return array{outcome:string, uploads:array<int, int>, alt_only:array<int, int>, retire:array<int, int>, reorder:bool, detail:string}
     */
    private function verifyUnchangedGallery(Product $stack): array
    {
        $images = $stack->allImages()->with('imageAsset')->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->orderBy('id')->get();
        if ($images->isEmpty()) {
            $plan = $this->emptyPlan('no_eligible_work', 'The stack has no inherited photographs to verify.');
            $this->note($stack, null, null, $plan['outcome'], $plan['detail'], false);

            return $plan;
        }
        $unauthorized = $images->contains(function (Image $image): bool {
            $source = Image::query()->find((int) $image->source_image_id);

            return ! ($source instanceof Image && $this->photographAuthorized($source)) && ! $this->stackCapturedPhotograph($image);
        });
        if ($unauthorized) {
            $plan = $this->emptyPlan('shopify_authorization_pending', 'The gallery has no current photograph authorization, so it was not marked verified.');
            $this->note($stack, null, null, $plan['outcome'], $plan['detail'], false);

            return $plan;
        }
        $productId = trim((string) $stack->shopify_id);
        try {
            if ($productId === '') {
                throw new \RuntimeException('The stack has no Shopify product id to read.');
            }
            $nodes = (new ShopifyProductMediaReader(app(ShopifyGraphqlGateway::class)))->media($productId, 'StackGalleryVerification');
        } catch (\Throwable $exception) {
            $plan = $this->emptyPlan('uncertain', 'Shopify gallery evidence is incomplete. Nothing was marked verified. '.$exception->getMessage());
            $this->note($stack, null, null, $plan['outcome'], $plan['detail'], false);

            return $plan;
        }
        $matched = true;
        foreach ($images as $image) {
            $source = Image::query()->find((int) $image->source_image_id);
            $node = collect($nodes)->first(fn (array $candidate): bool => ($candidate['id'] ?? null) === $image->shopify_id);
            $photographMatches = is_array($node)
                && ShopifyProductMediaReader::ready($node)
                && app(ImagePhotographEvidence::class)->matchesMedia($image, $node);
            $altTarget = $source instanceof Image || $image->alt_inheritance_mode === 'independent'
                ? $this->authorizedAltTarget($image, $source instanceof Image ? $source : null)
                : null;
            if ($altTarget === null && $source instanceof Image && $this->altAuthorized($source)) {
                $altTarget = trim((string) $source->alt_text);
            }
            $altRequired = $image->alt_inheritance_mode === 'independent'
                ? $altTarget !== null
                : ($source instanceof Image && $this->altAuthorized($source));
            $altMatches = ! $altRequired || (is_array($node) && trim((string) ($node['alt'] ?? '')) === $altTarget);
            if (! $photographMatches || ! $altMatches) {
                $matched = false;
                if (! $photographMatches) {
                    Image::withoutEvents(fn () => $image->forceFill(['needs_shopify_image_sync' => true])->save());
                } elseif (is_array($node)) {
                    Image::withoutEvents(fn () => $image->forceFill([
                        'shopify_alt_text' => trim((string) ($node['alt'] ?? '')),
                        'shopify_alt_pending' => true,
                    ])->save());
                }
                $this->note($stack, $image, $source instanceof Image ? $source : null, 'failed', 'Shopify read-back did not match the authorized gallery. It was not marked verified.', false);

                continue;
            }
            $this->note(
                $stack,
                $image,
                $source instanceof Image ? $source : null,
                'verified',
                $altRequired ? 'Shopify read-back matched the authorized photograph and alt text.' : 'Shopify read-back matched the authorized photograph. Alt text was not part of this verification.',
                true,
                null,
                true,
                $altRequired && $altMatches,
            );
        }
        if (! $matched) {
            return $this->emptyPlan('failed', 'Shopify read-back did not match the authorized gallery.');
        }

        return $this->emptyPlan('verified', 'Shopify read-back matched the authorized gallery.');
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
