<?php

namespace App\Services\Shopify;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\ShopifyStackInventoryReservation;

final class StackOrderFulfillmentInspector
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    public function isFullyFulfilled(ShopifyStackInventoryReservation $reservation): bool
    {
        $orderId = trim((string) $reservation->shopify_order_id);
        if ($orderId === '') {
            return false;
        }
        if (array_key_exists($orderId, $this->cache)) {
            return $this->cache[$orderId];
        }

        return $this->cache[$orderId] = $this->liveStatus($orderId) === true;
    }

    private function liveStatus(string $orderId): ?bool
    {
        $gid = str_starts_with($orderId, 'gid://shopify/')
            ? $orderId
            : 'gid://shopify/Order/'.$orderId;
        try {
            $data = $this->shopify->graphql(<<<'GRAPHQL'
query StackOrderFulfillmentStatus($id: ID!) {
  order(id: $id) { displayFulfillmentStatus cancelledAt }
}
GRAPHQL, ['id' => $gid]);
        } catch (\Throwable) {
            return null;
        }

        $status = data_get($data, 'order.displayFulfillmentStatus', data_get($data, 'data.order.displayFulfillmentStatus'));
        if (! is_string($status) || trim($status) === '') {
            return null;
        }

        $normalized = strtoupper(str_replace([' ', '-'], '_', trim($status)));

        return in_array($normalized, ['FULFILLED', 'COMPLETE', 'SHIPPED'], true);
    }
}
