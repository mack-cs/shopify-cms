<?php

namespace App\Services;

use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * Links existing stack photographs to component images.
 * It does not create images, reorder them, upload photographs, or change prices.
 */
final class StackImageSourceRepairService
{
    public function __construct(private readonly ImageAltTextShopifyWriter $shopify) {}

    /**
     * @param array<int, int> $stackIds
     * @return array{applied:bool, shopify:bool, stacks: array<int, array<string, mixed>>}
     */
    public function review(array $stackIds, bool $apply = false, bool $approveShopifyAlt = false): array
    {
        unset($approveShopifyAlt);
        $stacks = [];
        foreach ($this->ids($stackIds) as $stackId) {
            $stack = Product::query()->with(['variants' => fn ($query) => $query->orderBy('id')])->find($stackId);
            $plan = $stack instanceof Product ? $this->plan($stack) : [
                'product_id' => $stackId,
                'sku' => '',
                'mode' => 'blocked',
                'blocked' => ['Stack product '.$stackId.' was not found.'],
                'links' => [],
                'unmatched_stack_image_ids' => [],
                'applied' => false,
            ];
            if ($apply && $plan['blocked'] === [] && $stack instanceof Product) {
                $plan = $this->commit($stack, $plan);
            }
            $stacks[] = $plan;
        }

        return ['applied' => $apply, 'shopify' => false, 'stacks' => $stacks];
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(Product $stack): array
    {
        $sku = $this->sku($stack);
        $base = [
            'product_id' => $stack->id,
            'sku' => $sku,
            'mode' => 'blocked',
            'blocked' => [],
            'links' => [],
            'unmatched_stack_image_ids' => [],
            'applied' => false,
        ];
        if (! $stack->isStackProduct()) {
            $base['blocked'][] = ($sku !== '' ? $sku : 'Product '.$stack->id).' is not a stack.';

            return $base;
        }

        $owned = $stack->allImages()->active()->whereNull('source_image_id')->where('position', 1)->orderBy('id')->get();
        if ($owned->count() !== 1) {
            $base['blocked'][] = ($sku !== '' ? $sku : 'Stack '.$stack->id).($owned->isEmpty()
                ? ' has no owned position-1 image.'
                : ' has more than one owned position-1 image.');

            return $base;
        }

        $componentIds = $this->componentIds($stack);
        if ($componentIds === []) {
            $base['blocked'][] = ($sku !== '' ? $sku : 'Stack '.$stack->id).' has no component list on its latest draft.';

            return $base;
        }

        $stackImages = $stack->allImages()->active()
            ->where('position', '>=', 2)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $claimed = [];
        $links = [];
        foreach ($componentIds as $componentId) {
            $component = Product::query()->find($componentId);
            if (! $component instanceof Product) {
                $base['blocked'][] = 'Component #'.$componentId.' does not exist.';
                continue;
            }
            $componentImage = $this->componentImage($component);
            if (! $componentImage instanceof Image) {
                $base['blocked'][] = $this->sku($component).' has no Shopify image that can be linked.';
                continue;
            }
            $matches = $stackImages->filter(fn (Image $image): bool => $this->samePhotograph($componentImage, $image))->values();
            if ($matches->count() !== 1) {
                $base['blocked'][] = $matches->isEmpty()
                    ? $this->sku($component).' has no existing stack photograph with the same Shopify file. Uploading a replacement would be a separate approval.'
                    : $this->sku($component).' matches more than one stack photograph.';
                continue;
            }
            $match = $matches->first();
            if (! $match instanceof Image || isset($claimed[$match->id])) {
                $base['blocked'][] = $this->sku($component).' matches a stack photograph already linked to another component.';
                continue;
            }
            $claimed[$match->id] = true;
            $links[] = [
                'stack_image_id' => $match->id,
                'component_image_id' => $componentImage->id,
                'position' => (int) $match->position,
                'alt_text' => $this->alt($componentImage),
                'shopify_media_id' => trim((string) $match->shopify_id),
                'media_warning' => $this->shopify->rejectionReason(trim((string) $match->shopify_id))
                    ?? $this->shopify->rejectionReason(trim((string) $componentImage->shopify_id)),
            ];
        }

        if ($base['blocked'] !== []) {
            return $base;
        }

        $base['mode'] = 'metadata';
        $base['links'] = $links;
        $base['unmatched_stack_image_ids'] = $stackImages->pluck('id')->reject(fn ($id): bool => isset($claimed[(int) $id]))->values()->all();

        return $base;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function commit(Product $stack, array $plan): array
    {
        $priceBefore = $this->priceSnapshot($stack);
        DB::transaction(function () use ($plan): void {
            foreach ($plan['links'] as $link) {
                $image = Image::query()->find((int) $link['stack_image_id']);
                if (! $image instanceof Image) {
                    continue;
                }
                Image::withoutEvents(function () use ($image, $link): void {
                    $image->forceFill([
                        'source_image_id' => (int) $link['component_image_id'],
                    ])->save();
                });
            }
        });
        $stack->refresh();
        $priceAfter = $this->priceSnapshot($stack);
        if ($priceBefore !== $priceAfter) {
            throw new \RuntimeException('Stack repair changed variant prices.');
        }
        $plan['applied'] = true;

        return $plan;
    }

    private function componentImage(Product $component): ?Image
    {
        $image = $component->allImages()
            ->active()
            ->whereNotNull('shopify_id')
            ->where('shopify_id', '!=', '')
            ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        return $image instanceof Image ? $image : null;
    }

    private function samePhotograph(Image $componentImage, Image $stackImage): bool
    {
        $componentMedia = trim((string) $componentImage->shopify_id);
        $stackMedia = trim((string) $stackImage->shopify_id);
        if ($componentMedia !== '' && $componentMedia === $stackMedia) {
            return true;
        }
        $componentAsset = (int) ($componentImage->image_asset_id ?? 0);
        if ($componentAsset > 0 && $componentAsset === (int) ($stackImage->image_asset_id ?? 0)) {
            return true;
        }
        $componentUrl = $this->fileIdentity((string) $componentImage->src);
        $stackUrl = $this->fileIdentity((string) $stackImage->src);

        return $componentUrl !== '' && $componentUrl === $stackUrl;
    }

    private function fileIdentity(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if ($path === '') {
            return strtolower($url);
        }

        return $host.$path;
    }

    private function alt(Image $image): ?string
    {
        $alt = trim((string) ($image->alt_text ?? ''));

        return $alt === '' ? null : $alt;
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

    /**
     * @param array<int, mixed> $stackIds
     * @return array<int, int>
     */
    private function ids(array $stackIds): array
    {
        return collect($stackIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function sku(Product $product): string
    {
        $product->loadMissing('variants');

        return trim((string) ($product->variants->sortBy('id')->first()?->sku ?? ''));
    }

    /**
     * @return array<int, array{price:mixed, compare:mixed, sku:mixed}>
     */
    private function priceSnapshot(Product $stack): array
    {
        return Variant::query()->where('product_id', $stack->id)->orderBy('id')->get(['price', 'compare_at_price', 'sku'])
            ->map(fn (Variant $variant): array => [
                'price' => $variant->price,
                'compare' => $variant->compare_at_price,
                'sku' => $variant->sku,
            ])->all();
    }
}
