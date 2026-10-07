<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SiblingCollectionResolver
{
    /** @var array<int, array{gid:string,title:string,handle:string,tag_rules:array<int,string>}>|null */
    private ?array $candidateCache = null;

    public function __construct(
        private readonly ShopYourVibeShopify $shopify,
        private readonly DropdownCollectionCatalog $collectionCatalog,
    ) {}

    /**
     * @return array<int, array{gid:string,title:string,handle:string,tag_rules:array<int,string>}>
     */
    public function getSiblingCollections(): array
    {
        if ($this->candidateCache !== null) {
            return $this->candidateCache;
        }

        return $this->candidateCache = $this->shopify->siblingCollectionCandidates();
    }

    /**
     * @return array<int, array{tag:string,label:string,collection_gid:string,collection_title:string}>
     */
    public function optionsForParent(array $parent): array
    {
        $main = $this->mainCollectionContext($parent);
        if ($main['key'] === '') {
            return [];
        }

        return collect($this->getSiblingCollections())
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

    public function resolveCollectionGidForTags(?string $tags): ?string
    {
        $tokens = TagNormalizer::parseTokens($tags);
        if ($tokens === []) {
            return null;
        }

        $tokenKeys = collect($tokens)
            ->mapWithKeys(fn (string $tag): array => [mb_strtolower(trim($tag)) => true]);

        $matches = collect($this->getSiblingCollections())
            ->map(function (array $collection) use ($tokenKeys): ?array {
                foreach ($this->collectionSiblingTags($collection) as $tag) {
                    if (! $tokenKeys->has(mb_strtolower(trim($tag)))) {
                        continue;
                    }

                    return [
                        'gid' => (string) ($collection['gid'] ?? ''),
                        'score' => strlen($tag),
                    ];
                }

                return null;
            })
            ->filter()
            ->filter(fn (array $match): bool => trim((string) $match['gid']) !== '')
            ->sortByDesc('score')
            ->values();

        $best = $matches->first();

        return is_array($best) ? (string) $best['gid'] : null;
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

    /**
     * @return array<int, string>
     */
    private function collectionSiblingTags(array $collection): array
    {
        $sources = collect($collection['tag_rules'] ?? [])
            ->merge([
                (string) ($collection['handle'] ?? ''),
                (string) ($collection['title'] ?? ''),
            ]);

        return $sources
            ->map(fn (mixed $tag): string => trim((string) $tag))
            ->filter(fn (string $tag): bool => $tag !== '' && str_contains(Str::slug($tag), 'sibling'))
            ->unique(fn (string $tag): string => mb_strtolower($tag))
            ->values()
            ->all();
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
}
