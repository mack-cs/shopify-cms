<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use RuntimeException;

class ShopifyCollectionMembership
{
    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    /**
     * Product GIDs currently published in the collections with Shopify status ACTIVE.
     *
     * @param array<int, string> $collectionGids
     * @return array<int, string>
     */
    public function activeProductGids(array $collectionGids): array
    {
        $products = [];
        foreach (array_values(array_unique(array_filter(array_map(
            fn ($gid): string => trim((string) $gid),
            $collectionGids
        )))) as $collectionGid) {
            $cursor = null;
            $seenCursors = [];
            do {
                $seenKey = $cursor ?? "\0";
                if (isset($seenCursors[$seenKey])) {
                    throw new RuntimeException('Shopify collection '.$collectionGid.' repeated a page cursor.');
                }
                $seenCursors[$seenKey] = true;

                $data = $this->shopify->graphql(<<<'GQL'
query CollectionActiveProducts($id: ID!, $cursor: String) {
  collection(id: $id) {
    products(first: 100, after: $cursor) {
      nodes { id status }
      pageInfo { hasNextPage endCursor }
    }
  }
}
GQL, ['id' => $collectionGid, 'cursor' => $cursor]);

                $collection = $data['collection'] ?? null;
                if (! is_array($collection)) {
                    throw new RuntimeException('Shopify collection '.$collectionGid.' could not be read.');
                }

                $connection = $collection['products'] ?? null;
                if (! is_array($connection) || ! isset($connection['nodes'], $connection['pageInfo']) || ! is_array($connection['nodes']) || ! is_array($connection['pageInfo'])) {
                    throw new RuntimeException('Shopify collection '.$collectionGid.' returned an incomplete product page.');
                }

                foreach ($connection['nodes'] as $product) {
                    if (! is_array($product)) {
                        throw new RuntimeException('Shopify collection '.$collectionGid.' returned a malformed product.');
                    }
                    $gid = trim((string) ($product['id'] ?? ''));
                    if ($gid !== '' && strcasecmp((string) ($product['status'] ?? ''), 'ACTIVE') === 0) {
                        $products[$gid] = $gid;
                    }
                }

                $page = $connection['pageInfo'];
                if (! array_key_exists('hasNextPage', $page) || ! is_bool($page['hasNextPage'])) {
                    throw new RuntimeException('Shopify collection '.$collectionGid.' returned incomplete page information.');
                }
                if (! $page['hasNextPage']) {
                    $cursor = null;
                    continue;
                }

                $nextCursor = $page['endCursor'] ?? null;
                if (! is_string($nextCursor) || $nextCursor === '' || isset($seenCursors[$nextCursor])) {
                    throw new RuntimeException('Shopify collection '.$collectionGid.' has more products but did not return a new cursor.');
                }
                $cursor = $nextCursor;
            } while ($cursor !== null);
        }

        return array_values($products);
    }
}
