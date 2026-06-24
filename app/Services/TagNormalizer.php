<?php

namespace App\Services;

final class TagNormalizer
{
    public static function normalizeString(?string $value): ?string
    {
        $tokens = self::parseTokens($value);
        return empty($tokens) ? null : implode(', ', $tokens);
    }

    public static function normalizeFromArray(array $values): ?string
    {
        $tokens = [];
        $seen = [];

        foreach ($values as $value) {
            $token = self::normalizeToken((string) $value);
            if ($token === null) {
                continue;
            }

            $key = strtolower($token);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $tokens[] = $token;
        }

        return empty($tokens) ? null : implode(', ', $tokens);
    }

    public static function parseTokens(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $raw = trim($value);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[,;]/', $raw);
        if (!$parts) {
            return [];
        }

        $tokens = [];
        $seen = [];
        foreach ($parts as $part) {
            $token = self::normalizeToken($part);
            if ($token === null) {
                continue;
            }

            $key = strtolower($token);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $tokens[] = $token;
        }

        return $tokens;
    }

    public static function normalizeToken(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtolower($trimmed);
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized);
        $normalized = preg_replace('/-+/', '-', $normalized);
        $normalized = trim($normalized, '-');

        return $normalized === '' ? null : $normalized;
    }

    public static function containsBundleOrStackTag(?string $value): bool
    {
        foreach (self::parseTokens($value) as $tag) {
            if (in_array($tag, ['bundle', 'bundles', 'stack', 'stacks'], true)) {
                return true;
            }

            if (
                str_ends_with($tag, '-bundle')
                || str_ends_with($tag, '-bundles')
                || str_ends_with($tag, '-stack')
                || str_ends_with($tag, '-stacks')
            ) {
                return true;
            }
        }

        return false;
    }
}
