<?php

namespace App\Services\Shopify;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\ShopifyStackInventoryReservation;

final class StackOrderFulfillmentInspector
{
    /** @var array<string, array{gid:string,name:?string,display_fulfillment_status:?string,cancelled_at:?string,fulfilled:bool,known:bool}> */
    private array $snapshots = [];

    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    public function isFullyFulfilled(ShopifyStackInventoryReservation $reservation): bool
    {
        $snapshot = $this->snapshotFor($reservation->shopify_order_id);

        return (bool) ($snapshot['fulfilled'] ?? false);
    }

    /**
     * @param  array<int, string>  $orderIds
     * @return array<string, array{gid:string,name:?string,display_fulfillment_status:?string,cancelled_at:?string,fulfilled:bool,known:bool}>
     */
    public function snapshotsFor(array $orderIds, bool $strict = false): array
    {
        $gids = [];
        foreach ($orderIds as $orderId) {
            $gid = $this->gid((string) $orderId);
            if ($gid !== '') {
                $gids[$gid] = $gid;
            }
        }
        $missing = array_values(array_filter($gids, fn (string $gid): bool => ! array_key_exists($gid, $this->snapshots)));
        foreach (array_chunk($missing, 50) as $chunk) {
            $this->fetchChunk($chunk, $strict);
        }

        $out = [];
        foreach ($orderIds as $orderId) {
            $gid = $this->gid((string) $orderId);
            $out[(string) $orderId] = $this->snapshots[$gid] ?? $this->unknownSnapshot($gid);
        }

        return $out;
    }

    /**
     * @return array{gid:string,name:?string,display_fulfillment_status:?string,cancelled_at:?string,fulfilled:bool,known:bool}
     */
    public function snapshotFor(mixed $orderId): array
    {
        $gid = $this->gid((string) $orderId);
        if ($gid === '') {
            return $this->unknownSnapshot('');
        }

        return $this->snapshotsFor([$gid])[$gid];
    }

    /**
     * Live Shopify order. Transport failures throw so a reservation is not created from a guess.
     *
     * @return array{
     *     gid:string,
     *     known:bool,
     *     missing:bool,
     *     fulfilled:bool,
     *     cancelled:bool,
     *     display_fulfillment_status:?string,
     *     cancelled_at:?string,
     *     name:?string,
     *     lines:array<string, array{current:int, unfulfilled:?int}>
     * }
     */
    public function liveOrder(mixed $orderId): array
    {
        $gid = $this->gid((string) $orderId);
        if ($gid === '') {
            throw new \RuntimeException('Shopify order id is missing; no component stock was reserved.');
        }

        $data = $this->shopify->graphql(<<<'GRAPHQL'
query StackOrderLiveStatus($id: ID!) {
  order(id: $id) {
    id
    name
    displayFulfillmentStatus
    cancelledAt
    lineItems(first: 250) {
      nodes { id currentQuantity unfulfilledQuantity }
      pageInfo { hasNextPage endCursor }
    }
  }
}
GRAPHQL, ['id' => $gid]);

        $order = data_get($data, 'order', data_get($data, 'data.order'));
        if (! is_array($order)) {
            throw new \RuntimeException("Live Shopify did not return order {$gid}; no component stock was reserved.");
        }

        $lines = $this->lineMap((array) data_get($order, 'lineItems.nodes', []));
        $cursor = data_get($order, 'lineItems.pageInfo.endCursor');
        while ((bool) data_get($order, 'lineItems.pageInfo.hasNextPage', false) && is_string($cursor) && $cursor !== '') {
            $more = $this->shopify->graphql(<<<'GRAPHQL'
query StackOrderLiveLines($id: ID!, $after: String!) {
  order(id: $id) {
    lineItems(first: 250, after: $after) {
      nodes { id currentQuantity unfulfilledQuantity }
      pageInfo { hasNextPage endCursor }
    }
  }
}
GRAPHQL, ['id' => $gid, 'after' => $cursor]);
            $page = data_get($more, 'order.lineItems', data_get($more, 'data.order.lineItems', []));
            $lines += $this->lineMap((array) data_get($page, 'nodes', []));
            $cursor = data_get($page, 'pageInfo.endCursor');
            $order['lineItems']['pageInfo'] = data_get($page, 'pageInfo', []);
        }

        $status = $order['displayFulfillmentStatus'] ?? null;
        $cancelledAt = filled($order['cancelledAt'] ?? null) ? (string) $order['cancelledAt'] : null;

        return [
            'gid' => $gid,
            'known' => is_string($status) && trim($status) !== '',
            'missing' => false,
            'fulfilled' => $this->isFulfilledStatus($status),
            'cancelled' => $cancelledAt !== null,
            'display_fulfillment_status' => is_string($status) ? $status : null,
            'cancelled_at' => $cancelledAt,
            'name' => filled($order['name'] ?? null) ? (string) $order['name'] : null,
            'lines' => $lines,
        ];
    }

    /** @param array<int, string> $gids */
    private function fetchChunk(array $gids, bool $strict = false): void
    {
        try {
            $data = $this->shopify->graphql(<<<'GRAPHQL'
query StackOrderFulfillmentStatuses($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on Order {
      id
      name
      displayFulfillmentStatus
      cancelledAt
    }
  }
}
GRAPHQL, ['ids' => $gids]);
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }
            foreach ($gids as $gid) {
                $this->snapshots[$gid] = $this->unknownSnapshot($gid);
            }

            return;
        }

        $nodes = data_get($data, 'nodes', data_get($data, 'data.nodes', []));
        $found = [];
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            $gid = $this->gid((string) ($node['id'] ?? ''));
            if ($gid === '') {
                continue;
            }
            $status = $node['displayFulfillmentStatus'] ?? null;
            $this->snapshots[$gid] = [
                'gid' => $gid,
                'name' => filled($node['name'] ?? null) ? (string) $node['name'] : null,
                'display_fulfillment_status' => is_string($status) ? $status : null,
                'cancelled_at' => filled($node['cancelledAt'] ?? null) ? (string) $node['cancelledAt'] : null,
                'fulfilled' => $this->isFulfilledStatus($status),
                'known' => is_string($status) && trim($status) !== '',
            ];
            $found[$gid] = true;
        }
        foreach ($gids as $gid) {
            if (! isset($found[$gid])) {
                $this->snapshots[$gid] = $this->unknownSnapshot($gid);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @return array<string, array{current:int, unfulfilled:?int}>
     */
    private function lineMap(array $nodes): array
    {
        $lines = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $lineId = $this->lineGid((string) ($node['id'] ?? ''));
            if ($lineId === '') {
                continue;
            }
            $lines[$lineId] = [
                'current' => max(0, (int) ($node['currentQuantity'] ?? 0)),
                'unfulfilled' => array_key_exists('unfulfilledQuantity', $node) && $node['unfulfilledQuantity'] !== null
                    ? max(0, (int) $node['unfulfilledQuantity'])
                    : null,
            ];
        }

        return $lines;
    }

    private function lineGid(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        if (str_starts_with($id, 'gid://shopify/')) {
            return $id;
        }
        if (ctype_digit($id)) {
            return 'gid://shopify/LineItem/'.$id;
        }

        return $id;
    }

    private function isFulfilledStatus(mixed $status): bool
    {
        $normalized = strtoupper(str_replace([' ', '-'], '_', trim((string) $status)));

        return in_array($normalized, ['FULFILLED', 'COMPLETE', 'SHIPPED'], true);
    }

    private function gid(string $orderId): string
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return '';
        }
        if (str_starts_with($orderId, 'gid://shopify/')) {
            return $orderId;
        }
        if (ctype_digit($orderId)) {
            return 'gid://shopify/Order/'.$orderId;
        }

        return $orderId;
    }

    /**
     * @return array{gid:string,name:?string,display_fulfillment_status:?string,cancelled_at:?string,fulfilled:bool,known:bool}
     */
    private function unknownSnapshot(string $gid): array
    {
        return [
            'gid' => $gid,
            'name' => null,
            'display_fulfillment_status' => null,
            'cancelled_at' => null,
            'fulfilled' => false,
            'known' => false,
        ];
    }
}
