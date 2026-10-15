<?php

namespace App\Services;

/**
 * CMS-only variants of the existing Elevated Basics Bundles collection.
 * Both variants publish into that same Shopify collection.
 */
final class ElevatedBasicsStackCatalog
{
    public const BASE_COLLECTION = 'Elevated Basics Bundles';

    public const BRACELET_OPTION = 'Elevated Basics Bracelet Stacks';

    public const NECKLACE_OPTION = 'Elevated Basics Necklace Stacks';

    public const VENDOR = 'Elevated Basics Bundles';

    public const MEMBERSHIP_TAG = 'elevated-basics-bundles';

    public const NEW_IN_TAG = 'elevated-basics-stacks-new-in';

    public const LEGACY_NEW_IN_TAG = 'elevated-basics-bracelet-stacks-new-in';

    public const BRACELET_TAG = 'elevated-basics-bracelet-stacks';

    public const NECKLACE_TAG = 'elevated-basics-necklace-stacks';

    public static function canonical(?string $collection): ?string
    {
        $collection = trim((string) $collection);
        if ($collection === '') {
            return null;
        }

        foreach ([self::BASE_COLLECTION, self::BRACELET_OPTION, self::NECKLACE_OPTION] as $option) {
            if (strcasecmp($collection, $option) === 0) {
                return $option;
            }
        }

        return $collection;
    }

    public static function isVariant(?string $collection): bool
    {
        return in_array(self::canonical($collection), [self::BRACELET_OPTION, self::NECKLACE_OPTION], true);
    }

    /**
     * The base bundles name stays available for data lookups, but the collection
     * dropdown only offers the bracelet and necklace stack choices.
     *
     * @param array<string, string> $options
     * @return array<string, string>
     */
    public static function collectionSelectOptions(array $options): array
    {
        $replaced = false;
        foreach (array_keys($options) as $key) {
            if (strcasecmp((string) $key, self::BASE_COLLECTION) === 0) {
                unset($options[$key]);
                $replaced = true;
            }
        }

        if ($replaced) {
            $options[self::BRACELET_OPTION] = self::BRACELET_OPTION;
            $options[self::NECKLACE_OPTION] = self::NECKLACE_OPTION;
        }

        natcasesort($options);

        return $options;
    }

    public static function selectableCollection(?string $collection): ?string
    {
        if (self::canonical($collection) === self::BASE_COLLECTION) {
            return null;
        }

        return $collection;
    }

    /**
     * Dropdown data, vendor lookup, and Shopify collection membership stay on the base collection.
     */
    public static function sourceCollection(?string $collection): ?string
    {
        $canonical = self::canonical($collection);
        if ($canonical === null) {
            return null;
        }

        if ($canonical === self::BASE_COLLECTION || self::isVariant($canonical)) {
            return self::BASE_COLLECTION;
        }

        return $canonical;
    }

    public static function productType(?string $collection): ?string
    {
        return match (self::canonical($collection)) {
            self::BRACELET_OPTION => 'Bracelets',
            self::NECKLACE_OPTION => 'Necklaces',
            default => null,
        };
    }

    public static function identificationTag(?string $collection): ?string
    {
        return match (self::canonical($collection)) {
            self::BRACELET_OPTION => self::BRACELET_TAG,
            self::NECKLACE_OPTION => self::NECKLACE_TAG,
            default => null,
        };
    }

    /**
     * @param array<int, string> $tags
     */
    public static function hasAmbiguousIdentification(array $tags): bool
    {
        return in_array(self::BRACELET_TAG, $tags, true)
            && in_array(self::NECKLACE_TAG, $tags, true);
    }

    /**
     * @param array<int, string> $tags
     */
    public static function optionForTags(array $tags): ?string
    {
        if (self::hasAmbiguousIdentification($tags)) {
            return null;
        }

        if (in_array(self::BRACELET_TAG, $tags, true)) {
            return self::BRACELET_OPTION;
        }

        if (in_array(self::NECKLACE_TAG, $tags, true)) {
            return self::NECKLACE_OPTION;
        }

        return null;
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    public static function finalizeTags(array $tags, ?string $collection, bool $isNewIn): array
    {
        $identification = self::identificationTag($collection);
        if ($identification === null) {
            return array_values($tags);
        }

        $remove = [
            self::LEGACY_NEW_IN_TAG,
            self::BRACELET_TAG,
            self::NECKLACE_TAG,
            ...TagNormalizer::genericProductTypeTags(),
        ];
        if (!$isNewIn) {
            $remove[] = self::NEW_IN_TAG;
        }

        $tags = array_values(array_filter(
            $tags,
            static fn (string $tag): bool => !in_array($tag, $remove, true)
        ));
        $tags[] = 'bundles';
        $tags[] = self::MEMBERSHIP_TAG;
        $tags[] = $identification;
        if ($isNewIn) {
            $tags[] = self::NEW_IN_TAG;
        }

        return array_values(array_unique($tags));
    }
}
