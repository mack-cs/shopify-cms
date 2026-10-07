<?php

namespace App\Services;

final class ShopifyTaxonomyValueNormalizer
{
    public function normalize(string $header, string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (! $this->usesHandleValues($header)) {
            return $trimmed;
        }

        $tokens = $this->normalizeMany($header, $trimmed);

        return implode('; ', $tokens);
    }

    /**
     * @return array<int, string>
     */
    public function normalizeMany(string $header, string $value): array
    {
        if (! $this->usesHandleValues($header)) {
            $trimmed = trim($value);

            return $trimmed === '' ? [] : [$trimmed];
        }

        $parts = preg_split('/[;,]+/', $value) ?: [];
        $tokens = [];
        $seen = [];

        foreach ($parts as $part) {
            $token = $this->normalizeToken($part);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $tokens[] = $token;
        }

        return $tokens;
    }

    public function usesHandleValues(string $header): bool
    {
        return in_array($header, [
            HeaderStore::JEWELRY_MATERIAL,
        ], true);
    }

    private function normalizeToken(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $normalized = html_entity_decode($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = strtolower($normalized);
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/-+/', '-', $normalized) ?? $normalized;

        return trim($normalized, '-');
    }
}
