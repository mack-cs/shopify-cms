<?php

namespace App\Services;

use App\Models\NewProductDraft;
use Throwable;

final class NewInTagService
{
    public const TAGS = [
        'new-arrivals',
        'new-in',
        'newbies',
    ];

    public function __construct(
        private readonly DropdownCollectionCatalog $collectionCatalog
    ) {
    }

    /**
     * @param array<int, string> $existing
     * @return array<int, string>
     */
    public function tagsForNewProduct(array $existing, mixed $type = null, mixed $title = null): array
    {
        $existing = TagNormalizer::parseTokens(TagNormalizer::normalizeFromArray($existing));
        $required = self::TAGS;
        $context = $this->collectionContextForTags($existing);
        $collectionTag = TagNormalizer::normalizeToken((string) ($context['tag_primary'] ?? ''));

        $existing = array_values(array_filter(
            $existing,
            fn (string $tag): bool => !$this->isManagedCollectionNewInTag($tag)
        ));

        if ($collectionTag !== null) {
            if ($this->isStack($existing, $type, $title)) {
                $productType = $this->productTypeTag($type, $existing, $title);
                if ($productType !== null) {
                    $required[] = "{$collectionTag}-{$productType}-stacks-new-in";
                }
            } else {
                $required[] = "{$collectionTag}-new-in";
            }
        }

        return TagNormalizer::parseTokens(TagNormalizer::normalizeFromArray(array_merge($existing, $required)));
    }

    /**
     * @param  iterable<int, mixed>  $drafts
     * @return array{updated:int,already_marked:int,failed:int}
     */
    public function markDrafts(iterable $drafts): array
    {
        $updated = 0;
        $alreadyMarked = 0;
        $failed = 0;

        foreach ($drafts as $draft) {
            if (! $draft instanceof NewProductDraft) {
                continue;
            }

            $existing = TagNormalizer::parseTokens($draft->tags);
            $desired = $this->tagsForNewProduct($existing, $draft->type, $draft->title);
            if ($desired === $existing) {
                $alreadyMarked++;

                continue;
            }

            try {
                $draft->tags = TagNormalizer::normalizeFromArray($desired);
                $draft->save();
                $updated++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return [
            'updated' => $updated,
            'already_marked' => $alreadyMarked,
            'failed' => $failed,
        ];
    }

    /**
     * @param array<int, string> $tags
     * @return array{collection_style:string,tag_primary:string,tag_secondary:?string}|null
     */
    private function collectionContextForTags(array $tags): ?array
    {
        foreach ($this->collectionCatalog->contexts() as $context) {
            $primary = TagNormalizer::normalizeToken((string) ($context['tag_primary'] ?? ''));
            $secondary = TagNormalizer::normalizeToken((string) ($context['tag_secondary'] ?? ''));

            if (
                $secondary !== null
                && in_array($secondary, $tags, true)
                && (
                    $primary === null
                    || in_array($primary, $tags, true)
                    || str_starts_with($secondary, $primary . '-')
                )
            ) {
                return $context;
            }

            if ($primary !== null && in_array($primary, $tags, true) && $secondary === null) {
                return $context;
            }
        }

        foreach ($this->collectionCatalog->contexts() as $context) {
            $primary = TagNormalizer::normalizeToken((string) ($context['tag_primary'] ?? ''));
            if ($primary !== null && in_array($primary, $tags, true)) {
                return $context;
            }
        }

        return null;
    }

    /** @param array<int, string> $tags */
    private function isStack(array $tags, mixed $type, mixed $title): bool
    {
        $typeTag = TagNormalizer::normalizeToken((string) ($type ?? ''));
        if (in_array($typeTag, ['bundle', 'bundles', 'stack', 'stacks'], true)) {
            return true;
        }

        foreach ($tags as $tag) {
            if (
                in_array($tag, ['bundle', 'bundles', 'stack', 'stacks'], true)
                || str_ends_with($tag, '-bundle')
                || str_ends_with($tag, '-bundles')
                || str_ends_with($tag, '-stack')
                || str_ends_with($tag, '-stacks')
            ) {
                return true;
            }
        }

        $titleTag = TagNormalizer::normalizeToken((string) ($title ?? ''));

        return $titleTag !== null && (str_contains($titleTag, '-stack') || str_contains($titleTag, '-bundle'));
    }

    /** @param array<int, string> $tags */
    private function productTypeTag(mixed $type, array $tags, mixed $title): ?string
    {
        $candidates = [TagNormalizer::normalizeToken((string) ($type ?? ''))];

        foreach (['bracelet', 'necklace', 'earring', 'charm', 'anklet', 'ring'] as $productType) {
            if (in_array($productType, $tags, true) || in_array("{$productType}s", $tags, true)) {
                $candidates[] = $productType;
            }
        }

        $titleTag = TagNormalizer::normalizeToken((string) ($title ?? ''));
        if ($titleTag !== null) {
            foreach (['bracelet', 'necklace', 'earring', 'charm', 'anklet', 'ring'] as $productType) {
                if (str_contains($titleTag, $productType)) {
                    $candidates[] = $productType;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate === null || in_array($candidate, ['bundle', 'bundles', 'stack', 'stacks'], true)) {
                continue;
            }

            return match ($candidate) {
                'bracelets' => 'bracelet',
                'necklaces' => 'necklace',
                'earrings' => 'earring',
                'charms' => 'charm',
                'anklets' => 'anklet',
                'rings' => 'ring',
                default => str_ends_with($candidate, 's') && strlen($candidate) > 3
                    ? substr($candidate, 0, -1)
                    : $candidate,
            };
        }

        // The current bundle collections in Shopify are bracelet-stack
        // collections, even when the form's Type has not been selected yet.
        return 'bracelet';
    }

    private function isManagedCollectionNewInTag(string $tag): bool
    {
        foreach ($this->collectionCatalog->contexts() as $context) {
            $primary = TagNormalizer::normalizeToken((string) ($context['tag_primary'] ?? ''));
            if ($primary === null) {
                continue;
            }

            if (
                $tag === "{$primary}-new-in"
                || (str_starts_with($tag, $primary . '-') && str_ends_with($tag, '-stacks-new-in'))
            ) {
                return true;
            }
        }

        return false;
    }
}
