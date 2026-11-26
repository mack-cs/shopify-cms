<?php

namespace App\Services;

/**
 * Central classifier for New In lifecycle tags.
 *
 * Normalization is used for classification only. Callers must preserve the
 * exact original Shopify tag spelling when issuing tagsRemove mutations.
 *
 * Auto-remove patterns are anchored / token-aware (not unrestricted substrings)
 * so tags like new-inventory, new-inspiration, new-arrival-notification, and
 * renew-in-store are not treated as New In classifications.
 */
final class NewInTagClassifier
{
    public const CLASS_AUTO_REMOVE = 'auto_remove';

    public const CLASS_AMBIGUOUS = 'ambiguous';

    public const CLASS_UNRELATED = 'unrelated';

    /** Exact canonical New In forms after TagNormalizer::normalizeToken(). */
    private const EXACT_AUTO_REMOVE = [
        'new-in',
        'newin',
        'new-arrivals',
        'new-arrival',
        'newbies',
    ];

    /**
     * @param  array<int, string>  $tags
     * @return array{
     *   auto_remove: array<int, string>,
     *   ambiguous: array<int, string>,
     *   unrelated: array<int, string>
     * }
     */
    public function classifyMany(array $tags): array
    {
        $result = [
            self::CLASS_AUTO_REMOVE => [],
            self::CLASS_AMBIGUOUS => [],
            self::CLASS_UNRELATED => [],
        ];

        foreach ($tags as $tag) {
            // Preserve the exact Shopify string (including outer whitespace) for mutations.
            // Normalization is used only inside classify().
            $raw = (string) $tag;
            if (trim($raw) === '') {
                continue;
            }
            $result[$this->classify($raw)][] = $raw;
        }

        foreach ($result as $key => $values) {
            $result[$key] = array_values(array_unique($values));
        }

        return $result;
    }

    public function classify(string $tag): string
    {
        $normalized = TagNormalizer::normalizeToken($tag);
        if ($normalized === null) {
            return self::CLASS_UNRELATED;
        }

        if ($this->isAutoRemoveNormalized($normalized)) {
            return self::CLASS_AUTO_REMOVE;
        }

        if ($this->isAmbiguousNormalized($normalized)) {
            return self::CLASS_AMBIGUOUS;
        }

        return self::CLASS_UNRELATED;
    }

    public function isAutoRemove(string $tag): bool
    {
        return $this->classify($tag) === self::CLASS_AUTO_REMOVE;
    }

    public function isAmbiguous(string $tag): bool
    {
        return $this->classify($tag) === self::CLASS_AMBIGUOUS;
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string> Original spellings classified as auto_remove.
     */
    public function autoRemoveFrom(array $tags): array
    {
        return $this->classifyMany($tags)[self::CLASS_AUTO_REMOVE];
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function stripAutoRemove(array $tags): array
    {
        return array_values(array_filter(
            $tags,
            fn (string $tag): bool => ! $this->isAutoRemove($tag)
        ));
    }

    /**
     * Remove only the intended New In tags (matched by normalized form), preserving
     * unrelated tags and any New In tags not in the intended set.
     *
     * @param  array<int, string>  $tags
     * @param  array<int, string>  $intendedExactOrNormalized
     * @return array<int, string>
     */
    public function stripIntended(array $tags, array $intendedExactOrNormalized): array
    {
        $intendedNormalized = [];
        foreach ($intendedExactOrNormalized as $tag) {
            $normalized = TagNormalizer::normalizeToken((string) $tag);
            if ($normalized !== null) {
                $intendedNormalized[$normalized] = true;
            }
        }

        return array_values(array_filter($tags, function (string $tag) use ($intendedNormalized): bool {
            $normalized = TagNormalizer::normalizeToken($tag);
            if ($normalized === null) {
                return true;
            }

            return ! isset($intendedNormalized[$normalized]);
        }));
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function hasAutoRemove(array $tags): bool
    {
        return $this->autoRemoveFrom($tags) !== [];
    }

    private function isAutoRemoveNormalized(string $normalized): bool
    {
        if (in_array($normalized, self::EXACT_AUTO_REMOVE, true)) {
            return true;
        }

        // Collection / stack / legacy: must end with a New In suffix after a non-empty prefix.
        // Examples: livi-road-new-in, elevated-basics-bracelet-stacks-new-in
        // Rejects: new-inventory, new-inspiration, new-arrival-notification, renew-in-store
        return (bool) preg_match(
            '/^.+-(?:new-in|new-arrivals|new-arrival|newbies)$/',
            $normalized
        );
    }

    private function isAmbiguousNormalized(string $normalized): bool
    {
        if ($this->isAutoRemoveNormalized($normalized)) {
            return false;
        }

        foreach (['newborn', 'renew', 'renewal', 'newspaper', 'anew'] as $noise) {
            if (str_contains($normalized, $noise)) {
                return false;
            }
        }

        // Standalone "new" token, or other new-* forms that are not approved New In suffixes.
        if (preg_match('/(^|-)new($|-)/', $normalized) === 1) {
            return true;
        }

        return false;
    }
}
