<?php

namespace App\Services;

final class SaleTagService
{
    public const SALE_TAG = 'sale';
    public const EXCLUDE_FROM_SALE_TAG = 'exclude-from-the-sale';

    private const TYPE_SALE_TAGS = [
        'bracelet' => 'bracelets-sale',
        'bracelets' => 'bracelets-sale',
        'necklace' => 'necklaces-sale',
        'necklaces' => 'necklaces-sale',
        'earring' => 'earrings-sale',
        'earrings' => 'earrings-sale',
        'charm' => 'charms-sale',
        'charms' => 'charms-sale',
    ];

    private const COLLECTION_SALE_TAGS = [
        'livi-road' => 'livi-road-sale',
        'elevated-basics' => 'elevated-basics-sale',
        'untamed' => 'untamed-sale',
        'pata-pata' => 'pata-pata-sale',
        'elements-of-desire' => 'elements-of-desire-sale',
    ];

    /**
     * @param array<int, string>|string|null $tags
     * @return array<int, string>
     */
    public function apply(array|string|null $tags, bool $isOnSale, mixed $type = null): array
    {
        $tokens = is_array($tags)
            ? TagNormalizer::parseTokens(TagNormalizer::normalizeFromArray($tags))
            : TagNormalizer::parseTokens($tags);

        $tokens = array_values(array_filter(
            $tokens,
            fn (string $tag): bool => !in_array($tag, $this->managedSaleTags(), true)
        ));

        if (!$isOnSale) {
            $tokens[] = self::EXCLUDE_FROM_SALE_TAG;

            return TagNormalizer::parseTokens(TagNormalizer::normalizeFromArray($tokens));
        }

        $tokens[] = self::SALE_TAG;

        $typeSaleTag = $this->typeSaleTag($type, $tokens);
        if ($typeSaleTag !== null) {
            $tokens[] = $typeSaleTag;
        }

        foreach ($this->collectionSaleTags($tokens) as $collectionSaleTag) {
            $tokens[] = $collectionSaleTag;
        }

        return TagNormalizer::parseTokens(TagNormalizer::normalizeFromArray($tokens));
    }

    public function normalizeForStorage(array|string|null $tags, bool $isOnSale, mixed $type = null): ?string
    {
        return TagNormalizer::normalizeFromArray($this->apply($tags, $isOnSale, $type));
    }

    /**
     * @return array<int, string>
     */
    private function managedSaleTags(): array
    {
        return array_values(array_unique(array_merge(
            [self::SALE_TAG, self::EXCLUDE_FROM_SALE_TAG],
            array_values(self::TYPE_SALE_TAGS),
            array_values(self::COLLECTION_SALE_TAGS),
        )));
    }

    /**
     * @param array<int, string> $tags
     */
    private function typeSaleTag(mixed $type, array $tags): ?string
    {
        $typeToken = TagNormalizer::normalizeToken((string) ($type ?? ''));
        if ($typeToken !== null && isset(self::TYPE_SALE_TAGS[$typeToken])) {
            return self::TYPE_SALE_TAGS[$typeToken];
        }

        foreach ($tags as $tag) {
            if (isset(self::TYPE_SALE_TAGS[$tag])) {
                return self::TYPE_SALE_TAGS[$tag];
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private function collectionSaleTags(array $tags): array
    {
        $saleTags = [];
        foreach ($tags as $tag) {
            if (isset(self::COLLECTION_SALE_TAGS[$tag])) {
                $saleTags[] = self::COLLECTION_SALE_TAGS[$tag];
            }
        }

        return array_values(array_unique($saleTags));
    }
}
