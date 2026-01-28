<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use App\Services\TagNormalizer;

class DropdownOption extends Model
{
    protected $fillable = [
        'header',
        'value',
        'vendor',
        'product_type',
        'collection_style',
        'collection_tag_primary',
        'collection_tag_secondary',
        'active',
        'sort_order',
    ];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public static function optionsForHeader(
        string $header,
        ?string $vendor = null,
        ?string $productType = null,
        mixed $tags = null
    ): Collection {
        $query = self::query()
            ->where('header', $header)
            ->where('active', true);

        $normalizedTags = self::normalizeTags($tags);
        if (!empty($normalizedTags)) {
            $query->where(function ($tagQuery) use ($normalizedTags): void {
                $tagQuery->where(function ($match) use ($normalizedTags): void {
                    $match->whereIn('collection_tag_primary', $normalizedTags)
                        ->whereIn('collection_tag_secondary', $normalizedTags);
                })
                ->orWhere(function ($match) use ($normalizedTags): void {
                    $match->whereIn('collection_tag_primary', $normalizedTags)
                        ->whereNull('collection_tag_secondary');
                });
            });
        }

        if ($header === \App\Services\HeaderStore::COLOR_METAFIELD) {
            $vendor = self::normalizeFilter($vendor);
            $productType = self::normalizeFilter($productType);

            if ($vendor) {
                if ($productType) {
                    $specific = (clone $query)
                        ->whereRaw('LOWER(vendor) = ?', [strtolower($vendor)])
                        ->whereRaw('LOWER(product_type) = ?', [strtolower($productType)])
                        ->pluck('value');
                    if ($specific->isNotEmpty()) {
                        return $specific;
                    }
                }

                $vendorOnly = (clone $query)
                    ->whereRaw('LOWER(vendor) = ?', [strtolower($vendor)])
                    ->whereNull('product_type')
                    ->pluck('value');
                if ($vendorOnly->isNotEmpty()) {
                    return $vendorOnly;
                }
            }

            return $query
                ->whereNull('vendor')
                ->pluck('value');
        }

        return $query->pluck('value');
    }

    public static function activeHeaders(): Collection
    {
        return self::query()
            ->where('active', true)
            ->select('header')
            ->distinct()
            ->pluck('header');
    }

    private static function normalizeFilter(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalizeTags(mixed $tags): array
    {
        if ($tags === null) {
            return [];
        }

        if (is_array($tags)) {
            $tokens = [];
            foreach ($tags as $value) {
                $token = TagNormalizer::normalizeToken((string) $value);
                if ($token !== null) {
                    $tokens[] = $token;
                }
            }
            return array_values(array_unique($tokens));
        }

        return TagNormalizer::parseTokens((string) $tags);
    }
}
