<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\NewProductDraft;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShopYourVibeSiblingService
{
    public function __construct(
        private readonly ShopYourVibeShopify $shopify,
        private readonly SiblingCollectionResolver $siblings,
    ) {}

    /**
     * @return array<int, array{tag:string,label:string,collection_gid:string,collection_title:string}>
     */
    public function optionsForParent(array $parent): array
    {
        return $this->siblings->optionsForParent($parent);
    }

    /**
     * @param array<int, string> $tags
     * @param array<int, array{tag:string,label:string}> $options
     * @return array<int, array{tag:string,label:string}>
     */
    public function selectedForTags(array $tags, array $options): array
    {
        return $this->siblings->selectedForTags($tags, $options);
    }

    /**
     * @param array<int, string> $selectedTags
     * @return array{tags:array<int,string>}
     */
    public function assign(array $parent, string $productGid, array $selectedTags): array
    {
        $options = $this->optionsForParent($parent);
        $validByKey = collect($options)->mapWithKeys(fn (array $option): array => [
            mb_strtolower(trim($option['tag'])) => $option['tag'],
        ]);
        $selected = collect($selectedTags)
            ->map(fn (mixed $tag): string => mb_strtolower(trim((string) $tag)))
            ->filter()
            ->unique()
            ->values();

        foreach ($selected as $tagKey) {
            if (! $validByKey->has($tagKey)) {
                throw new RuntimeException('One of the selected siblings is not available for this collection.');
            }
        }

        $remote = $this->shopify->productTags($productGid);
        $currentByKey = collect($remote['tags'])->mapWithKeys(fn ($tag): array => [mb_strtolower(trim((string) $tag)) => (string) $tag]);
        $managed = $validByKey->keys();
        $add = [];
        $remove = [];

        foreach ($managed as $tagKey) {
            $hasTag = $currentByKey->has($tagKey);
            $wantsTag = $selected->contains($tagKey);
            if ($wantsTag && ! $hasTag) {
                $add[] = $validByKey->get($tagKey);
            } elseif (! $wantsTag && $hasTag) {
                $remove[] = $currentByKey->get($tagKey);
            }
        }

        if ($add !== []) {
            $this->shopify->addProductTags($productGid, $add);
        }
        if ($remove !== []) {
            $this->shopify->removeProductTags($productGid, $remove);
        }

        $confirmed = $this->shopify->productTags($productGid);
        $this->reconcileTags($productGid, $remote['tags'], $confirmed['tags']);

        return $confirmed;
    }

    /**
     * @param array<int, string> $before
     * @param array<int, string> $after
     */
    private function reconcileTags(string $productGid, array $before, array $after): void
    {
        $product = Product::query()->where('shopify_id', $productGid)->latest('id')->first();
        if (! $product) {
            return;
        }

        $oldTags = TagNormalizer::normalizeFromArray($before);
        $newTags = TagNormalizer::normalizeFromArray($after);
        if (TagNormalizer::normalizeForComparison($oldTags) === TagNormalizer::normalizeForComparison($newTags)) {
            return;
        }

        DB::transaction(function () use ($product, $oldTags, $newTags): void {
            Product::query()->whereKey($product->id)->update(['tags' => $newTags]);
            NewProductDraft::query()
                ->where(fn ($query) => $query->where('shopify_id', $product->shopify_id)->orWhere('handle', $product->handle))
                ->get()
                ->each(function (NewProductDraft $draft) use ($newTags): void {
                    $warnings = collect($draft->shopify_sync_warnings ?? [])
                        ->reject(fn ($warning) => data_get($warning, 'field') === 'tags')
                        ->values()
                        ->all();
                    NewProductDraft::withoutEvents(fn () => $draft->forceFill([
                        'tags' => $newTags,
                        'shopify_sync_warnings' => $warnings !== [] ? $warnings : null,
                    ])->save());
                });

            ChangeLog::create([
                'import_id' => $product->import_id,
                'product_id' => $product->id,
                'changed_by' => auth()->id(),
                'source' => 'shop_your_vibe_siblings',
                'model_type' => Product::class,
                'model_id' => $product->id,
                'field' => 'tags',
                'old_value' => $oldTags,
                'new_value' => $newTags,
            ]);
        });
    }
}
