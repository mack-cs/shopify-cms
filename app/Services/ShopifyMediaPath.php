<?php

namespace App\Services;

final class ShopifyMediaPath
{
    public static function canonical(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || str_starts_with($url, 's3://')) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $path = rtrim((string) $parts['path'], '/');

        return $host.$path;
    }

    public static function isCmsDeliveryUrl(?string $url): bool
    {
        $path = (string) (parse_url(trim((string) $url), PHP_URL_PATH) ?? '');

        return str_contains($path, '/product-image-backups/');
    }

    public static function rememberableCdnUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || self::canonical($url) === null || self::isCmsDeliveryUrl($url)) {
            return null;
        }

        return $url;
    }
}
