<?php

namespace App\Services\Shopify;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\ShopifyOrder;
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

        $local = $this->localStatus($orderId);
        if ($local === true) {
            return $this->cache[$orderId] = true;
        }

        $live = $this->liveStatus($orderId);
        if ($live !== null) {
            return $this->cache[$orderId] = $live;
        }

        return $this->cache[$orderId] = false;
    }

    private function localStatus(string $orderId): ?bool
    {
        $order = ShopifyOrder::query()
            ->whereIn('shopify_order_id', $this->idAliases($orderId))
            ->latest('id')
            ->first();
        if (! $order) {
            return null;
        }

        return $this->isFulfilledStatus($order->fulfillment_status);
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

        return $this->isFulfilledStatus($status);
    }

    private function isFulfilledStatus(mixed $status): bool
    {
        $normalized = strtoupper(str_replace([' ', '-'], '_', trim((string) $status)));

        return in_array($normalized, ['FULFILLED', 'COMPLETE', 'SHIPPED'], true);
    }

    /** @return array<int, string> */
    private function idAliases(string $orderId): array
    {
        $aliases = [$orderId];
        if (preg_match('#gid://shopify/Order/(\d+)$#', $orderId, $match) === 1) {
            $aliases[] = $match[1];
        } elseif (ctype_digit($orderId)) {
            $aliases[] = 'gid://shopify/Order/'.$orderId;
        }

        return array_values(array_unique($aliases));
    }
}
