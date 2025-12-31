<?php

namespace App\Services;

final class CategoryTypeMap
{
    private const MAPPINGS = [
        [
            'category' => 'Apparel & Accessories > Jewelry > Bracelets',
            'type' => 'Bracelets',
            'google_product_category' => '191',
        ],
        [
            'category' => 'Gift Cards',
            'type' => 'Gift Cards',
            'google_product_category' => '53',
        ],
        [
            'category' => 'Apparel & Accessories > Jewelry > Charms & Pendants',
            'type' => 'Charms',
            'google_product_category' => '192',
        ],
        [
            'category' => 'Apparel & Accessories > Jewelry > Necklaces',
            'type' => 'Necklaces',
            'google_product_category' => '189',
        ],
        [
            'category' => 'Apparel & Accessories > Jewelry > Earrings',
            'type' => 'Earrings',
            'google_product_category' => '190',
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
        return array_map(fn (array $row) => $row['category'], self::MAPPINGS);
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
                'active' => true,
            ],
            self::MAPPINGS
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

        return null;
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

        $categoryMatch = $normalizedCategory ? self::byCategory($normalizedCategory) : null;
        $typeMatch = $normalizedType ? self::byType($normalizedType) : null;

        $mismatch = $categoryMatch && $typeMatch && $categoryMatch['type'] !== $typeMatch['type'];
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
}
