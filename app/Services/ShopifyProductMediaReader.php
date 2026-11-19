<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use RuntimeException;

/** All identity decisions use complete, uncached product connections. */
final class ShopifyProductMediaReader
{
    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    public function read(string $productId, string $queryName = 'ProductAltMedia'): array
    {
        return [
            'media' => ['nodes' => $this->connection($productId, 'media', $queryName)],
            'images' => ['nodes' => $this->connection($productId, 'images', $queryName.'Legacy')],
        ];
    }

    public function media(string $productId, string $queryName = 'ReadAttachedImageAlt'): array
    {
        return $this->connection($productId, 'media', $queryName);
    }

    private function connection(string $productId, string $field, string $queryName): array
    {
        $selection = $field === 'media'
            ? self::mediaNodeSelection()
            : 'id url';
        $cursor = null;
        $seen = [];
        $nodes = [];
        do {
            $data = $this->shopify->graphql("query {$queryName}(\$id: ID!, \$after: String) { product(id: \$id) { {$field}(first: 250, after: \$after) { nodes { {$selection} } pageInfo { hasNextPage endCursor } } } }", ['id' => $productId, 'after' => $cursor]);
            $connection = $data['product'][$field] ?? null;
            if (! is_array($connection) || ! is_array($connection['nodes'] ?? null)
                || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)) {
                throw new RuntimeException('Shopify returned an incomplete '.$field.' connection; no absence or identity decision is safe.');
            }
            $nodes = array_merge($nodes, $connection['nodes']);
            if (! $connection['pageInfo']['hasNextPage']) {
                break;
            }
            $cursor = $connection['pageInfo']['endCursor'] ?? null;
            if (! is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                throw new RuntimeException('Shopify media pagination did not advance.');
            }
            $seen[$cursor] = true;
        } while (true);

        return $nodes;
    }

    /** fileStatus belongs to MediaImage, not the Media interface. API 2026-01 rejects it there. */
    public static function mediaNodeSelection(): string
    {
        return 'id ... on MediaImage { alt status fileStatus image { url } originalSource { url } }';
    }

    public static function selectsFileStatusOnMediaInterface(string $query): bool
    {
        $withoutImageFragment = preg_replace('/\.\.\. on MediaImage \{.*?\}/s', '', $query) ?? $query;

        return str_contains($withoutImageFragment, 'fileStatus');
    }

    public static function ready(array $node): bool
    {
        return ($node['status'] ?? null) === 'READY'
            && ($node['fileStatus'] ?? null) === 'READY';
    }
}
