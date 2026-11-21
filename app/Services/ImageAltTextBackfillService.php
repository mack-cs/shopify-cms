<?php

namespace App\Services;

use App\Models\Image;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

final class ImageAltTextBackfillService
{
    public function __construct(
        private readonly ShopifyCollectionMembership $collections,
        private readonly ImageAltTextShopifyWriter $shopify,
        private readonly ShopifyMediaImageResolver $media,
        private readonly ProductPartialApprovalService $approvals,
    ) {}

    public function downloadActive(): StreamedResponse
    {
        return $this->export()['response'];
    }

    /** Worker-only iterator: bounded model chunks; no catalogue-wide image collection. */
    public function snapshotRows(array $collectionGids): \Generator
    {
        $products = Product::query()->activeStatus()->with(['variants' => fn ($query) => $query->orderBy('id')]);
        if ($collectionGids !== []) {
            $gids = $this->collections->activeProductGids($collectionGids);
            $products->whereIn('shopify_id', $gids === [] ? [''] : $gids);
        }
        foreach ($products->lazyById(100) as $product) {
            $images = $product->allImages()->active()->with('imageAsset');
            foreach ($images->lazyById(100) as $image) {
                yield $this->csvRow($product, $image);
            }
        }
    }

    /**
     * @param  array<int, string>  $collectionGids
     * @return array{exceptions: array<int, array<string, mixed>>, response: StreamedResponse}
     */
    public function export(array $collectionGids = [], bool $includeImages = false): array
    {
        $built = $this->build($collectionGids);
        $filename = 'image-alt-text-'.now()->format('Y-m-d');

        if (! $includeImages) {
            return [
                'exceptions' => $built['exceptions'],
                'response' => $this->csvResponse($built['rows'], $filename.'.csv'),
            ];
        }

        $zip = $this->zipResponse($built['rows'], $built['exceptions'], $filename.'.zip');

        return [
            'exceptions' => array_merge($built['exceptions'], $zip['failures']),
            'response' => $zip['response'],
        ];
    }

    /**
     * @return array{updated:int, unchanged:int, skipped_blank:int, excluded:int, propagated:int, failed:array<int, array<string, mixed>>, shopify_failed:array<int, array<string, mixed>>, applied:bool}
     */
    public function importFromPath(string $path, bool $apply = false): array
    {
        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 2);
        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($magic === 'PK') {
            return $this->import($this->csvFromXlsx($path), $apply);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('The alt-text file could not be read.');
        }

        return $this->import($contents, $apply);
    }

    /**
     * @return array{updated:int, unchanged:int, skipped_blank:int, excluded:int, propagated:int, failed:array<int, array<string, mixed>>, shopify_failed:array<int, array<string, mixed>>, applied:bool}
     */
    public function import(string $contents, bool $apply = false): array
    {
        $table = $this->table($contents);
        if (! in_array('image_id', $table['headers'], true)) {
            throw new RuntimeException('This file has no image_id column. Download a new alt text CSV and match rows by image id.');
        }

        $updated = 0;
        $unchanged = 0;
        $skippedBlank = 0;
        $excluded = 0;
        $propagated = 0;
        $failed = [];
        $shopifyFailed = [];
        $seen = [];

        foreach ($table['rows'] as $offset => $row) {
            $rowNumber = $offset + 2;
            $altText = trim((string) ($row['alt_text'] ?? ''));
            if ($altText === '') {
                $skippedBlank++;

                continue;
            }

            $imageId = (int) ($row['image_id'] ?? 0);
            if ($imageId < 1 || isset($seen[$imageId])) {
                $failed[] = $this->failure($rowNumber, $imageId, $imageId < 1
                    ? 'A local image id is required.'
                    : 'This image id was already present in the file.');

                continue;
            }
            $seen[$imageId] = true;

            $image = Image::query()->useWritePdo()->with('product')->find($imageId);
            if (! $image instanceof Image || ! $image->product instanceof Product) {
                $failed[] = $this->failure($rowNumber, $imageId, 'No image matches image id '.$imageId.'.');

                continue;
            }

            $mismatch = $this->mismatch($image, $row);
            if ($mismatch !== null) {
                $failed[] = $this->failure($rowNumber, $imageId, $mismatch);

                continue;
            }

            if ($this->notDirectlyEditable($image)) {
                $excluded++;
                $failed[] = $this->failure($rowNumber, $imageId, 'Stack image '.$imageId.' is inherited from a component. Update the component image instead.');

                continue;
            }

            if (trim((string) $image->alt_text) === $altText) {
                $unchanged++;

                continue;
            }

            $updated++;
            if (! $apply) {
                continue;
            }

            $this->markPendingReview($image, $altText);
        }

        return [
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped_blank' => $skippedBlank,
            'excluded' => $excluded,
            'propagated' => $propagated,
            'failed' => $failed,
            'shopify_failed' => $shopifyFailed,
            'applied' => $apply,
        ];
    }

    /**
     * Saves a staff correction in the CMS. Publishing waits for the product partial-approval workflow.
     */
    public function markPendingReview(Image $image, string $altText): void
    {
        $altText = trim($altText);
        if ($this->notDirectlyEditable($image)) {
            throw new RuntimeException('Stack image '.$image->id.' is inherited from a component. Update the component image instead.');
        }
        if ($altText === '') {
            throw new RuntimeException('Blank alt text is not saved.');
        }

        if (mb_strlen($altText) > 255) {
            throw new RuntimeException('Alt text exceeds the 255 character limit.');
        }
        if ($image->alt_confirmation_media_id !== null && $image->alt_confirmation_text !== $altText) {
            throw new RuntimeException('Resolve the pending Shopify confirmation before changing its sentence.');
        }
        if (trim((string) $image->alt_text) === $altText
            && in_array($image->alt_text_status, [Image::ALT_APPROVED, Image::ALT_PUBLISHED], true)) {
            return;
        }
        if ($this->inheritedStackImage($image)) {
            Image::withoutEvents(fn () => $image->forceFill(['alt_inheritance_mode' => 'independent'])->save());
        }
        $this->writeReviewState($image, $altText, Image::ALT_PENDING_REVIEW);
    }

    /**
     * Stage alt text supplied with an image import.
     * An identical description leaves the current approval state alone.
     * The same imported sentence does not replace a newer CMS edit.
     *
     * @return 'applied'|'unchanged'|'preserved'
     */
    public function stageImportedAlt(Image $image, string $altText): string
    {
        $altText = trim($altText);
        $image = $image->fresh() ?? $image;
        if ($altText === '') {
            throw new RuntimeException('Blank alt text is not saved.');
        }
        if (mb_strlen($altText) > 255) {
            throw new RuntimeException('Alt text exceeds the 255 character limit.');
        }
        if ($this->notDirectlyEditable($image)) {
            throw new RuntimeException('Stack image '.$image->id.' is inherited from a component. Update the component image instead.');
        }

        $current = trim((string) $image->alt_text);
        if ($current === $altText) {
            $this->rememberImportedAlt($image, $altText);

            return 'unchanged';
        }

        if ($image->imported_alt_text !== null && trim((string) $image->imported_alt_text) === $altText) {
            return 'preserved';
        }

        $this->markPendingReview($image, $altText);
        $this->rememberImportedAlt($image, $altText);

        return 'applied';
    }

    public function approvedUploadAlt(Image $image): ?string
    {
        $own = $this->approvals->approvedAlt($image);
        if (! $this->inheritedStackImage($image) || $image->alt_inheritance_mode === 'independent') {
            return $own;
        }
        if (! $this->copyHasCurrentSource($image) || $this->inheritedAltConflicts($image)) {
            return null;
        }
        $source = Image::query()->useWritePdo()->find((int) $image->source_image_id);

        if (($resolution = app(InheritedAltResolution::class)->current($image)) !== null) {
            return $resolution;
        }

        return ($source instanceof Image ? $this->approvals->approvedAlt($source) : null) ?? $own;
    }

    /**
     * Publishes an inherited stack description with fileUpdate. An unapproved
     * source sentence is left in the CMS and is not presented as approved.
     *
     * @return array{published:int, failed:array<int, array{row:int, image_id:int, reason:string}>}
     */
    public function publishInheritedCopy(Image $image): array
    {
        $image = $image->fresh(['product', 'imageAsset']) ?? $image;
        if (! $this->inheritedStackImage($image)) {
            return ['published' => 0, 'failed' => []];
        }
        $approved = app(StackImageSync::class)->authorizedAltTarget($image);
        if ($approved === null) {
            return ['published' => 0, 'failed' => []];
        }
        if (trim((string) $image->alt_text) !== $approved) {
            if ($image->alt_inheritance_mode === 'independent' || $image->alt_confirmation_media_id !== null) {
                return ['published' => 0, 'failed' => [$this->failure(0, (int) $image->id, 'Inherited image '.$image->id.' does not contain the approved source description.')]];
            }
            Image::withoutEvents(fn () => $image->forceFill([
                'alt_text' => $approved,
                'alt_text_version' => max(1, (int) $image->alt_text_version) + 1,
            ])->save());
            $image = $image->fresh(['product', 'imageAsset']) ?? $image;
        }
        if (trim((string) $image->shopify_id) === '') {
            return ['published' => 0, 'failed' => [$this->failure(0, (int) $image->id, 'Inherited image '.$image->id.' has no Shopify media for its approved description.')]];
        }
        $result = $this->deliver($image, false);

        return ['published' => $result['published'], 'failed' => $result['failed']];
    }

    /**
     * @param  array<int, string>  $collectionGids
     * @return array{published:int, propagated:int, shopify_failed:array<int, array<string, mixed>>}
     */
    public function publishApproved(array $collectionGids = []): array
    {
        return $this->publishIds($this->publishableIds($collectionGids));
    }

    /**
     * @param  array<int, int>  $imageIds
     * @return array{published:int, propagated:int, shopify_failed:array<int, array<string, mixed>>}
     */
    public function publishIds(array $imageIds): array
    {
        $published = 0;
        $propagated = 0;
        $failed = [];
        $ids = $this->ids($imageIds);
        if ($ids === []) {
            return ['published' => 0, 'propagated' => 0, 'shopify_failed' => []];
        }
        Image::query()->useWritePdo()->active()->with('product')->whereIn('id', $ids)->orderBy('id')->each(function (Image $image) use (&$published, &$propagated, &$failed): void {
            $image = $image->fresh(['product', 'imageAsset']) ?? $image;
            $approved = $this->approvedUploadAlt($image);
            if ($approved === null) {
                if (in_array($image->alt_text_status, [Image::ALT_APPROVED, Image::ALT_PUBLISHED, Image::ALT_FAILED, Image::ALT_AWAITING_CONFIRMATION], true)) {
                    $reason = 'Approval evidence or ownership is unresolved. Review this image before publishing.';
                    $this->recordAltFailure($image, trim((string) $image->alt_text), new RuntimeException($reason), $image->alt_confirmation_media_id !== null);
                    $failed[] = $this->failure(0, (int) $image->id, $reason);
                }

                return;
            }
            if ($this->inheritedStackImage($image) && $image->alt_inheritance_mode !== 'independent' && trim((string) $image->alt_text) !== $approved) {
                if ($image->alt_confirmation_media_id !== null) {
                    $failed[] = $this->failure(0, (int) $image->id, 'Resolve the pending alt confirmation first.');

                    return;
                }
                app(ImagePhotographEvidence::class)->archive($image, 'Approved component description copied before alt publication');
                Image::withoutEvents(fn () => $image->forceFill(['alt_text' => $approved])->save());
            }
            $result = $this->deliver($image, ! $this->inheritedStackImage($image));
            $published += $result['published'];
            $propagated += $result['propagated'];
            $failed = array_merge($failed, $result['failed']);
        });

        return ['published' => $published, 'propagated' => $propagated, 'shopify_failed' => $failed];
    }

    /**
     * @param  array<int, string>  $collectionGids
     * @return array{retried:int, shopify_failed:array<int, array<string, mixed>>}
     */
    public function retryFailed(array $collectionGids = []): array
    {
        return $this->retryIds(array_merge(
            $this->idsForStatus($collectionGids, Image::ALT_FAILED),
            $this->idsForStatus($collectionGids, Image::ALT_AWAITING_CONFIRMATION),
        ));
    }

    /**
     * @param  array<int, int>  $imageIds
     * @return array{retried:int, shopify_failed:array<int, array<string, mixed>>}
     */
    public function retryIds(array $imageIds): array
    {
        $failed = [];
        $retried = 0;
        $ids = $this->ids($imageIds);
        if ($ids === []) {
            return ['retried' => 0, 'shopify_failed' => []];
        }
        Image::query()->useWritePdo()->active()->with('product')->whereIn('id', $ids)->orderBy('id')->each(function (Image $image) use (&$failed, &$retried): void {
            $image = $image->fresh(['product', 'imageAsset']) ?? $image;
            if (! in_array($image->alt_text_status, [Image::ALT_FAILED, Image::ALT_AWAITING_CONFIRMATION], true)) {
                return;
            }
            if ($this->approvedUploadAlt($image) === null) {
                $failed[] = $this->failure(0, (int) $image->id, 'Image '.$image->id.' is no longer an approved description. It was not pushed.');

                return;
            }
            if ((int) $image->source_image_id > 0 && ! $this->copyHasCurrentSource($image)) {
                $failed[] = $this->failure(0, (int) $image->id, 'Stack image '.$image->id.' points at a component image which is missing or not a linked component. It was not changed.');

                return;
            }
            $retried++;
            $result = $this->deliver($image, ! $this->inheritedStackImage($image));
            $failed = array_merge($failed, $result['failed']);
        });

        return ['retried' => $retried, 'shopify_failed' => $failed];
    }

    /**
     * @param  array<int, string>  $collectionGids
     * @return array{rows: array<int, array<int, string>>, exceptions: array<int, array<string, mixed>>}
     */
    private function build(array $collectionGids): array
    {
        $products = Product::query()->activeStatus()->with(['variants' => fn ($query) => $query->orderBy('id')]);
        if ($collectionGids !== []) {
            $gids = $this->collections->activeProductGids($collectionGids);
            $products->whereIn('shopify_id', $gids === [] ? [''] : $gids);
        }

        $included = [];
        $rows = [];
        $exceptions = [];
        $products->orderBy('id')->each(function (Product $product) use (&$included, &$rows, &$exceptions): void {
            $included[$product->id] = $product;
            $images = $product->allImages()->active()->with('imageAsset')
                ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            if (! $product->isStackProduct()) {
                foreach ($images as $image) {
                    $rows[] = $this->csvRow($product, $image);
                }

                return;
            }

            $ownedImages = $images->filter(fn (Image $image): bool => ImageOwnership::isStackOwned($image)
                || (ImageOwnership::isInherited($image) && $image->alt_inheritance_mode === 'independent'))->values();
            foreach ($images as $image) {
                $rows[] = $this->csvRow($product, $image);
            }
            foreach ($images as $image) {
                if ($ownedImages->contains(fn (Image $owned): bool => (int) $owned->id === (int) $image->id)) {
                    continue;
                }
                $exception = $this->stackException($product, $image);
                if ($exception !== null) {
                    $exceptions[] = $exception;
                }
            }
        });

        foreach ($included as $product) {
            if (! $product->isStackProduct()) {
                continue;
            }
            foreach ($this->componentIds($product) as $componentId) {
                if (isset($included[$componentId])) {
                    continue;
                }
                $component = Product::query()->with('variants')->find($componentId);
                $exceptions[] = [
                    'kind' => 'component_dependency',
                    'product_id' => $componentId,
                    'image_id' => null,
                    'sku' => $this->sku($component),
                    'message' => 'Stack '.$this->sku($product).' uses component '.($this->sku($component) ?: '#'.$componentId).', which is outside this export.',
                ];
            }
        }

        return ['rows' => $rows, 'exceptions' => $exceptions];
    }

    /**
     * @return array<int, string>
     */
    private function csvRow(Product $product, Image $image): array
    {
        return [
            (string) $image->id,
            (string) $product->id,
            trim((string) ($product->shopify_id ?? '')),
            trim((string) ($image->shopify_id ?? '')),
            $this->sku($product),
            (string) ($product->title ?? ''),
            (string) (int) ($image->position ?? 1),
            $this->imageUrl($image),
            (string) ($image->alt_text ?? ''),
            (string) (ImageOwnership::effective($image) ?? 'product'),
            app(ImageAltRecovery::class)->issues($image),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stackException(Product $product, Image $image): ?array
    {
        $sourceId = (int) ($image->source_image_id ?? 0);
        if ($sourceId > 0) {
            if ($this->copyHasCurrentSource($image)) {
                return null;
            }

            return [
                'kind' => 'stale_source',
                'product_id' => $product->id,
                'image_id' => $image->id,
                'sku' => $this->sku($product),
                'message' => 'Stack image '.$image->id.' points at component image '.$sourceId.', which is missing or not a linked component. It was not changed.',
            ];
        }

        $assetId = (int) ($image->image_asset_id ?? 0);
        $matches = $assetId > 0
            ? Image::query()->useWritePdo()->active()->where('image_asset_id', $assetId)->whereIn('product_id', $this->componentIds($product))->orderBy('id')->get()
            : collect();

        return [
            'kind' => $matches->isEmpty() ? 'unmapped_stack_image' : 'ambiguous_legacy',
            'product_id' => $product->id,
            'image_id' => $image->id,
            'sku' => $this->sku($product),
            'message' => $matches->isEmpty()
                ? 'Stack image '.$image->id.' has no verified ownership. It is exported for review; classify it in Image Alt Review before approving or publishing.'
                : 'Stack image '.$image->id.' shares an asset with component image '.$matches->pluck('id')->implode(', ').', but no source link is stored. It was not linked or changed.',
        ];
    }

    /**
     * @return array<int, int>
     */
    private function componentIds(Product $stack): array
    {
        return app(StackComponentCatalog::class)->componentIds($stack);
    }

    private function inheritedStackImage(Image $image): bool
    {
        return ImageOwnership::isInherited($image);
    }

    private function notDirectlyEditable(Image $image): bool
    {
        return ImageOwnership::blocksOwnAltApproval($image);
    }

    /**
     * @return array{0: Collection<int, Image>, 1: Collection<int, Image>}
     */
    private function componentCopies(Image $componentImage): array
    {
        $copies = Image::query()
            ->active()
            ->where(fn ($query) => $query->whereNull('alt_inheritance_mode')->orWhere('alt_inheritance_mode', '!=', 'independent'))
            ->with('product')
            ->where(function ($query) use ($componentImage): void {
                $linked = DB::table('inherited_image_sources')->where('component_image_id', $componentImage->id)->whereNull('removed_at')->pluck('image_id');
                $query->where('source_image_id', $componentImage->id);
                if ($linked->isNotEmpty()) {
                    $query->orWhereIn('id', $linked);
                }
            })
            ->whereKeyNot($componentImage->id)
            ->orderBy('id')
            ->get();

        $conflicted = $copies->filter(fn (Image $copy): bool => $this->inheritedAltConflicts($copy))->values();
        $eligible = $copies->reject(fn (Image $copy): bool => $conflicted->contains(fn (Image $item): bool => (int) $item->id === (int) $copy->id));

        return [
            $eligible->filter(fn (Image $copy): bool => $this->copyHasCurrentSource($copy))->values(),
            $eligible->reject(fn (Image $copy): bool => $this->copyHasCurrentSource($copy))
                ->merge($conflicted)
                ->values(),
        ];
    }

    public function copyHasCurrentSource(Image $copy): bool
    {
        $product = $copy->product;
        if (! $product instanceof Product || ! $product->isStackProduct() || ! $this->inheritedStackImage($copy)) {
            return false;
        }

        $source = Image::query()->useWritePdo()->find((int) $copy->source_image_id);
        if (! $source instanceof Image) {
            return false;
        }

        return in_array((int) $source->product_id, $this->componentIds($product), true)
            && ! in_array($source->sync_state, [Image::SYNC_STATE_LOCAL_DELETED, Image::SYNC_STATE_REMOTE_DELETED], true)
            && ! $source->is_duplicate_hidden
            && (int) $source->image_asset_id > 0
            && (int) $copy->image_asset_id === (int) $source->image_asset_id;
    }

    /** Manual stack publishing is separate from automatic propagation. */
    public function canPublishInheritedPhotograph(Image $copy): bool
    {
        if (! $this->copyHasCurrentSource($copy) || ! $copy->product?->isApprovedByTwo()) {
            return false;
        }
        $source = Image::query()->with('product')->find($copy->source_image_id);
        if (! $source || ! $source->imageAsset?->isAvailable()) {
            return false;
        }
        if (app(StackImageSync::class)->stackCapturedPhotograph($copy)) {
            return true;
        }
        if (! $source->product?->isApprovedByTwo() || ! app(ComponentPhotographSelector::class)->confirmed($source)) {
            return false;
        }

        return in_array($this->media->diagnose($source)['classification'], ['verified_current_media', 'recoverable_historical'], true);
    }

    private function inheritedAltConflicts(Image $copy): bool
    {
        $ids = DB::table('inherited_image_sources')->where('image_id', $copy->id)->whereNull('removed_at')->pluck('component_image_id');
        if ($ids->count() < 2) {
            return false;
        }
        $alts = Image::query()->active()->whereIn('id', $ids)->where('image_asset_id', $copy->image_asset_id)->get()
            ->filter(fn (Image $source): bool => in_array((int) $source->product_id, $this->componentIds($copy->product), true))
            ->map(fn (Image $source): string => trim((string) $this->approvals->approvedAlt($source)))
            ->filter()
            ->unique();

        $resolution = app(InheritedAltResolution::class)->current($copy);

        return $alts->count() > 1 && (! is_string($resolution) || ! $alts->contains($resolution));
    }

    /**
     * @param  array<int, string>  $collectionGids
     * @return array<int, int>
     */
    private function idsForStatus(array $collectionGids, string $status): array
    {
        $products = Product::query()->activeStatus();
        if ($collectionGids !== []) {
            $gids = $this->collections->activeProductGids($collectionGids);
            $products->whereIn('shopify_id', $gids === [] ? [''] : $gids);
        }

        return Image::query()
            ->active()
            ->whereIn('product_id', $products->select('id'))
            ->where('alt_text_status', $status)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, string>  $collectionGids
     * @return array<int, int>
     */
    private function publishableIds(array $collectionGids): array
    {
        $products = Product::query()->activeStatus();
        if ($collectionGids !== []) {
            $gids = $this->collections->activeProductGids($collectionGids);
            $products->whereIn('shopify_id', $gids === [] ? [''] : $gids);
        }

        $ids = [];
        Image::query()->useWritePdo()->active()->with('product')->whereIn('product_id', $products->select('id'))->orderBy('id')
            ->each(function (Image $image) use (&$ids): void {
                if ($this->approvedUploadAlt($image) !== null) {
                    $ids[] = (int) $image->id;
                }
            });

        return $ids;
    }

    /**
     * @param  array<int, int>  $imageIds
     * @return array<int, int>
     */
    private function ids(array $imageIds): array
    {
        return collect($imageIds)->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
    }

    private function writeReviewState(Image $image, string $altText, string $status): void
    {
        Image::withoutEvents(function () use ($image, $altText, $status): void {
            $image->forceFill([
                'alt_text' => $altText,
                'alt_text_status' => $status,
                'shopify_alt_pending' => false,
                'shopify_alt_sync_error' => null,
            ])->save();
        });

        if ($status === Image::ALT_PENDING_REVIEW) {
            $product = $image->product instanceof Product ? $image->product : Product::query()->find($image->product_id);
            if ($product instanceof Product) {
                $this->approvals->invalidatePendingImageAlt($product);
            }
        }
    }

    private function rememberImportedAlt(Image $image, string $altText): void
    {
        if (trim((string) $image->imported_alt_text) === $altText) {
            return;
        }

        Image::withoutEvents(function () use ($image, $altText): void {
            $image->forceFill(['imported_alt_text' => $altText])->save();
        });
    }

    /**
     * @return array{published:int, propagated:int, failed:array<int, array<string, mixed>>}
     */
    private function deliver(Image $image, bool $propagate): array
    {
        $altText = trim((string) $image->alt_text);
        if ($altText === '') {
            return [
                'published' => 0,
                'propagated' => 0,
                'failed' => [$this->failure(0, (int) $image->id, 'Image '.$image->id.' has no alt text to publish.')],
            ];
        }
        // Dependent copies are published before the source. Publishing the source
        // marks its alt as already on Shopify, and the copy still has to read that
        // same approval while its own file is updated.
        $targets = collect();
        $targetSentences = [];
        $propagated = 0;
        $failed = [];
        if ($propagate) {
            [$copies, $rejected] = $this->componentCopies($image);
            foreach ($rejected as $copy) {
                $failed[] = $this->failure(0, (int) $copy->id, 'Stack image '.$copy->id.' points at component image '.$image->id.', which is missing or not a linked component. It was not changed.');
            }
            foreach ($copies as $copy) {
                if ($copy->alt_inheritance_mode === 'independent') {
                    continue;
                }
                $copySentence = $this->approvedUploadAlt($copy);
                if ($copySentence === null) {
                    $failed[] = $this->failure(0, (int) $copy->id, 'No current approved component sentence is available for this inherited photograph.');

                    continue;
                }
                if ($copy->alt_confirmation_media_id !== null && $copy->alt_confirmation_text !== $copySentence) {
                    $failed[] = $this->failure(0, (int) $copy->id, 'Resolve the previous confirmation before applying the new component sentence.');

                    continue;
                }
                if (trim((string) $copy->alt_text) !== $copySentence) {
                    app(ImagePhotographEvidence::class)->archive($copy, 'Component alt text propagation');
                    Image::withoutEvents(function () use ($copy, $copySentence): void {
                        $copy->forceFill(['alt_text' => $copySentence])->save();
                    });
                }
                $targetSentences[$copy->id] = $copySentence;
                $targets->push($copy);
                $propagated++;
            }
        }
        $targets->push($image);

        $published = 0;
        foreach ($targets as $target) {
            $targetAlt = $targetSentences[$target->id] ?? $altText;
            if ($this->shopifyAlreadyHasApprovedAlt($target, $targetAlt)) {
                continue;
            }
            $failure = $this->pushAlt($target, $targetAlt);
            if ($failure === null) {
                $published++;

                continue;
            }
            $failed[] = $failure;
        }

        return ['published' => $published, 'propagated' => $propagated, 'failed' => $failed];
    }

    private function shopifyAlreadyHasApprovedAlt(Image $image, string $altText): bool
    {
        if ($image->alt_text_status !== Image::ALT_PUBLISHED || trim((string) $image->shopify_alt_text) !== $altText) {
            return false;
        }

        $image->loadMissing('product');
        $productGid = trim((string) ($image->product?->shopify_id ?? ''));
        $mediaId = trim((string) $image->shopify_id);
        if ($productGid === '' || preg_match('~^gid://shopify/MediaImage/\d+$~', $mediaId) !== 1) {
            return false;
        }

        try {
            $decision = $this->media->diagnose($image->fresh(['product', 'imageAsset']) ?? $image);
            if (! in_array($decision['classification'], ['verified_current_media', 'recoverable_historical'], true)
                || $decision['proposed_media_id'] !== $mediaId) {
                return false;
            }

            return $this->shopify->attachedAlt($productGid, $mediaId) === $altText;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{row:int, image_id:int, reason:string}|null
     */
    private function pushAlt(Image $image, string $altText): ?array
    {
        $mutex = new ImageSyncMutex;
        if (! $mutex->acquire((int) $image->product_id)) {
            return $this->failure(0, $image->id, 'Another image operation is running.');
        }
        try {
            if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
                throw new RuntimeException('Alt publication must start outside an enclosing database transaction so confirmation state is durable.');
            }
            $fresh = $image->fresh(['product', 'imageAsset']) ?? $image;
            $productGid = trim((string) ($fresh->product?->shopify_id ?? ''));
            $mediaId = $this->media->resolve($fresh);
            if (Image::query()->active()->whereKeyNot($fresh->id)->where('shopify_id', $mediaId)->exists()) {
                throw new RuntimeException('This Shopify file is shared by multiple CMS images. Review separate media identities before publishing independent descriptions.');
            }
            if ($this->inheritedStackImage($fresh)) {
                $sourceIds = DB::table('inherited_image_sources')->where('image_id', $fresh->id)->whereNull('removed_at')->pluck('component_image_id')->push($fresh->source_image_id)->filter();
                if (Image::query()->whereIn('id', $sourceIds)->where('shopify_id', $mediaId)->exists()) {
                    throw new RuntimeException('This inherited image shares a component Shopify file. Rebuild its separate stack media before publishing alt text.');
                }
            }
            $mutex->assertOwned();
            if ((int) $fresh->source_image_id > 0 && ! $this->copyHasCurrentSource($fresh)) {
                throw new RuntimeException('The stack photograph no longer matches its component.');
            }
            $pending = $fresh->alt_confirmation_media_id !== null;
            if ($pending && ($fresh->alt_confirmation_media_id !== $mediaId || $fresh->alt_confirmation_text !== $altText)) {
                throw new AltTextAwaitingConfirmation('A previous alt update must be reconciled before changing its media or description.');
            }
            Image::withoutEvents(fn () => $fresh->forceFill([
                'alt_confirmation_media_id' => $mediaId,
                'alt_confirmation_text' => $altText,
                'alt_text_status' => Image::ALT_AWAITING_CONFIRMATION,
            ])->save());
            $assertCurrent = function () use ($mutex, $fresh, $altText): void {
                $mutex->assertOwned();
                $live = $fresh->fresh(['product', 'imageAsset']);
                if (! $live || $live->image_asset_id !== $fresh->image_asset_id || $live->shopify_id !== $fresh->shopify_id
                    || $live->shopify_identity_unresolved || $this->approvedUploadAlt($live) !== $altText) {
                    throw new RuntimeException('Photograph identity or approval changed during publication.');
                }
            };
            $assertCurrent();
            // Network work uses the session mutex and durable pending marker, not row locks.
            $this->shopify->update($mediaId, $altText, $productGid, $pending, $assertCurrent);
            $confirmed = $this->media->diagnose($fresh->fresh(['product', 'imageAsset']));
            if (! in_array($confirmed['classification'], ['verified_current_media', 'recoverable_historical'], true)
                || $confirmed['proposed_media_id'] !== $mediaId) {
                throw new AltTextAwaitingConfirmation('Alt text read-back succeeded but photograph identity changed or could not be confirmed. Review before publishing again.');
            }
            DB::transaction(function () use ($fresh, $altText, $assertCurrent): void {
                $ids = array_filter([$fresh->id, $fresh->source_image_id]);
                Image::query()->useWritePdo()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                $assertCurrent();
                Image::withoutEvents(fn () => $fresh->refresh()->forceFill([
                    'alt_text_status' => Image::ALT_PUBLISHED,
                    'alt_confirmation_media_id' => null, 'alt_confirmation_text' => null,
                    'shopify_alt_pending' => false, 'shopify_alt_sync_error' => null,
                    'shopify_alt_text' => $altText,
                ])->save());
            });

            return null;
        } catch (AltTextAwaitingConfirmation $exception) {
            $this->recordAltFailure($image, $altText, $exception, true);

            return $this->failure(0, (int) $image->id, $exception->getMessage());
        } catch (\Throwable $exception) {
            $uncertain = $image->fresh()?->alt_confirmation_media_id !== null && ! $exception instanceof AltTextRejected;
            $this->recordAltFailure($image, $altText, $exception, $uncertain);

            return $this->failure(0, (int) $image->id, $exception->getMessage());
        } finally {
            $mutex->release();
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function recordAltFailure(Image $image, string $altText, \Throwable $exception, bool $uncertain): void
    {
        $updates = [
            'alt_text_status' => $uncertain ? Image::ALT_AWAITING_CONFIRMATION : Image::ALT_FAILED,
            'shopify_alt_pending' => true, 'shopify_alt_sync_error' => $exception->getMessage(),
        ];
        if (! $uncertain) {
            $updates['alt_confirmation_media_id'] = null;
            $updates['alt_confirmation_text'] = null;
        }
        // Never overwrite a newer photograph, review state, or successful worker.
        Image::query()->useWritePdo()->whereKey($image->id)
            ->where('image_asset_id', $image->image_asset_id)->where('alt_text', $altText)
            ->whereIn('alt_text_status', [Image::ALT_APPROVED, Image::ALT_FAILED, Image::ALT_AWAITING_CONFIRMATION, Image::ALT_PENDING_REVIEW, Image::ALT_PUBLISHED])
            ->update($updates);
    }

    private function mismatch(Image $image, array $row): ?string
    {
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId > 0 && $productId !== (int) $image->product_id) {
            return 'Image '.$image->id.' does not belong to product '.$productId.'.';
        }

        $shopifyProductId = trim((string) ($row['shopify_product_id'] ?? ''));
        $localProductId = trim((string) ($image->product?->shopify_id ?? ''));
        if ($shopifyProductId !== '' && $localProductId !== '' && $shopifyProductId !== $localProductId) {
            return 'Image '.$image->id.' does not belong to Shopify product '.$shopifyProductId.'.';
        }

        $mediaId = trim((string) ($row['shopify_media_id'] ?? ''));
        $localMediaId = trim((string) ($image->shopify_id ?? ''));
        if ($mediaId !== '' && $localMediaId !== '' && $mediaId !== $localMediaId) {
            return 'Image '.$image->id.' does not match Shopify media '.$mediaId.'.';
        }

        return null;
    }

    /**
     * @return array{row:int, image_id:int, reason:string}
     */
    private function failure(int $row, int $imageId, string $reason): array
    {
        return ['row' => $row, 'image_id' => $imageId, 'reason' => $reason];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>}
     */
    private function table(string $contents): array
    {
        $contents = ltrim(str_replace("\r\n", "\n", $contents), "\xEF\xBB\xBF");
        if (trim($contents) === '') {
            throw new RuntimeException('The alt-text file is empty.');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);
        $headerLine = fgets($handle);
        if (! is_string($headerLine) || trim($headerLine) === '') {
            fclose($handle);
            throw new RuntimeException('The alt-text file is empty.');
        }
        $delimiter = substr_count($headerLine, "\t") > substr_count($headerLine, ',') ? "\t" : ',';
        rewind($handle);
        $headers = array_map([$this, 'header'], fgetcsv($handle, 0, $delimiter) ?: []);
        $rows = [];
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                continue;
            }
            if (str_starts_with(trim((string) ($values[0] ?? '')), '#')) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = trim((string) ($values[$index] ?? ''));
                }
            }
            $rows[] = $row;
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function header(string $header): string
    {
        $header = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header));

        return match (str_replace([' ', '-'], '_', $header)) {
            'local_image_id' => 'image_id',
            'local_product_id' => 'product_id',
            'media_id', 'shopify_image_id' => 'shopify_media_id',
            'item_sku', 'variant_sku' => 'sku',
            'title', 'product' => 'product_title',
            'image_position', 'pos' => 'position',
            'shopify_image', 'src', 'url' => 'image_url',
            'alt', 'image_alt', 'image_alt_text' => 'alt_text',
            default => str_replace([' ', '-'], '_', $header),
        };
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function csvResponse(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fputcsv($out, ImageAltTextExportService::HEADERS);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, array<string, mixed>>  $exceptions
     * @return array{response: StreamedResponse, failures: array<int, array<string, mixed>>}
     */
    private function zipResponse(array $rows, array $exceptions, string $filename): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Image download requires the PHP zip extension.');
        }

        $path = tempnam(sys_get_temp_dir(), 'alt-zip-');
        if ($path === false) {
            throw new RuntimeException('The image download could not be created.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('The image download could not be created.');
        }

        $zip->addFromString('alt-text.csv', $this->csvDocument($rows, true));
        if ($exceptions !== []) {
            $exceptionCsv = fopen('php://temp', 'r+');
            fputcsv($exceptionCsv, ['kind', 'product_id', 'image_id', 'sku', 'message']);
            foreach ($exceptions as $exception) {
                fputcsv($exceptionCsv, [
                    $exception['kind'] ?? '',
                    $exception['product_id'] ?? '',
                    $exception['image_id'] ?? '',
                    $exception['sku'] ?? '',
                    $exception['message'] ?? '',
                ]);
            }
            rewind($exceptionCsv);
            $zip->addFromString('exceptions.csv', (string) stream_get_contents($exceptionCsv));
            fclose($exceptionCsv);
        }

        $manifest = fopen('php://temp', 'r+');
        $failureCsv = fopen('php://temp', 'r+');
        fputcsv($manifest, ['filename', 'image_id', 'product_id', 'sku', 'position', 'image_url', 'download_status', 'download_error']);
        fputcsv($failureCsv, ['image_id', 'product_id', 'sku', 'position', 'image_url', 'reason']);
        $downloadFailures = [];
        foreach ($rows as $row) {
            $url = (string) ($row[7] ?? '');
            $name = 'images/image-'.$row[0].'.'.$this->extension($url);
            $error = $this->storeZipPhotograph($zip, $name, $url);
            fputcsv($manifest, [$name, $row[0], $row[1], $row[4], $row[6], $url, $error === null ? 'downloaded' : 'failed', $error ?? '']);
            if ($error === null) {
                continue;
            }
            fputcsv($failureCsv, [$row[0], $row[1], $row[4], $row[6], $url, $error]);
            $downloadFailures[] = [
                'kind' => 'photograph_download',
                'product_id' => (int) $row[1],
                'image_id' => (int) $row[0],
                'sku' => (string) $row[4],
                'message' => 'Image '.$row[0].' was not included in the ZIP. '.$error,
            ];
        }
        rewind($manifest);
        $zip->addFromString('manifest.csv', (string) stream_get_contents($manifest));
        fclose($manifest);
        if ($downloadFailures !== []) {
            rewind($failureCsv);
            $zip->addFromString('download-failures.csv', (string) stream_get_contents($failureCsv));
        }
        fclose($failureCsv);
        $zip->close();

        return ['failures' => $downloadFailures, 'response' => response()->streamDownload(function () use ($path): void {
            try {
                $bytes = file_get_contents($path);
                if (is_string($bytes)) {
                    echo $bytes;
                }
            } finally {
                @unlink($path);
            }
        }, $filename, ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store'])];
    }

    private function storeZipPhotograph(ZipArchive $zip, string $name, string $url): ?string
    {
        if ($url === '') {
            return 'No photograph URL is available.';
        }

        try {
            $response = Http::timeout(30)->get($url);
        } catch (\Throwable $exception) {
            return 'Photograph download failed: '.$exception->getMessage();
        }

        if (! $response->successful() || $response->body() === '') {
            return 'Photograph download failed with HTTP '.$response->status().'.';
        }

        $zip->addFromString($name, $response->body());

        return null;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function csvDocument(array $rows, bool $header): string
    {
        $out = fopen('php://temp', 'r+');
        if ($header) {
            fputcsv($out, ImageAltTextExportService::HEADERS);
        }
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function extension(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $extension : 'jpg';
    }

    private function sku(?Product $product): string
    {
        if (! $product instanceof Product) {
            return '';
        }

        return trim((string) ($product->variants->first()?->sku ?? $product->variants()->orderBy('id')->value('sku') ?? ''));
    }

    private function imageUrl(Image $image): string
    {
        $src = trim((string) $image->src);
        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }

        return (string) ($image->backupPublicUrl() ?? '');
    }

    private function csvFromXlsx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The Excel file could not be read.');
        }
        $shared = $this->sharedStrings($zip->getFromName('xl/sharedStrings.xml'));
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (! is_string($sheetXml) || $sheetXml === '') {
            throw new RuntimeException('The Excel file has no worksheet.');
        }
        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw new RuntimeException('The Excel worksheet could not be read.');
        }
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $csv = fopen('php://temp', 'r+');
        foreach ($xml->children($ns)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $column = $this->columnIndex((string) $cell->attributes()['r']);
                $cells[$column] = $this->cellValue($cell, $shared);
            }
            if ($cells === []) {
                continue;
            }
            $values = [];
            for ($index = 0; $index <= max(array_keys($cells)); $index++) {
                $values[] = $cells[$index] ?? '';
            }
            fputcsv($csv, $values);
        }
        rewind($csv);
        $contents = (string) stream_get_contents($csv);
        fclose($csv);

        return $contents;
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(string|false $xml): array
    {
        if (! is_string($xml) || $xml === '') {
            return [];
        }
        $parsed = simplexml_load_string($xml);
        if ($parsed === false) {
            return [];
        }
        $strings = [];
        foreach ($parsed->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->si as $item) {
            $text = (string) ($item->t ?? '');
            foreach ($item->r as $run) {
                $text .= (string) ($run->t ?? '');
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param  array<int, string>  $shared
     */
    private function cellValue(\SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell->attributes()['t'];
        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }
        if (isset($cell->f) && ! isset($cell->v)) {
            return '';
        }
        $value = (string) ($cell->v ?? '');

        return $type === 's' ? ($shared[(int) $value] ?? '') : $value;
    }

    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?? '';
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
