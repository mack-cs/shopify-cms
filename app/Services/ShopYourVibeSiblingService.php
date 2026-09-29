<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\NewProductDraft;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ShopYourVibeSiblingService
{
    public function __construct(
        private readonly ShopYourVibeShopify $shopify,
        private readonly DropdownCollectionCatalog $collectionCatalog,
    ) {}

    /**
     * @return array<int, array{tag:string,label:string,collection_gid:string,collection_title:string}>
     */
    public function optionsForParent(array $parent): array
    {
        $main = $this->mainCollectionContext($parent);
        if ($main['key'] === '') {
            return [];
        }

        return collect($this->shopify->siblingCollectionCandidates())
            ->map(fn (array $collection): ?array => $this->optionFromCollection($collection, $main))
            ->filter()
            ->unique(fn (array $option): string => mb_strtolower($option['tag']))
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $tags
     * @param array<int, array{tag:string,label:string}> $options
     * @return array<int, array{tag:string,label:string}>
     */
    public function selectedForTags(array $tags, array $options): array
    {
        $tagKeys = collect($tags)->mapWithKeys(fn (string $tag): array => [mb_strtolower(trim($tag)) => true]);

        return collect($options)
            ->filter(fn (array $option): bool => isset($tagKeys[mb_strtolower(trim($option['tag']))]))
            ->values()
            ->all();
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
     * @return array{key:string,prefixes:array<int,string>}
     */
    private function mainCollectionContext(array $parent): array
    {
        $title = trim((string) ($parent['title'] ?? ''));
        $handle = trim((string) ($parent['handle'] ?? ''));
        $candidates = array_values(array_filter([Str::slug($handle), Str::slug($title)]));

        foreach ($this->collectionCatalog->contexts() as $context) {
            $style = Str::slug((string) ($context['collection_style'] ?? ''));
            $primary = Str::slug((string) ($context['tag_primary'] ?? ''));
            $secondary = Str::slug((string) ($context['tag_secondary'] ?? ''));
            if (
                in_array($style, $candidates, true)
                || in_array($primary, $candidates, true)
                || in_array($secondary, $candidates, true)
            ) {
                return [
                    'key' => $primary ?: ($secondary ?: $style),
                    'prefixes' => array_values(array_unique(array_filter([$primary, $style, Str::slug($title), Str::slug($handle)]))),
                ];
            }
        }

        $fallback = preg_replace('/-(bracelets?|necklaces?|earrings?|rings?|charms?|anklets?|bundles?|stacks?)$/', '', Str::slug($handle ?: $title)) ?: Str::slug($handle ?: $title);

        return ['key' => $fallback, 'prefixes' => array_values(array_unique(array_filter([$fallback, Str::slug($title), Str::slug($handle)])))];
    }

    private function optionFromCollection(array $collection, array $main): ?array
    {
        $rules = collect($collection['tag_rules'] ?? [])
            ->map(fn (mixed $tag): string => trim((string) $tag))
            ->filter();
        $sources = $rules->merge([
            (string) ($collection['handle'] ?? ''),
            (string) ($collection['title'] ?? ''),
        ]);

        $matched = null;
        foreach ($sources as $source) {
            $slug = Str::slug($source);
            if (! preg_match('/(?:^|[-\s])siblings?$/i', str_replace('-', ' ', $slug))) {
                continue;
            }

            foreach ($main['prefixes'] as $prefix) {
                if ($prefix !== '' && str_starts_with($slug, $prefix . '-')) {
                    $matched = $source;
                    break 2;
                }
            }
        }

        if ($matched === null) {
            return null;
        }

        $canonical = $rules->first(function (string $rule) use ($main): bool {
            $slug = Str::slug($rule);

            return str_contains($slug, 'sibling')
                && collect($main['prefixes'])->contains(fn (string $prefix): bool => $prefix !== '' && str_starts_with($slug, $prefix . '-'));
        }) ?? (string) ($collection['handle'] ?? $matched);

        $label = $this->displayLabel($canonical, $main['prefixes']);
        if ($label === '') {
            return null;
        }

        return [
            'tag' => $canonical,
            'label' => $label,
            'collection_gid' => (string) ($collection['gid'] ?? ''),
            'collection_title' => (string) ($collection['title'] ?? ''),
        ];
    }

    /** @param array<int, string> $prefixes */
    private function displayLabel(string $tag, array $prefixes): string
    {
        $slug = Str::slug($tag);
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($slug, $prefix . '-')) {
                $slug = substr($slug, strlen($prefix) + 1);
                break;
            }
        }
        $slug = preg_replace('/-siblings?$/', '', $slug) ?? $slug;

        return trim(Str::headline(str_replace('-', ' ', $slug)));
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
