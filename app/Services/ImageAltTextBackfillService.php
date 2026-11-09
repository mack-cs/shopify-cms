<?php

namespace App\Services;

use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
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

    /**
     * @param array<int, string> $collectionGids
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

            $image = Image::query()->with('product')->find($imageId);
            if (! $image instanceof Image || ! $image->product instanceof Product) {
                $failed[] = $this->failure($rowNumber, $imageId, 'No image matches image id '.$imageId.'.');
                continue;
            }

            $mismatch = $this->mismatch($image, $row);
            if ($mismatch !== null) {
                $failed[] = $this->failure($rowNumber, $imageId, $mismatch);
                continue;
            }

            if ($this->inheritedStackImage($image)) {
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

            $this->writeReviewState($image, $altText, Image::ALT_PENDING_REVIEW);
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
        if ($this->inheritedStackImage($image)) {
            throw new RuntimeException('Stack image '.$image->id.' is inherited from a component. Update the component image instead.');
        }
        if ($altText === '') {
            throw new RuntimeException('Blank alt text is not saved.');
        }

        $this->writeReviewState($image, $altText, Image::ALT_PENDING_REVIEW);
    }

    /**
     * @param array<int, string> $collectionGids
     * @return array{published:int, propagated:int, shopify_failed:array<int, array<string, mixed>>}
     */
    public function publishApproved(array $collectionGids = []): array
    {
        return $this->publishIds($this->publishableIds($collectionGids));
    }

    /**
     * @param array<int, int> $imageIds
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
        Image::query()->with('product')->whereIn('id', $ids)->orderBy('id')->each(function (Image $image) use (&$published, &$propagated, &$failed): void {
            if ($this->approvals->approvedAlt($image) === null) {
                return;
            }
            if ($this->inheritedStackImage($image)) {
                $failed[] = $this->failure(0, (int) $image->id, 'Stack image '.$image->id.' is inherited. Push the approved component image instead.');

                return;
            }

            $result = $this->deliver($image, true);
            $published += $result['published'];
            $propagated += $result['propagated'];
            $failed = array_merge($failed, $result['failed']);
        });

        return ['published' => $published, 'propagated' => $propagated, 'shopify_failed' => $failed];
    }

    /**
     * @param array<int, string> $collectionGids
     * @return array{retried:int, shopify_failed:array<int, array<string, mixed>>}
     */
    public function retryFailed(array $collectionGids = []): array
    {
        return $this->retryIds($this->idsForStatus($collectionGids, Image::ALT_FAILED));
    }

    /**
     * @param array<int, int> $imageIds
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
        Image::query()->with('product')->whereIn('id', $ids)->orderBy('id')->each(function (Image $image) use (&$failed, &$retried): void {
            if ($image->alt_text_status !== Image::ALT_FAILED) {
                return;
            }
            if ($this->approvals->approvedAlt($image) === null && ! $this->inheritedStackImage($image)) {
                $failed[] = $this->failure(0, (int) $image->id, 'Image '.$image->id.' is no longer an approved description. It was not pushed.');

                return;
            }
            if ((int) $image->source_image_id > 0 && ! $this->copyHasCurrentSource($image)) {
                $failed[] = $this->failure(0, (int) $image->id, 'Stack image '.$image->id.' points at a component image which is missing or not a linked component. It was not changed.');

                return;
            }
            if ($this->inheritedStackImage($image)) {
                $source = Image::query()->find((int) $image->source_image_id);
                $approved = $source instanceof Image ? $this->approvals->approvedAlt($source) : null;
                if ($approved === null || $approved !== trim((string) $image->alt_text)) {
                    $failed[] = $this->failure(0, (int) $image->id, 'Stack image '.$image->id.' no longer matches an approved component description. It was not pushed.');

                    return;
                }
            }

            $retried++;
            $result = $this->deliver($image, ! $this->inheritedStackImage($image));
            $failed = array_merge($failed, $result['failed']);
        });

        return ['retried' => $retried, 'shopify_failed' => $failed];
    }

    /**
     * @param array<int, string> $collectionGids
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

            $owned = $images->first(fn (Image $image): bool => $image->source_image_id === null && ($image->position === null || (int) $image->position <= 1));
            if ($owned instanceof Image) {
                $rows[] = $this->csvRow($product, $owned);
            }
            foreach ($images as $image) {
                if ($owned instanceof Image && $image->id === $owned->id) {
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
            ? Image::query()->active()->where('image_asset_id', $assetId)->whereIn('product_id', $this->componentIds($product))->orderBy('id')->get()
            : collect();

        return [
            'kind' => $matches->isEmpty() ? 'unmapped_stack_image' : 'ambiguous_legacy',
            'product_id' => $product->id,
            'image_id' => $image->id,
            'sku' => $this->sku($product),
            'message' => $matches->isEmpty()
                ? 'Stack image '.$image->id.' is after the stack-owned image and has no verified component source. It was not exported or changed.'
                : 'Stack image '.$image->id.' shares an asset with component image '.$matches->pluck('id')->implode(', ').', but no source link is stored. It was not linked or changed.',
        ];
    }

    /**
     * @return array<int, int>
     */
    private function componentIds(Product $stack): array
    {
        $shopifyId = trim((string) ($stack->shopify_id ?? ''));
        $handle = trim((string) ($stack->handle ?? ''));
        if ($shopifyId === '' && $handle === '') {
            return [];
        }

        $draft = NewProductDraft::query()
            ->where(function ($query) use ($shopifyId, $handle): void {
                if ($shopifyId !== '') {
                    $query->where('shopify_id', $shopifyId);
                }
                if ($handle !== '') {
                    $shopifyId !== '' ? $query->orWhere('handle', $handle) : $query->where('handle', $handle);
                }
            })
            ->orderByDesc('updated_at')
            ->first(['bundle_product_ids']);

        return collect(is_array($draft?->bundle_product_ids) ? $draft->bundle_product_ids : [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function inheritedStackImage(Image $image): bool
    {
        $product = $image->product;
        if (! $product instanceof Product || ! $product->isStackProduct()) {
            return false;
        }

        return $image->position !== null && (int) $image->position >= 2;
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, Image>, 1: \Illuminate\Support\Collection<int, Image>}
     */
    private function componentCopies(Image $componentImage): array
    {
        $copies = Image::query()
            ->active()
            ->with('product')
            ->where('source_image_id', $componentImage->id)
            ->whereKeyNot($componentImage->id)
            ->orderBy('id')
            ->get();

        return [
            $copies->filter(fn (Image $copy): bool => $this->copyHasCurrentSource($copy))->values(),
            $copies->reject(fn (Image $copy): bool => $this->copyHasCurrentSource($copy))->values(),
        ];
    }

    private function copyHasCurrentSource(Image $copy): bool
    {
        $product = $copy->product;
        if (! $product instanceof Product || ! $product->isStackProduct() || ! $this->inheritedStackImage($copy)) {
            return false;
        }

        $source = Image::query()->find((int) $copy->source_image_id);
        if (! $source instanceof Image) {
            return false;
        }

        return in_array((int) $source->product_id, $this->componentIds($product), true);
    }

    /**
     * @param array<int, string> $collectionGids
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
     * @param array<int, string> $collectionGids
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
        Image::query()->active()->with('product')->whereIn('product_id', $products->select('id'))->orderBy('id')
            ->each(function (Image $image) use (&$ids): void {
                if ($this->approvals->approvedAlt($image) !== null) {
                    $ids[] = (int) $image->id;
                }
            });

        return $ids;
    }

    /**
     * @param array<int, int> $imageIds
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
        $targets = collect([$image]);
        $propagated = 0;
        $failed = [];
        if ($propagate) {
            [$copies, $rejected] = $this->componentCopies($image);
            foreach ($rejected as $copy) {
                $failed[] = $this->failure(0, (int) $copy->id, 'Stack image '.$copy->id.' points at component image '.$image->id.', which is missing or not a linked component. It was not changed.');
            }
            foreach ($copies as $copy) {
                if (trim((string) $copy->alt_text) !== $altText) {
                    Image::withoutEvents(function () use ($copy, $altText): void {
                        $copy->forceFill(['alt_text' => $altText])->save();
                    });
                }
                $targets->push($copy);
                $propagated++;
            }
        }

        $published = 0;
        foreach ($targets as $target) {
            if ($target->alt_text_status === Image::ALT_PUBLISHED && trim((string) $target->shopify_alt_text) === $altText) {
                continue;
            }
            $failure = $this->pushAlt($target, $altText);
            if ($failure === null) {
                $published++;
                continue;
            }
            $failed[] = $failure;
        }

        return ['published' => $published, 'propagated' => $propagated, 'failed' => $failed];
    }

    /**
     * @return array{row:int, image_id:int, reason:string}|null
     */
    private function pushAlt(Image $image, string $altText): ?array
    {
        try {
            $mediaId = $this->media->resolve($image->fresh(['product']) ?? $image);
            $this->shopify->update($mediaId, $altText);
            Image::withoutEvents(function () use ($image, $altText): void {
                $image->forceFill([
                    'alt_text_status' => Image::ALT_PUBLISHED,
                    'shopify_alt_pending' => false,
                    'shopify_alt_sync_error' => null,
                    'shopify_alt_text' => $altText,
                ])->save();
            });

            return null;
        } catch (\Throwable $exception) {
            Image::withoutEvents(function () use ($image, $exception): void {
                $image->forceFill([
                    'alt_text_status' => Image::ALT_FAILED,
                    'shopify_alt_pending' => true,
                    'shopify_alt_sync_error' => $exception->getMessage(),
                ])->save();
            });

            return $this->failure(0, (int) $image->id, $exception->getMessage());
        }
    }

    /**
     * @param array<string, string> $row
     */
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
     * @param array<int, array<int, string>> $rows
     */
    private function csvResponse(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['image_id', 'product_id', 'shopify_product_id', 'shopify_media_id', 'sku', 'product_title', 'position', 'image_url', 'alt_text']);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @param array<int, array<string, mixed>> $exceptions
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

        $zip = new ZipArchive();
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
     * @param array<int, array<int, string>> $rows
     */
    private function csvDocument(array $rows, bool $header): string
    {
        $out = fopen('php://temp', 'r+');
        if ($header) {
            fputcsv($out, ['image_id', 'product_id', 'shopify_product_id', 'shopify_media_id', 'sku', 'product_title', 'position', 'image_url', 'alt_text']);
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
        $zip = new ZipArchive();
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
     * @param array<int, string> $shared
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
