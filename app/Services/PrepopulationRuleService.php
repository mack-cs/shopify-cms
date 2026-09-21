<?php

namespace App\Services;

use App\Models\DropdownOption;
use App\Models\PrepopulationRule;
use Illuminate\Support\Str;

final class PrepopulationRuleService
{
    public function ruleForCollection(?string $collection): ?PrepopulationRule
    {
        return $this->ruleFor($collection, PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION);
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
        $type = $rule->auto_type ?: $currentType;

        $updates = [
            'tags' => $resolvedTags,
            'vendor' => $rule->auto_vendor,
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

        $slug = Str::slug($value);

        return PrepopulationRule::query()
            ->where('behavior', $behavior)
            ->where(function ($query) use ($value, $slug): void {
                $query->whereRaw('LOWER(handle) = ?', [strtolower($slug)])
                    ->orWhereRaw('LOWER(collection_name) = ?', [strtolower($value)]);
            })
            ->first();
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
