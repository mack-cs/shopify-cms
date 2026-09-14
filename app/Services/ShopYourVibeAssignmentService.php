<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShopYourVibeCollectionMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShopYourVibeAssignmentService
{
    public function __construct(private readonly ShopYourVibeShopify $shopify) {}

    public function syncMapping(string $parentGid, array $card, array $collection): ShopYourVibeCollectionMapping
    {
        $mapping = ShopYourVibeCollectionMapping::firstOrNew([
            'parent_collection_id' => $parentGid,
            'shopify_collection_id' => $collection['gid'],
        ]);
        $mapping->fill([
            'collection_name' => $collection['title'] ?: $card['name'],
            'collection_handle' => $collection['handle'],
            'is_active' => true,
        ]);

        // A configured value wins. Detection only fills a missing membership tag.
        if (blank($mapping->membership_tag) && filled($collection['detected_membership_tag'] ?? null)) {
            $mapping->membership_tag = $collection['detected_membership_tag'];
        }
        $mapping->save();

        return $mapping;
    }

    public function configure(int $id, array $values): ShopYourVibeCollectionMapping
    {
        $mapping = ShopYourVibeCollectionMapping::findOrFail($id);
        $mapping->update([
            'membership_tag' => $this->nullable($values['membership_tag'] ?? null),
            'design_value' => $this->nullable($values['design_value'] ?? null),
            'colour_style_value' => $this->nullable($values['colour_style_value'] ?? null),
        ]);

        return $mapping->refresh();
    }

    /**
     * @param array<int, array{match:string,membership_tag:string,design_value:?string,colour_style_value:?string,row:int}> $rows
     * @return array<int, ShopYourVibeCollectionMapping>
     */
    public function importMappings(string $parentGid, array $rows): array
    {
        $mappings = ShopYourVibeCollectionMapping::query()
            ->where('parent_collection_id', $parentGid)
            ->where('is_active', true)
            ->get();
        $resolved = [];
        $used = [];

        foreach ($rows as $row) {
            $match = mb_strtolower(trim($row['match']));
            $matches = $mappings->filter(fn ($mapping) => in_array($match, [
                mb_strtolower(trim($mapping->collection_handle)),
                mb_strtolower(trim($mapping->collection_name)),
            ], true));
            if ($matches->count() !== 1) {
                throw new RuntimeException('Row '.$row['row'].': collection ['.$row['match'].'] must match exactly one Shop Your Vibe name or handle.');
            }
            $mapping = $matches->first();
            if (isset($used[$mapping->id])) {
                throw new RuntimeException('Row '.$row['row'].': this collection appears more than once in the file.');
            }
            if (trim($row['membership_tag']) === '') {
                throw new RuntimeException('Row '.$row['row'].': Membership Tag is required.');
            }
            foreach (['membership_tag', 'design_value', 'colour_style_value'] as $field) {
                if (mb_strlen((string) ($row[$field] ?? '')) > 255) {
                    throw new RuntimeException('Row '.$row['row'].': values may not exceed 255 characters.');
                }
            }
            $used[$mapping->id] = true;
            $resolved[] = [$mapping, $row];
        }

        return DB::transaction(function () use ($resolved): array {
            return array_map(function (array $item): ShopYourVibeCollectionMapping {
                [$mapping, $row] = $item;
                $mapping->update([
                    'membership_tag' => trim($row['membership_tag']),
                    'design_value' => $this->nullable($row['design_value'] ?? null),
                    'colour_style_value' => $this->nullable($row['colour_style_value'] ?? null),
                ]);

                return $mapping->refresh();
            }, $resolved);
        });
    }

    public function deactivateMissing(string $parentGid, array $collectionGids): void
    {
        ShopYourVibeCollectionMapping::query()
            ->where('parent_collection_id', $parentGid)
            ->when($collectionGids !== [], fn ($query) => $query->whereNotIn('shopify_collection_id', $collectionGids))
            ->update(['is_active' => false]);
    }

    /**
     * Update only tags owned by Shop Your Vibe. Shopify remains authoritative.
     * Design and Colour Style are retained as generic mapping metadata until their
     * Shopify taxonomy/metaobject mappings are configured; they are never guessed as tags.
     */
    public function assign(string $parentGid, string $productGid, array $selectedCollectionGids): array
    {
        $mappings = ShopYourVibeCollectionMapping::query()
            ->where('parent_collection_id', $parentGid)
            ->where('is_active', true)
            ->get();
        $selected = array_fill_keys(array_unique($selectedCollectionGids), true);

        foreach (array_keys($selected) as $gid) {
            if (! $mappings->contains('shopify_collection_id', $gid)) {
                throw new RuntimeException('One of the selected Shop Your Vibes is not configured for this collection.');
            }
        }

        $missingTags = $mappings->filter(fn ($mapping) => isset($selected[$mapping->shopify_collection_id]) && blank($mapping->membership_tag));
        if ($missingTags->isNotEmpty()) {
            throw new RuntimeException('Configure the membership tag for: '.$missingTags->pluck('collection_name')->join(', ').'.');
        }

        $remote = $this->shopify->productTags($productGid);
        $currentByKey = collect($remote['tags'])->mapWithKeys(fn ($tag) => [mb_strtolower(trim($tag)) => $tag]);
        $add = [];
        $remove = [];
        $managedByTag = $mappings->filter(fn ($mapping) => filled($mapping->membership_tag))
            ->groupBy(fn ($mapping) => mb_strtolower(trim($mapping->membership_tag)));
        foreach ($managedByTag as $key => $tagMappings) {
            $tag = trim((string) $tagMappings->first()->membership_tag);
            $hasTag = $currentByKey->has($key);
            // A shared tag must remain while any selected vibe still requires it.
            $wantsTag = $tagMappings->contains(fn ($mapping) => isset($selected[$mapping->shopify_collection_id]));
            if ($wantsTag && ! $hasTag) {
                $add[] = $tag;
            } elseif (! $wantsTag && $hasTag) {
                $remove[] = $currentByKey->get($key);
            }
        }

        if ($add !== []) {
            $this->shopify->addProductTags($productGid, $add);
        }
        if ($remove !== []) {
            $this->shopify->removeProductTags($productGid, $remove);
        }

        $confirmed = $this->shopify->productTags($productGid);
        DB::transaction(function () use ($productGid, $confirmed): void {
            $productId = Product::query()->where('shopify_id', $productGid)->latest('id')->value('id');
            if ($productId) {
                Product::query()->whereKey($productId)->update([
                    'tags' => TagNormalizer::normalizeFromArray($confirmed['tags']),
                ]);
            }
        });

        return $confirmed;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
