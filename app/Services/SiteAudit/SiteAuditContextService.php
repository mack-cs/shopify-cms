<?php

namespace App\Services\SiteAudit;

use App\Models\SiteAuditResult;
use App\Models\SiteAuditUrl;
use App\Services\ShopifyApiClient;
use Throwable;

final class SiteAuditContextService
{
    public function __construct(
        private readonly ShopifyApiClient $shopifyApiClient,
    ) {
    }

    /**
     * @return array{error_reason: string|null, shopify_resource_status: string|null, shopify_context: array<string, mixed>|null}
     */
    public function explain(
        SiteAuditUrl $auditUrl,
        string $result,
        ?int $statusCode,
        ?string $finalUrl,
        ?Throwable $exception = null,
    ): array {
        $context = [
            'error_reason' => $this->httpReason($auditUrl, $result, $statusCode, $finalUrl, $exception),
            'shopify_resource_status' => null,
            'shopify_context' => null,
        ];

        if (! in_array($result, SiteAuditResult::ISSUE_RESULTS, true)) {
            return $context;
        }

        if ($result !== SiteAuditResult::RESULT_BROKEN) {
            return $context;
        }

        $lookup = $this->lookupShopifyResource($auditUrl);

        if ($lookup === null) {
            return $context;
        }

        $context['shopify_resource_status'] = $lookup['status'];
        $context['shopify_context'] = $lookup;
        $context['error_reason'] = $this->shopifyReason($auditUrl, $lookup, $context['error_reason']);

        return $context;
    }

    private function httpReason(
        SiteAuditUrl $auditUrl,
        string $result,
        ?int $statusCode,
        ?string $finalUrl,
        ?Throwable $exception = null,
    ): string {
        if ($exception instanceof Throwable) {
            $message = strtolower($exception->getMessage());

            if (str_contains($message, 'redirect')) {
                return 'The public request failed while following redirects. This may indicate a redirect loop or an invalid redirect chain.';
            }

            if (str_contains($message, 'could not resolve host') || str_contains($message, 'curl error 6')) {
                return 'DNS host not resolved. Check the domain, DNS, or network route used by the audit worker.';
            }

            if (str_contains($message, 'connection refused') || str_contains($message, 'failed to connect')) {
                return 'Connection failed before Shopify returned a response.';
            }

            return match ($result) {
                SiteAuditResult::RESULT_TIMEOUT => 'The public request timed out before Shopify returned a response.',
                SiteAuditResult::RESULT_SSL_ERROR => 'The public request failed because of an SSL or certificate problem.',
                default => 'The public request failed before a usable response was returned.',
            };
        }

        if ($result === SiteAuditResult::RESULT_REDIRECT) {
            return $finalUrl && $finalUrl !== $auditUrl->url
                ? 'The URL redirects visitors to the final URL shown.'
                : 'The URL returned a redirect status.';
        }

        if ($result === SiteAuditResult::RESULT_BROKEN) {
            $resource = $this->resourceLabel($auditUrl);
            $suffix = $finalUrl === $auditUrl->url || $finalUrl === null
                ? ' No redirect happened before the 404/410 response.'
                : ' The final URL still returned a broken response.';

            return "{$resource} URL returned HTTP {$statusCode}.{$suffix}";
        }

        if ($result === SiteAuditResult::RESULT_SERVER_ERROR) {
            return "The public URL returned HTTP {$statusCode}, which indicates a Shopify/server-side error.";
        }

        if ($result === SiteAuditResult::RESULT_RATE_LIMITED) {
            return 'Shopify returned HTTP 429 Too Many Requests. The audit could not confirm whether this URL is healthy; recheck it after the request rate has cooled down.';
        }

        if ($result === SiteAuditResult::RESULT_FAILED) {
            return $statusCode
                ? "The public URL returned unexpected HTTP {$statusCode}."
                : 'The public request failed before an HTTP status code was available.';
        }

        return 'The URL loaded successfully.';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupShopifyResource(SiteAuditUrl $auditUrl): ?array
    {
        $handle = $this->handleFromUrl($auditUrl);

        if ($handle === '') {
            return null;
        }

        return match ($auditUrl->resource_type) {
            SiteAuditUrl::RESOURCE_PRODUCT => $this->lookupProduct($handle),
            SiteAuditUrl::RESOURCE_COLLECTION => $this->lookupCollection($handle),
            SiteAuditUrl::RESOURCE_PAGE => $this->lookupPage($handle),
            SiteAuditUrl::RESOURCE_BLOG => $this->lookupBlog($auditUrl),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupProduct(string $handle): ?array
    {
        try {
            $data = $this->shopifyApiClient->graphql(<<<'GQL'
query SiteAuditProductByHandle($handle: String!) {
  productByHandle(handle: $handle) {
    id
    handle
    title
    status
  }
}
GQL, [
                'handle' => $handle,
            ]);
        } catch (Throwable $exception) {
            return $this->lookupFailedContext('product', $handle, $exception);
        }

        $product = data_get($data, 'productByHandle');

        if (! is_array($product) || empty($product['id'])) {
            return [
                'resource_type' => 'product',
                'handle' => $handle,
                'status' => 'not_found',
                'found' => false,
            ];
        }

        return [
            'resource_type' => 'product',
            'handle' => $handle,
            'status' => strtolower((string) ($product['status'] ?? 'found')),
            'found' => true,
            'title' => (string) ($product['title'] ?? ''),
            'shopify_id' => (string) ($product['id'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupCollection(string $handle): ?array
    {
        try {
            $data = $this->shopifyApiClient->graphql(<<<'GQL'
query SiteAuditCollectionByHandle($query: String!) {
  collections(first: 1, query: $query) {
    nodes {
      id
      handle
      title
    }
  }
}
GQL, [
                'query' => 'handle:' . $handle,
            ]);
        } catch (Throwable $exception) {
            return $this->lookupFailedContext('collection', $handle, $exception);
        }

        $collection = collect(data_get($data, 'collections.nodes', []))
            ->first(fn ($node): bool => is_array($node) && ($node['handle'] ?? null) === $handle);

        if (! is_array($collection) || empty($collection['id'])) {
            return [
                'resource_type' => 'collection',
                'handle' => $handle,
                'status' => 'not_found',
                'found' => false,
            ];
        }

        return [
            'resource_type' => 'collection',
            'handle' => $handle,
            'status' => 'found',
            'found' => true,
            'title' => (string) ($collection['title'] ?? ''),
            'shopify_id' => (string) ($collection['id'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupPage(string $handle): ?array
    {
        try {
            $data = $this->shopifyApiClient->graphql(<<<'GQL'
query SiteAuditPageByHandle($query: String!) {
  pages(first: 1, query: $query) {
    nodes {
      id
      handle
      title
    }
  }
}
GQL, [
                'query' => 'handle:' . $handle,
            ]);
        } catch (Throwable $exception) {
            return $this->lookupFailedContext('page', $handle, $exception);
        }

        $page = collect(data_get($data, 'pages.nodes', []))
            ->first(fn ($node): bool => is_array($node) && ($node['handle'] ?? null) === $handle);

        if (! is_array($page) || empty($page['id'])) {
            return [
                'resource_type' => 'page',
                'handle' => $handle,
                'status' => 'not_found',
                'found' => false,
            ];
        }

        return [
            'resource_type' => 'page',
            'handle' => $handle,
            'status' => 'found',
            'found' => true,
            'title' => (string) ($page['title'] ?? ''),
            'shopify_id' => (string) ($page['id'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupBlog(SiteAuditUrl $auditUrl): ?array
    {
        $segments = $this->pathSegments($auditUrl->url);
        $blogIndex = array_search('blogs', $segments, true);
        $blogHandle = $blogIndex !== false ? (string) ($segments[$blogIndex + 1] ?? '') : '';
        $articleHandle = $blogIndex !== false ? (string) ($segments[$blogIndex + 2] ?? '') : '';

        if ($blogHandle === '') {
            return null;
        }

        try {
            $data = $this->shopifyApiClient->graphql(<<<'GQL'
query SiteAuditBlogByHandle($query: String!) {
  blogs(first: 1, query: $query) {
    nodes {
      id
      handle
      title
    }
  }
}
GQL, [
                'query' => 'handle:' . $blogHandle,
            ]);
        } catch (Throwable $exception) {
            return $this->lookupFailedContext('blog', $blogHandle, $exception);
        }

        $blog = collect(data_get($data, 'blogs.nodes', []))
            ->first(fn ($node): bool => is_array($node) && ($node['handle'] ?? null) === $blogHandle);

        if (! is_array($blog) || empty($blog['id'])) {
            return [
                'resource_type' => 'blog',
                'handle' => $blogHandle,
                'article_handle' => $articleHandle,
                'status' => 'not_found',
                'found' => false,
            ];
        }

        return [
            'resource_type' => 'blog',
            'handle' => $blogHandle,
            'article_handle' => $articleHandle,
            'status' => $articleHandle === '' ? 'found' : 'blog_found_article_not_verified',
            'found' => true,
            'title' => (string) ($blog['title'] ?? ''),
            'shopify_id' => (string) ($blog['id'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupFailedContext(string $resourceType, string $handle, Throwable $exception): array
    {
        return [
            'resource_type' => $resourceType,
            'handle' => $handle,
            'status' => 'lookup_failed',
            'found' => null,
            'lookup_error' => $exception->getMessage(),
        ];
    }

    /**
     * @param array<string, mixed> $lookup
     */
    private function shopifyReason(SiteAuditUrl $auditUrl, array $lookup, string $fallback): string
    {
        $resource = $this->resourceLabel($auditUrl);
        $status = (string) ($lookup['status'] ?? '');
        $title = trim((string) ($lookup['title'] ?? ''));
        $label = $title !== '' ? "{$resource} \"{$title}\"" : $resource;

        if (($lookup['found'] ?? null) === false || $status === 'not_found') {
            return "{$resource} handle not found in Shopify. The URL is still in the sitemap but the Shopify resource appears deleted or renamed.";
        }

        if ($status === 'lookup_failed') {
            return "{$fallback} Shopify context lookup failed: " . (string) ($lookup['lookup_error'] ?? 'unknown error');
        }

        if ($auditUrl->resource_type === SiteAuditUrl::RESOURCE_PRODUCT && ! in_array($status, ['active', 'found'], true)) {
            return "{$label} exists in Shopify but its status is " . strtoupper($status) . '. It may not be published to the Online Store.';
        }

        if ($status === 'blog_found_article_not_verified') {
            return "{$label} exists in Shopify, but the article handle was not verified. Check whether the article exists and is published.";
        }

        return "{$label} exists in Shopify, but the public URL still returned 404/410. Check Online Store publication, redirects, theme routing, or sitemap freshness.";
    }

    private function handleFromUrl(SiteAuditUrl $auditUrl): string
    {
        $segments = $this->pathSegments($auditUrl->url);

        $key = match ($auditUrl->resource_type) {
            SiteAuditUrl::RESOURCE_PRODUCT => 'products',
            SiteAuditUrl::RESOURCE_COLLECTION => 'collections',
            SiteAuditUrl::RESOURCE_PAGE => 'pages',
            SiteAuditUrl::RESOURCE_BLOG => 'blogs',
            default => null,
        };

        if ($key === null) {
            return '';
        }

        $index = array_search($key, $segments, true);

        return $index === false ? '' : (string) ($segments[$index + 1] ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function pathSegments(string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));
    }

    private function resourceLabel(SiteAuditUrl $auditUrl): string
    {
        return match ($auditUrl->resource_type) {
            SiteAuditUrl::RESOURCE_PRODUCT => 'Product',
            SiteAuditUrl::RESOURCE_COLLECTION => 'Collection',
            SiteAuditUrl::RESOURCE_PAGE => 'Page',
            SiteAuditUrl::RESOURCE_BLOG => 'Blog',
            default => 'Public',
        };
    }
}
