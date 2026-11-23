<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\ShopifyCollection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ShopifyCollectionMembership
{
    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    /** Resolve the existing Products collection filter without inferring membership from tags. */
    public function resolveCollectionGids(array $values): array
    {
        $gids = [];
        foreach (array_unique(array_filter(array_map(fn ($value): string => trim((string) $value), $values))) as $value) {
            if ($value === '__none__') {
                throw new RuntimeException('Clear the No collection filter or select a Shopify collection before exporting alt text.');
            }
            $matches = ShopifyCollection::query()->whereNotNull('shopify_id')
                ->where(fn ($query) => $query->where('shopify_id', $value)
                    ->orWhere('title', $value)->orWhere('handle', Str::slug($value)))
                ->pluck('shopify_id')->unique()->values();
            if ($matches->count() !== 1) {
                throw new RuntimeException('Collection '.$value.' must match exactly one synced Shopify collection before export.');
            }
            $gids[] = (string) $matches->first();
        }

        return array_values(array_unique($gids));
    }

    /**
     * Product GIDs currently published in the collections with Shopify status ACTIVE.
     * One unreadable collection fails the whole read. Publication keeps this strict path.
     *
     * @param  array<int, string>  $collectionGids
     * @return array<int, string>
     */
    public function activeProductGids(array $collectionGids): array
    {
        $products = [];
        foreach ($this->selectedCollectionGids($collectionGids) as $collectionGid) {
            foreach ($this->paginateCollection($collectionGid) as $productGid) {
                $products[$productGid] = $productGid;
            }
        }

        return array_values($products);
    }

    /**
     * Resolve each selected collection on its own. A collection is merged only after all of its pages succeed.
     *
     * @param  array<int, string>  $collectionGids
     * @return array{product_gids: array<int, string>, skipped: array<int, array{gid: string, reason: string}>, resolved: array<int, string>}
     */
    public function resolveForExport(array $collectionGids): array
    {
        $selected = $this->selectedCollectionGids($collectionGids);
        if ($selected === []) {
            throw new RuntimeException('Select a Shopify collection before exporting alt text.');
        }

        $products = [];
        $skipped = [];
        $resolved = [];
        foreach ($selected as $collectionGid) {
            try {
                $members = $this->readCollection($collectionGid);
            } catch (Throwable $exception) {
                $skipped[] = ['gid' => $collectionGid, 'reason' => $exception->getMessage()];

                continue;
            }
            $resolved[] = $collectionGid;
            foreach ($members as $productGid) {
                $products[$productGid] = $productGid;
            }
        }

        if ($resolved === []) {
            $reasons = implode(' | ', array_map(
                fn (array $skipped): string => $skipped['gid'].': '.$skipped['reason'],
                $skipped
            ));

            throw new RuntimeException('No selected Shopify collection could be resolved. '.$reasons);
        }

        return [
            'product_gids' => array_values($products),
            'skipped' => $skipped,
            'resolved' => $resolved,
        ];
    }

    /**
     * @param  array<int, mixed>  $collectionGids
     * @return array<int, string>
     */
    private function selectedCollectionGids(array $collectionGids): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($gid): string => trim((string) $gid),
            $collectionGids
        ))));
    }

    /**
     * @return array<int, string>
     */
    private function readCollection(string $collectionGid): array
    {
        $delays = array_values(array_map(intval(...), (array) config('alt_text_exports.collection_retry_ms', [200, 400])));
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->paginateCollection($collectionGid);
            } catch (Throwable $exception) {
                if (! $this->transient($exception) || $attempt > count($delays)) {
                    throw $exception;
                }
                $delay = $delays[$attempt - 1] ?? 0;
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function paginateCollection(string $collectionGid): array
    {
        $products = [];
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

        return array_values($products);
    }

    private function transient(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        foreach (['throttl', 'rate limit', 'timed out', 'timeout', 'temporarily', 'connection', '429', '502', '503', '504'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
