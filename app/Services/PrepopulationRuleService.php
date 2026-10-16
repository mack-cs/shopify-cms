<?php

namespace App\Services;

use App\Models\DropdownOption;
use App\Models\PrepopulationRule;
use Illuminate\Support\Str;

final class PrepopulationRuleService
{
    public function ruleForCollection(?string $collection): ?PrepopulationRule
    {
        $token = TagNormalizer::normalizeToken((string) $collection);
        if (ElevatedBasicsStackCatalog::optionForTags(array_filter([$token ?? ''])) !== null) {
            return $this->elevatedBasicsStackRule()
                ?? $this->ruleFor($collection, PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION);
        }

        return $this->ruleFor($collection, PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION);
    }

    public function elevatedBasicsStackRule(): ?PrepopulationRule
    {
        return PrepopulationRule::query()
            ->where('behavior', PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION)
            ->where(function ($query): void {
                $query->whereRaw('LOWER(handle) IN (?, ?)', ['elevated-basics-stacks', 'elevated-basics-bundles'])
                    ->orWhereRaw('LOWER(collection_name) IN (?, ?)', ['elevated basics stacks', 'elevated basics bundles']);
            })
            ->orderByRaw("CASE LOWER(handle) WHEN 'elevated-basics-stacks' THEN 0 WHEN 'elevated-basics-bundles' THEN 1 ELSE 2 END")
            ->first();
    }

    public function shopYourVibeRuleForHandle(?string $handle): ?PrepopulationRule
    {
        return $this->ruleFor($handle, PrepopulationRule::BEHAVIOR_SHOP_YOUR_VIBE_DROPDOWN);
    }

    /** @return array<string, mixed> */
    public function applyRule(PrepopulationRule $rule, mixed $tags, ?string $currentType = null): array
    {
        $resolvedTags = $this->applyTagActions($tags, $rule->add_tags ?? [], $rule->remove_tags ?? []);

        return $this->updatesForRule($rule, $resolvedTags, $currentType);
    }

    /** @return array<string, mixed> */
    public function applyCollectionRuleReplacingManagedTags(PrepopulationRule $rule, mixed $tags, ?string $currentType = null): array
    {
        $resolvedTags = $this->removeManagedCollectionTags($tags, $rule);
        $resolvedTags = $this->applyTagActions($resolvedTags, $rule->add_tags ?? [], $rule->remove_tags ?? []);

        return $this->updatesForRule($rule, $resolvedTags, $currentType);
    }

    /** @return array<int, string> */
    public function removeManagedCollectionTags(mixed $tags, ?PrepopulationRule $except = null): array
    {
        $current = TagNormalizer::parseTokens(is_array($tags) ? TagNormalizer::normalizeFromArray($tags) : $tags);
        $managed = PrepopulationRule::query()
            ->where('behavior', PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION)
            ->when($except, fn ($query): mixed => $query->whereKeyNot($except->getKey()))
            ->get(['add_tags'])
            ->flatMap(fn (PrepopulationRule $rule): array => $rule->add_tags ?? [])
            ->map(fn (string $tag): ?string => TagNormalizer::normalizeToken($tag))
            ->filter()
            ->map(fn (string $tag): string => strtolower($tag))
            ->unique()
            ->all();

        return array_values(array_filter(
            $current,
            fn (string $tag): bool => ! in_array(strtolower($tag), $managed, true)
        ));
    }

    /** @param array<int, string> $resolvedTags @return array<string, mixed> */
    private function updatesForRule(PrepopulationRule $rule, array $resolvedTags, ?string $currentType = null): array
    {
        if ($this->isStackOrBundleContext($rule, $resolvedTags)) {
            $resolvedTags = TagNormalizer::withoutGenericProductTypeTags($resolvedTags);
        }

        $type = $rule->auto_type ?: $currentType;

        $updates = [
            'tags' => $resolvedTags,
            'vendor' => $this->resolvedVendor($rule, $resolvedTags),
            'type' => $rule->auto_type,
            'product_category' => $rule->auto_product_category,
            'google_product_category' => $rule->auto_google_product_category,
            'status' => $rule->auto_status,
            'colour_style' => $rule->auto_colour_style,
            'product_design' => $rule->auto_design,
        ];

        if ($rule->auto_colour_style) {
            $this->ensureDropdownOption(HeaderStore::PATTERN_CATEGORY, $rule->auto_colour_style, $rule);
        }

        if ($rule->auto_design) {
            $header = HeaderStore::designHeaderForTypeAndTags($type, $resolvedTags);
            if ($header !== null) {
                $this->ensureDropdownOption($header, $rule->auto_design, $rule);
            }
        }

        return array_filter($updates, fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<int, string> $resolvedTags
     */
    private function resolvedVendor(PrepopulationRule $rule, array $resolvedTags): ?string
    {
        $vendor = trim((string) ($rule->auto_vendor ?? ''));
        if ($vendor === '') {
            return null;
        }

        $isBundleRule = collect(array_merge(
            $resolvedTags,
            [(string) ($rule->handle ?? ''), (string) ($rule->collection_name ?? '')]
        ))->contains(function (string $value): bool {
            $slug = Str::slug($value);

            return in_array($slug, ['bundles', 'stacks'], true)
                || str_contains($slug, '-bundles')
                || str_contains($slug, '-bundle')
                || str_contains($slug, '-stacks')
                || str_contains($slug, '-stack');
        });

        if ($isBundleRule && strcasecmp($vendor, 'Elevated Basics') === 0) {
            return 'Elevated Basics Bundles';
        }

        return $vendor;
    }

    /**
     * @param array<int, string> $tags
     */
    private function isStackOrBundleContext(PrepopulationRule $rule, array $tags): bool
    {
        $candidates = array_merge(
            $tags,
            array_map(static fn (mixed $tag): string => (string) $tag, $rule->add_tags ?? []),
            [(string) ($rule->handle ?? ''), (string) ($rule->collection_name ?? '')]
        );

        return TagNormalizer::containsBundleOrStackTag(implode(',', $candidates));
    }

    /** @param array<int, string> $add @param array<int, string> $remove */
    public function applyTagActions(mixed $tags, array $add, array $remove): array
    {
        $current = TagNormalizer::parseTokens(is_array($tags) ? TagNormalizer::normalizeFromArray($tags) : $tags);
        $byKey = [];

        foreach (array_merge($current, $add) as $tag) {
            $normalized = TagNormalizer::normalizeToken((string) $tag);
            if ($normalized !== null) {
                $byKey[strtolower($normalized)] = $normalized;
            }
        }

        foreach ($remove as $tag) {
            $normalized = TagNormalizer::normalizeToken((string) $tag);
            if ($normalized !== null) {
                unset($byKey[strtolower($normalized)]);
            }
        }

        return array_values($byKey);
    }

    private function ruleFor(?string $value, string $behavior): ?PrepopulationRule
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $slugs = $this->collectionLookupSlugs($value);
        $lowerSlugs = array_map('strtolower', $slugs);

        return PrepopulationRule::query()
            ->where('behavior', $behavior)
            ->where(function ($query) use ($value, $slugs, $lowerSlugs): void {
                $query->whereRaw('LOWER(handle) IN (' . implode(',', array_fill(0, count($lowerSlugs), '?')) . ')', $lowerSlugs)
                    ->orWhereRaw('LOWER(collection_name) = ?', [strtolower($value)]);

                foreach ($slugs as $slug) {
                    $query->orWhereJsonContains('add_tags', $slug);
                }
            })
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function collectionLookupSlugs(string $value): array
    {
        $slug = Str::slug($value);
        $slugs = [$slug];

        foreach ([
            '-bundles' => '-stacks',
            '-bundle' => '-stack',
            '-stacks' => '-bundles',
            '-stack' => '-bundle',
        ] as $from => $to) {
            if (str_ends_with($slug, $from)) {
                $slugs[] = substr($slug, 0, -strlen($from)) . $to;
            }
        }

        if (str_contains($slug, '-bundle-')) {
            $slugs[] = str_replace('-bundle-', '-stack-', $slug);
        }

        if (str_contains($slug, '-stack-')) {
            $slugs[] = str_replace('-stack-', '-bundle-', $slug);
        }

        return array_values(array_unique($slugs));
    }

    private function ensureDropdownOption(string $header, string $value, PrepopulationRule $rule): void
    {
        $canonical = DropdownOption::canonicalValue($header, $value);
        $exists = DropdownOption::query()
            ->where('header', $header)
            ->get()
            ->contains(fn (DropdownOption $option): bool => DropdownOption::canonicalValue($header, $option->value) === $canonical);

        if ($exists) {
            return;
        }

        DropdownOption::query()->create([
            'header' => $header,
            'value' => $value,
            'collection_style' => $rule->auto_cms_collection ?: $rule->collection_name,
            'active' => true,
            'sort_order' => 0,
        ]);
    }
}
