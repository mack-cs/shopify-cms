<?php

namespace App\Services;

final class CategoryTypeMap
{
    private const MAPPINGS = [
        [
            'category' => 'Apparel & Accessories > Jewelry > Bracelets',
            'type' => 'Bracelets',
            'google_product_category' => '191',
            'shopify_taxonomy_gid' => 'gid://shopify/TaxonomyCategory/aa-6-3',
        ],
        [
            'category' => 'Gift Cards',
            'type' => 'Gift Cards',
            'google_product_category' => '53',
            'shopify_taxonomy_gid' => 'gid://shopify/TaxonomyCategory/gc',
        ],
        [
            'category' => 'Apparel & Accessories > Jewelry > Charms & Pendants',
            'type' => 'Charms',
            'google_product_category' => '192',
            'shopify_taxonomy_gid' => 'gid://shopify/TaxonomyCategory/aa-6-5',
        ],
        [
            'category' => 'Apparel & Accessories > Jewelry > Necklaces',
            'type' => 'Necklaces',
            'google_product_category' => '189',
            'shopify_taxonomy_gid' => 'gid://shopify/TaxonomyCategory/aa-6-8',
        ],
        [
            'category' => 'Apparel & Accessories > Jewelry > Earrings',
            'type' => 'Earrings',
            'google_product_category' => '190',
            'shopify_taxonomy_gid' => 'gid://shopify/TaxonomyCategory/aa-6-6',
        ],
    ];

    private const EXTRA_CATEGORY_MAPPINGS = [
        [
            'category' => 'Health & Beauty > Jewelry Cleaning & Care > Jewelry Holders > Jewelry Boxes',
            'type' => null,
            'google_product_category' => null,
            'shopify_taxonomy_gid' => 'gid://shopify/TaxonomyCategory/hb-2-3-1',
        ],
        [
            'category' => 'Arts & Entertainment > Party & Celebration > Gift Giving > Gift Wrapping',
            'type' => null,
            'google_product_category' => '94',
            'shopify_taxonomy_gid' => 'gid://shopify/TaxonomyCategory/ae-3-1-5',
        ],
        [
            'category' => 'Health & Beauty > Jewelry Cleaning & Care > Jewelry Holders',
            'type' => null,
            'google_product_category' => '5974',
            'shopify_taxonomy_gid' => 'gid://shopify/TaxonomyCategory/hb-2-3',
        ],
        [
            'category' => 'Home & Garden > Decor > Chair & Sofa Cushions',
            'type' => null,
            'google_product_category' => '4453',
            'shopify_taxonomy_gid' => 'gid://shopify/TaxonomyCategory/hg-3-15',
        ],
        [
            'category' => 'Arts & Entertainment > Party & Celebration > Gift Giving > Gift Wrapping > Gift Boxes & Tins',
            'type' => null,
            'google_product_category' => '5091',
            'shopify_taxonomy_gid' => null,
        ],
    ];

    private const TYPE_ALIASES = [
        'bracelet' => 'Bracelets',
        'bracelets' => 'Bracelets',
        'gift card' => 'Gift Cards',
        'gift cards' => 'Gift Cards',
        'charm' => 'Charms',
        'charms' => 'Charms',
        'necklace' => 'Necklaces',
        'necklaces' => 'Necklaces',
        'earring' => 'Earrings',
        'earrings' => 'Earrings',
    ];

    public static function mappings(): array
    {
        return self::MAPPINGS;
    }

    public static function categories(): array
    {
        return array_values(array_unique(array_map(
            fn (array $row) => $row['category'],
            self::categoryMappings()
        )));
    }

    /**
     * @return array<string, string> key=value to store, value=label for users
     */
    public static function categoryOptions(): array
    {
        $options = [];

        foreach (self::categoryMappings() as $row) {
            $label = $row['category'];
            $gid = self::normalizeTaxonomyGid($row['shopify_taxonomy_gid'] ?? null);
            $key = $gid ?? $label;
            $options[$key] = $label;
        }

        return $options;
    }

    public static function types(): array
    {
        return array_map(fn (array $row) => $row['type'], self::MAPPINGS);
    }

    public static function categoryRows(): array
    {
        return array_map(
            fn (array $row) => [
                'name' => $row['category'],
                'google_product_category' => $row['google_product_category'],
                'shopify_taxonomy_gid' => $row['shopify_taxonomy_gid'],
                'active' => true,
            ],
            self::categoryMappings()
        );
    }

    public static function typeRows(): array
    {
        return array_map(
            fn (array $row) => [
                'name' => $row['type'],
                'google_product_category' => $row['google_product_category'],
            ],
            self::MAPPINGS
        );
    }

    public static function byCategory(?string $category): ?array
    {
        $normalized = self::normalizeCategory($category);
        if ($normalized === null) {
            return null;
        }

        foreach (self::MAPPINGS as $row) {
            if (strcasecmp($row['category'], $normalized) === 0) {
                return $row;
            }
        }

        foreach (self::EXTRA_CATEGORY_MAPPINGS as $row) {
            if (strcasecmp($row['category'], $normalized) === 0) {
                return $row;
            }
        }

        return null;
    }

    public static function byCategoryValue(?string $value): ?array
    {
        $gid = self::normalizeTaxonomyGid($value);
        if ($gid !== null) {
            foreach (self::categoryMappings() as $row) {
                if (($row['shopify_taxonomy_gid'] ?? null) === $gid) {
                    return $row;
                }
            }
        }

        return self::byCategory($value);
    }

    public static function categoryLabelForValue(?string $value): ?string
    {
        $match = self::byCategoryValue($value);
        if ($match !== null) {
            return $match['category'] ?? null;
        }

        return self::normalizeCategory($value) ?? $value;
    }

    public static function byType(?string $type): ?array
    {
        $normalized = self::normalizeType($type);
        if ($normalized === null) {
            return null;
        }

        foreach (self::MAPPINGS as $row) {
            if (strcasecmp($row['type'], $normalized) === 0) {
                return $row;
            }
        }

        return null;
    }

    public static function byGoogleCategory(?string $googleCategory): ?array
    {
        $normalized = self::normalizeGoogleCategory($googleCategory);
        if ($normalized === null) {
            return null;
        }

        foreach (self::MAPPINGS as $row) {
            if ($row['google_product_category'] === $normalized) {
                return $row;
            }
        }

        return null;
    }

    public static function resolve(?string $category, ?string $type, ?string $googleCategory): array
    {
        $normalizedCategory = self::normalizeCategory($category);
        $normalizedType = self::normalizeType($type);
        $normalizedGoogle = self::normalizeGoogleCategory($googleCategory);

        $categoryMatch = $normalizedCategory ? self::byCategoryValue($normalizedCategory) : null;
        $typeMatch = $normalizedType ? self::byType($normalizedType) : null;

        $mismatch = $categoryMatch
            && $typeMatch
            && !empty($categoryMatch['type'])
            && !empty($typeMatch['type'])
            && $categoryMatch['type'] !== $typeMatch['type'];
        if ($mismatch) {
            return [
                'category' => $normalizedCategory,
                'type' => $normalizedType,
                'google_product_category' => $normalizedGoogle,
                'matched' => false,
                'mismatch' => true,
            ];
        }

        $resolved = $categoryMatch ?? $typeMatch ?? self::byGoogleCategory($normalizedGoogle);
        if ($resolved) {
            return [
                'category' => $resolved['category'],
                'type' => $resolved['type'],
                'google_product_category' => $resolved['google_product_category'],
                'matched' => true,
                'mismatch' => false,
            ];
        }

        return [
            'category' => $normalizedCategory,
            'type' => $normalizedType,
            'google_product_category' => $normalizedGoogle,
            'matched' => false,
            'mismatch' => false,
        ];
    }

    public static function normalizeCategory(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = preg_replace('/\s*>\s*/', ' > ', $trimmed);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized ?? '');
        return $normalized === '' ? null : $normalized;
    }

    public static function normalizeType(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $key = strtolower(preg_replace('/\s+/', ' ', $trimmed));
        return self::TYPE_ALIASES[$key] ?? $trimmed;
    }

    public static function normalizeGoogleCategory(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    public static function normalizeTaxonomyGid(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || !str_starts_with($trimmed, 'gid://shopify/')) {
            return null;
        }

        return $trimmed;
    }

    private static function categoryMappings(): array
    {
        return array_merge(self::MAPPINGS, self::EXTRA_CATEGORY_MAPPINGS);
    }
}
