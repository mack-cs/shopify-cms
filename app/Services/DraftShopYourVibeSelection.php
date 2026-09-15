<?php

namespace App\Services;

use App\Models\DropdownOption;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Models\ShopYourVibeCollectionMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Local draft edits only. Membership tags travel through the normal product approval and push. */
class DraftShopYourVibeSelection
{
    public function mappings(?string $collection, ?string $vendor): Collection
    {
        if (blank($collection) && blank($vendor)) {
            return collect();
        }
        $rows = DropdownOption::where('collection_style', $collection)->get();
        $specific = collect([$collection])->merge($rows->pluck('collection_tag_secondary'))->filter()
            ->map(fn ($value) => $this->contextKey($value))->unique();
        $brand = collect([$vendor])->merge($rows->pluck('collection_tag_primary'))->filter()
            ->map(fn ($value) => $this->contextKey($value))->unique();
        $parents = ShopifyCollection::whereNotNull('shopify_id')->latest('id')->get(['shopify_id', 'title', 'handle'])
            ->map(fn ($parent) => (object) $parent->only(['shopify_id', 'title', 'handle']));
        // The management page discovers parents directly from Shopify, independently of catalogue imports.
        foreach (Cache::get('shop-your-vibe-parents:'.config('services.shopify.shop'), []) as $parent) {
            $parents->push((object) ['shopify_id' => $parent['gid'], 'title' => $parent['title'], 'handle' => $parent['handle']]);
        }
        foreach (ShopYourVibeDraft::get(['collection_gid', 'snapshot']) as $draft) {
            $parent = $draft->snapshot['parent'] ?? [];
            if (isset($parent['title'], $parent['handle'])) {
                $parents->push((object) ['shopify_id' => $draft->collection_gid, 'title' => $parent['title'], 'handle' => $parent['handle']]);
            }
        }
        $matches = fn ($tokens) => $parents->filter(fn ($parent) => $tokens->contains($this->contextKey($parent->handle))
            || $tokens->contains($this->contextKey($parent->title)))->pluck('shopify_id');
        $parentIds = filled($collection) ? $matches($specific) : collect();
        if ($parentIds->isEmpty()) {
            $parentIds = $matches($brand);
        }

        return ShopYourVibeAssignmentService::configuredMappings($parentIds->all())
            ->sortBy('collection_name')->unique('shopify_collection_id')->values();
    }

    public function options(?string $collection, ?string $vendor): array
    {
        return $this->mappings($collection, $vendor)->mapWithKeys(fn ($mapping) => [
            $mapping->shopify_collection_id => $mapping->collection_name.' ('.$mapping->collection_handle.')'
                .(blank($mapping->membership_tag) ? ' — membership tag needs configuration' : ''),
        ])->all();
    }

    public function selected(mixed $tags, ?string $collection, ?string $vendor): array
    {
        $tags = $this->tags($tags);

        return $this->mappings($collection, $vendor)->filter(fn ($mapping) => $this->tags($mapping->membership_tag) !== []
            && array_diff($this->tags($mapping->membership_tag), $tags) === [])
            ->pluck('shopify_collection_id')->values()->all();
    }

    public function unavailable(?string $collection, ?string $vendor): array
    {
        return $this->mappings($collection, $vendor)->filter(fn ($mapping) => blank($mapping->membership_tag))
            ->pluck('shopify_collection_id')->all();
    }

    public function refresh(?string $collection, ?string $vendor): void
    {
        if (blank($collection) && blank($vendor)) {
            return;
        }
        $parents = app(ShopYourVibeShopify::class)->parents();
        Cache::put('shop-your-vibe-parents:'.config('services.shopify.shop'), $parents, 300);
        $rows = DropdownOption::where('collection_style', $collection)->get();
        $specific = collect([$collection])->merge($rows->pluck('collection_tag_secondary'))->filter()
            ->map(fn ($value) => $this->contextKey($value));
        $brand = collect([$vendor])->merge($rows->pluck('collection_tag_primary'))->filter()
            ->map(fn ($value) => $this->contextKey($value));
        $matches = fn ($tokens) => collect($parents)->filter(fn ($parent) => $tokens->contains($this->contextKey($parent['title']))
            || $tokens->contains($this->contextKey($parent['handle'])));
        $selectedParents = $matches($specific);
        if ($selectedParents->isEmpty()) {
            $selectedParents = $matches($brand);
        }
        $shopify = app(ShopYourVibeShopify::class);
        $service = app(ShopYourVibeAssignmentService::class);
        foreach ($selectedParents as $parent) {
            $state = $shopify->parent($parent['gid']);
            $gids = [];
            foreach ($state['cards'] as $card) {
                if (filled($card['collection_gid'] ?? null)) {
                    $gid = $card['collection_gid'];
                    $service->syncMapping($parent['gid'], $card, $shopify->collectionMapping($gid));
                    $gids[] = $gid;
                }
            }
            $service->deactivateMissing($parent['gid'], $gids);
        }
    }

    public function apply(mixed $tags, array $selected, ?string $collection, ?string $vendor, array $protected = []): array
    {
        $mappings = $this->mappings($collection, $vendor);
        if (array_diff($selected, $mappings->pluck('shopify_collection_id')->all())
            || array_intersect($selected, $this->unavailable($collection, $vendor))) {
            throw ValidationException::withMessages(['shop_your_vibe_collections' => 'Choose Shop Your Vibe collections available for the current collection or vendor.']);
        }
        // Include inactive mappings so a changed collection does not retain obsolete membership tags.
        $managed = ShopYourVibeCollectionMapping::pluck('membership_tag')->flatMap(fn ($value) => $this->tags($value))->unique()->all();
        $wanted = $mappings->whereIn('shopify_collection_id', $selected)->pluck('membership_tag')
            ->flatMap(fn ($value) => $this->tags($value))->all();

        return $this->tags(array_merge(array_diff($this->tags($tags), $managed), $wanted, $protected));
    }

    private function contextKey(?string $value): string
    {
        // The draft catalogue calls these Bundles; the storefront calls them Stacks.
        // Keep the brand prefix so unrelated stack collections cannot match.
        return ltrim(preg_replace('/(?:^|-)bundles?$/', '-stacks', Str::slug($value ?? '')), '-');
    }

    private function tags(mixed $tags): array
    {
        return TagNormalizer::parseTokens(is_array($tags) ? TagNormalizer::normalizeFromArray($tags) : $tags);
    }
}
