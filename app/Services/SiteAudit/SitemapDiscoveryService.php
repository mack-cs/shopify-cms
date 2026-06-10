<?php

namespace App\Services\SiteAudit;

use App\Models\SiteAuditUrl;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class SitemapDiscoveryService
{
    public function sync(?string $parentSitemapUrl = null): int
    {
        $parentSitemapUrl = trim((string) ($parentSitemapUrl ?? config('site-audit.sitemap_url')));

        if ($parentSitemapUrl === '') {
            throw new RuntimeException('SITE_AUDIT_SITEMAP_URL is not configured.');
        }

        $parentDocument = $this->fetchXml($parentSitemapUrl);
        $sitemapUrls = $this->extractLocs($parentDocument, 'sitemap');

        if ($sitemapUrls === [] && $this->extractLocs($parentDocument, 'url') !== []) {
            $sitemapUrls = [$parentSitemapUrl];
        }

        $discoveredUrls = [];

        foreach ($sitemapUrls as $sitemapUrl) {
            foreach ($this->getUrlsFromSitemap($sitemapUrl) as $url) {
                SiteAuditUrl::query()->updateOrCreate(
                    ['url' => $url],
                    [
                        'source' => 'sitemap',
                        'sitemap_url' => $sitemapUrl,
                        'resource_type' => $this->detectResourceType($url),
                        'is_active' => true,
                        'last_seen_at' => now(),
                    ],
                );

                $discoveredUrls[] = $url;
            }
        }

        $discoveredUrls = array_values(array_unique($discoveredUrls));

        if ($discoveredUrls !== []) {
            SiteAuditUrl::query()
                ->where('source', 'sitemap')
                ->whereNotIn('url', $discoveredUrls)
                ->update(['is_active' => false]);
        }

        return count($discoveredUrls);
    }

    public function getUrlsFromSitemap(string $sitemapUrl): array
    {
        try {
            return $this->extractLocs($this->fetchXml($sitemapUrl), 'url');
        } catch (Throwable) {
            return [];
        }
    }

    public function detectResourceType(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_contains($path, '/products/')) {
            return SiteAuditUrl::RESOURCE_PRODUCT;
        }

        if (str_contains($path, '/collections/')) {
            return SiteAuditUrl::RESOURCE_COLLECTION;
        }

        if (str_contains($path, '/blogs/')) {
            return SiteAuditUrl::RESOURCE_BLOG;
        }

        if (str_contains($path, '/pages/')) {
            return SiteAuditUrl::RESOURCE_PAGE;
        }

        return SiteAuditUrl::RESOURCE_UNKNOWN;
    }

    private function fetchXml(string $url): DOMDocument
    {
        $response = Http::timeout((int) config('site-audit.request_timeout_seconds', 20))
            ->retry(2, 500)
            ->withHeaders([
                'User-Agent' => (string) config('site-audit.user_agent'),
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to fetch sitemap: {$url}");
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($response->body(), LIBXML_NONET)) {
                throw new RuntimeException("Sitemap XML could not be parsed: {$url}");
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function extractLocs(DOMDocument $document, string $parentNodeName): array
    {
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query("//*[local-name() = '{$parentNodeName}']/*[local-name() = 'loc']");

        if ($nodes === false) {
            return [];
        }

        $urls = [];

        foreach ($nodes as $node) {
            $url = trim($node->textContent);

            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }
}
