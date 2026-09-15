<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ChangeLog;
use App\Models\NewProductDraft;
use App\Models\ShopifyRow;
use App\Models\ShopYourVibeCollectionMapping;
use App\Services\HeaderStore;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShopYourVibeAssignmentService
{
    private const GLOBAL_PARENT = '__global__';

    /** Reuse configuration saved for the same Shopify collection under any parent. */
    public static function configuredMappings(array $parentGids): \Illuminate\Support\Collection
    {
        $all = ShopYourVibeCollectionMapping::where('is_active', true)->latest('id')->get();
        return $all->whereIn('parent_collection_id', $parentGids)->map(function ($mapping) use ($all) {
            $resolved = clone $mapping;
            foreach (['membership_tag', 'design_value', 'colour_style_value'] as $field) {
                if (blank($resolved->{$field})) {
                    $source = $all->first(fn ($candidate) => $candidate->shopify_collection_id === $mapping->shopify_collection_id
                        && filled($candidate->{$field}));
                    $resolved->{$field} = $source?->{$field};
                }
            }
            return $resolved;
        })->values();
    }

    public function __construct(
        private readonly ShopYourVibeShopify $shopify,
        private readonly ProductShopifyUpdater $productUpdater,
    ) {}

    /** Reconcile only Shop Your Vibe membership tags from live Shopify product tags. */
    public function backfillMembershipTags(?int $userId = null): array
    {
        $mappings = ShopYourVibeCollectionMapping::query()
            ->where('is_active', true)
            ->where('parent_collection_id', '!=', self::GLOBAL_PARENT)
            ->whereNotNull('membership_tag')
            ->get();
        $managed = $mappings->pluck('membership_tag')->filter()->mapWithKeys(
            fn ($tag) => [mb_strtolower(trim((string) $tag)) => trim((string) $tag)]
        );
        $seen = [];
        $updated = 0;
        $skipped = 0;

        foreach ($mappings->groupBy('parent_collection_id') as $parentGid => $parentMappings) {
            foreach ($this->shopify->parentProducts($parentGid) as $remote) {
                $gid = (string) ($remote['id'] ?? '');
                if ($gid === '' || isset($seen[$gid])) {
                    continue;
                }
                $seen[$gid] = true;
                $product = Product::query()->where('shopify_id', $gid)->latest('id')->first();
                if (! $product) {
                    $skipped++;
                    continue;
                }

                $remoteTags = TagNormalizer::parseTokens(
                    TagNormalizer::normalizeFromArray((array) ($remote['tags'] ?? []))
                );
                $remoteManaged = collect($remoteTags)->filter(
                    fn ($tag) => $managed->has(mb_strtolower(trim((string) $tag)))
                )->values()->all();
                $localUnmanaged = collect(TagNormalizer::parseTokens($product->tags))->reject(
                    fn ($tag) => $managed->has(mb_strtolower(trim((string) $tag)))
                )->values()->all();
                $newTags = TagNormalizer::normalizeFromArray(array_merge($localUnmanaged, $remoteManaged));
                $oldTags = TagNormalizer::normalizeFromArray(TagNormalizer::parseTokens($product->tags));
                if (TagNormalizer::normalizeForComparison($oldTags) === TagNormalizer::normalizeForComparison($newTags)) {
                    continue;
                }

                Product::withoutEvents(fn () => Product::query()->whereKey($product->id)->update(['tags' => $newTags]));
                $drafts = NewProductDraft::query()->where(function ($query) use ($product): void {
                    $query->where('shopify_id', $product->shopify_id)->orWhere('handle', $product->handle);
                })->get();
                foreach ($drafts as $draft) {
                    $draftUnmanaged = collect(TagNormalizer::parseTokens($draft->tags))->reject(
                        fn ($tag) => $managed->has(mb_strtolower(trim((string) $tag)))
                    )->values()->all();
                    $warnings = collect($draft->shopify_sync_warnings ?? [])->reject(
                        fn ($warning) => data_get($warning, 'field') === 'tags'
                    )->values()->all();
                    NewProductDraft::withoutEvents(fn () => $draft->forceFill([
                        'tags' => TagNormalizer::normalizeFromArray(array_merge($draftUnmanaged, $remoteManaged)),
                        'shopify_sync_warnings' => $warnings !== [] ? $warnings : null,
                    ])->save());
                }
                ChangeLog::create([
                    'import_id' => $product->import_id,
                    'product_id' => $product->id,
                    'changed_by' => $userId,
                    'source' => 'shop_your_vibe_backfill',
                    'model_type' => Product::class,
                    'model_id' => $product->id,
                    'field' => 'tags',
                    'old_value' => $oldTags,
                    'new_value' => $newTags,
                ]);
                $updated++;
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'checked' => count($seen)];
    }

    /** Discover every configured vibe so a single upload can configure all parents. */
    public function syncAllMappings(): int
    {
        $count = 0;
        foreach ($this->shopify->parents() as $parent) {
            $state = $this->shopify->parent($parent['gid']);
            $gids = [];
            foreach ($state['cards'] as $card) {
                $gid = $card['collection_gid'] ?? null;
                if (! $gid) {
                    continue;
                }
                $this->syncMapping($parent['gid'], $card, $this->shopify->collectionMapping($gid));
                $gids[] = $gid;
                $count++;
            }
            $this->deactivateMissing($parent['gid'], $gids);
        }

        return $count;
    }

    public function syncMapping(string $parentGid, array $card, array $collection): ShopYourVibeCollectionMapping
    {
        $mapping = ShopYourVibeCollectionMapping::firstOrNew([
            'parent_collection_id' => $parentGid,
            'shopify_collection_id' => $collection['gid'],
        ]);
        if (! $mapping->exists) {
            $seed = ShopYourVibeCollectionMapping::query()
                ->where('shopify_collection_id', $collection['gid'])
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNotNull('membership_tag')->orWhereNotNull('design_value')->orWhereNotNull('colour_style_value'))
                ->latest('id')
                ->first();
            if ($seed) {
                $mapping->membership_tag = $seed->membership_tag;
                $mapping->design_value = $seed->design_value;
                $mapping->colour_style_value = $seed->colour_style_value;
            }
        }
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
     * @param array<int, array{match:string,design_value:?string,colour_style_value:?string,row:int}> $rows
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
            foreach (['design_value', 'colour_style_value'] as $field) {
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
                    'design_value' => $this->nullable($row['design_value'] ?? null),
                    'colour_style_value' => $this->nullable($row['colour_style_value'] ?? null),
                ]);

                return $mapping->refresh();
            }, $resolved);
        });
    }

    /** Configure matching vibe collections across every parent collection. */
    public function importMappingsGlobally(array $rows): array
    {
        $mappings = ShopYourVibeCollectionMapping::query()->where('is_active', true)->get();
        $resolved = [];
        $usedCollections = [];
        foreach ($rows as $row) {
            $match = mb_strtolower(trim($row['match']));
            $matches = $mappings->filter(fn ($mapping) => in_array($match, [
                mb_strtolower(trim($mapping->collection_handle)),
                mb_strtolower(trim($mapping->collection_name)),
            ], true));
            if ($matches->isEmpty()) {
                $collections = \App\Models\ShopifyCollection::query()
                    ->whereNotNull('shopify_id')
                    ->where(function ($query) use ($row): void {
                        $query->whereRaw('LOWER(handle) = ?', [mb_strtolower(trim($row['match']))])
                            ->orWhereRaw('LOWER(title) = ?', [mb_strtolower(trim($row['match']))]);
                    })
                    ->latest('id')
                    ->get()
                    ->unique('shopify_id');
                if ($collections->count() === 1) {
                    $collection = $collections->first();
                    $mapping = ShopYourVibeCollectionMapping::firstOrCreate([
                        'parent_collection_id' => self::GLOBAL_PARENT,
                        'shopify_collection_id' => $collection->shopify_id,
                    ], [
                        'collection_name' => $collection->title,
                        'collection_handle' => $collection->handle,
                        'is_active' => true,
                    ]);
                    $mappings->push($mapping);
                    $matches = collect([$mapping]);
                }
            }
            $collectionIds = $matches->pluck('shopify_collection_id')->unique()->values();
            if ($collectionIds->count() !== 1) {
                throw new RuntimeException('Row '.$row['row'].': collection ['.$row['match'].'] must match exactly one Shop Your Vibe collection name or handle.');
            }
            $collectionId = $collectionIds->first();
            $signature = mb_strtolower(trim((string) ($row['design_value'] ?? ''))).'|'.mb_strtolower(trim((string) ($row['colour_style_value'] ?? '')));
            if (isset($usedCollections[$collectionId])) {
                if ($usedCollections[$collectionId] !== $signature) {
                    throw new RuntimeException('Row '.$row['row'].': this collection has conflicting Design or Colour Style values in the file.');
                }
                continue;
            }
            foreach (['design_value', 'colour_style_value'] as $field) {
                if (mb_strlen((string) ($row[$field] ?? '')) > 255) {
                    throw new RuntimeException('Row '.$row['row'].': values may not exceed 255 characters.');
                }
            }
            $usedCollections[$collectionId] = $signature;
            $resolved[] = [$matches, $row];
        }

        return DB::transaction(function () use ($resolved): array {
            $updated = [];
            foreach ($resolved as [$matches, $row]) {
                foreach ($matches as $mapping) {
                    $mapping->update([
                        'design_value' => $this->nullable($row['design_value'] ?? null),
                        'colour_style_value' => $this->nullable($row['colour_style_value'] ?? null),
                    ]);
                    $updated[] = $mapping->refresh();
                }
            }

            return $updated;
        });
    }

    public function deactivateMissing(string $parentGid, array $collectionGids): void
    {
        ShopYourVibeCollectionMapping::query()
            ->where('parent_collection_id', $parentGid)
            ->when($collectionGids !== [], fn ($query) => $query->whereNotIn('shopify_collection_id', $collectionGids))
            ->update(['is_active' => false]);
    }

    /** Update only tags and product attributes owned by Shop Your Vibe. */
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

        $futureTagKeys = $currentByKey->keys()->reject(fn ($key) => collect($remove)->contains(fn ($tag) => mb_strtolower(trim($tag)) === $key));
        $futureTagKeys = $futureTagKeys->merge(collect($add)->map(fn ($tag) => mb_strtolower(trim($tag))))->unique();
        $globalMappings = ShopYourVibeCollectionMapping::query()->where('is_active', true)->get();
        $activeMappings = $globalMappings->filter(fn ($mapping) => filled($mapping->membership_tag)
            && $futureTagKeys->contains(mb_strtolower(trim($mapping->membership_tag))));
        $addedTagKeys = collect($add)->map(fn ($tag) => mb_strtolower(trim($tag)));
        $removedTagKeys = collect($remove)->map(fn ($tag) => mb_strtolower(trim($tag)));
        $addedMappings = $mappings->filter(fn ($mapping) => filled($mapping->membership_tag)
            && $addedTagKeys->contains(mb_strtolower(trim($mapping->membership_tag))));
        $removedMappings = $mappings->filter(fn ($mapping) => filled($mapping->membership_tag)
            && $removedTagKeys->contains(mb_strtolower(trim($mapping->membership_tag))));
        $protectedDesigns = $activeMappings->pluck('design_value')->filter()->map(fn ($value) => mb_strtolower(trim($value)));
        $protectedColours = $activeMappings->pluck('colour_style_value')->filter()->map(fn ($value) => mb_strtolower(trim($value)));
        $product = Product::query()->where('shopify_id', $productGid)->latest('id')->firstOrFail();
        $designHeader = HeaderStore::designHeaderForTypeAndTags($product->type, $product->tags);
        if ($designHeader === null && $addedMappings->pluck('design_value')->filter()->isNotEmpty()) {
            $collections = $addedMappings->filter(fn ($mapping) => filled($mapping->design_value))
                ->pluck('collection_name')->unique()->join(', ');
            $designs = $addedMappings->pluck('design_value')->filter()->unique()->join(', ');
            $type = filled($product->type) ? $product->type : 'not set';

            throw new RuntimeException(
                'Cannot apply Design ['.$designs.'] from Shop Your Vibe ['.$collections.'] to product ['.$product->title.'] '
                .'because its Product Type is ['.$type.']. Change the Product Type to Bracelets, Necklaces, or Earrings, '
                .'or remove this Shop Your Vibe selection, then try again. No Shopify changes were made.'
            );
        }
        $primaryRow = ShopifyRow::query()->where('import_id', $product->import_id)->where('handle', $product->handle)
            ->where('row_type', 'product_primary')->latest('id')->first();
        $before = [
            'tags' => TagNormalizer::normalizeFromArray($remote['tags']),
            'product_design' => $designHeader ? (string) $primaryRow?->get($designHeader, '') : '',
            'colour_style' => (string) $primaryRow?->get(HeaderStore::PATTERN_CATEGORY, ''),
        ];
        $this->productUpdater->syncShopYourVibeAttributes(
            $product,
            $addedMappings->pluck('design_value')->filter()->unique()->values()->all(),
            $addedMappings->pluck('colour_style_value')->filter()->unique()->values()->all(),
            $removedMappings->pluck('design_value')->filter(fn ($value) => ! $protectedDesigns->contains(mb_strtolower(trim($value))))->unique()->values()->all(),
            $removedMappings->pluck('colour_style_value')->filter(fn ($value) => ! $protectedColours->contains(mb_strtolower(trim($value))))->unique()->values()->all(),
        );

        if ($add !== []) {
            $this->shopify->addProductTags($productGid, $add);
        }
        if ($remove !== []) {
            $this->shopify->removeProductTags($productGid, $remove);
        }

        $confirmed = $this->shopify->productTags($productGid);
        $this->reconcileConfirmedState($product, $primaryRow?->fresh(), $designHeader, $before, $confirmed['tags']);

        return $confirmed;
    }

    private function reconcileConfirmedState(Product $product, ?ShopifyRow $row, ?string $designHeader, array $before, array $confirmedTags): void
    {
        $after = [
            'tags' => TagNormalizer::normalizeFromArray($confirmedTags),
            'product_design' => $designHeader ? (string) $row?->get($designHeader, '') : '',
            'colour_style' => (string) $row?->get(HeaderStore::PATTERN_CATEGORY, ''),
        ];

        DB::transaction(function () use ($product, $row, $before, $after): void {
            Product::query()->whereKey($product->id)->update(['tags' => $after['tags']]);
            $drafts = NewProductDraft::query()->where(function ($query) use ($product): void {
                $query->where('shopify_id', $product->shopify_id)->orWhere('handle', $product->handle);
            })->get();
            foreach ($drafts as $draft) {
                $warnings = collect($draft->shopify_sync_warnings ?? [])->reject(
                    fn ($warning) => in_array(data_get($warning, 'field'), ['tags', 'product_design', 'colour_style'], true)
                )->values()->all();
                NewProductDraft::withoutEvents(fn () => $draft->forceFill([
                    'tags' => $after['tags'],
                    'product_design' => $after['product_design'] !== '' ? $after['product_design'] : null,
                    'colour_style' => $after['colour_style'] !== '' ? $after['colour_style'] : null,
                    'shopify_sync_warnings' => $warnings !== [] ? $warnings : null,
                ])->save());
            }
            foreach ($after as $field => $newValue) {
                $oldValue = (string) ($before[$field] ?? '');
                if ($oldValue === (string) $newValue) {
                    continue;
                }
                ChangeLog::create([
                    'import_id' => $product->import_id,
                    'product_id' => $product->id,
                    'shopify_row_id' => $row?->id,
                    'changed_by' => auth()->id(),
                    'source' => 'shop_your_vibe',
                    'model_type' => Product::class,
                    'model_id' => $product->id,
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => (string) $newValue,
                ]);
            }
        });
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
