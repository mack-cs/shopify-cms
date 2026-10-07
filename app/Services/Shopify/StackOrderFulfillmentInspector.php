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
    public function snapshotsFor(array $orderIds): array
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
            $this->fetchChunk($chunk);
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

    /** @param array<int, string> $gids */
    private function fetchChunk(array $gids): void
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
        } catch (\Throwable) {
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
