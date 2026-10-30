<?php

namespace App\Services\EngravedBeads;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackInventoryReservation;
use App\Services\Shopify\StackFulfillmentInventoryService;

final class EngravedBeadFulfillmentReconciliation
{
    public function __construct(private readonly ShopifyGraphqlGateway $gateway, private readonly StackFulfillmentInventoryService $fulfillments) {}

    public function reconcile(string $orderId): void
    {
        app(EngravedBeadWriteGuard::class)->check();
        $records = ShopifyStackInventoryReservation::query()->where('shopify_order_id', $orderId)->whereNotNull('bead_family_id')->get();
        if ($records->isEmpty()) {
            return;
        }
        $data = $this->gateway->graphql(<<<'GQL'
query BeadOrderFulfillments($id: ID!) {
  order(id: $id) {
    id fulfillments {
      id status createdAt location { id }
      fulfillmentLineItems(first: 250) {
        nodes { quantity lineItem { id sku variant { id } } }
        pageInfo { hasNextPage endCursor }
      }
    }
  }
}
GQL, ['id' => $orderId]);
        if (data_get($data, 'order.id') !== $orderId || ! is_array(data_get($data, 'order.fulfillments'))) {
            throw new \RuntimeException('The complete live fulfillment snapshot could not be verified.');
        }
        foreach ($data['order']['fulfillments'] as $remote) {
            $nodes = data_get($remote, 'fulfillmentLineItems.nodes', []);
            $page = data_get($remote, 'fulfillmentLineItems.pageInfo');
            $seen = [];
            while (($page['hasNextPage'] ?? null) === true) {
                $cursor = $page['endCursor'] ?? null;
                if (! is_string($cursor) || isset($seen[$cursor])) {
                    throw new \RuntimeException('Invalid fulfillment pagination.');
                }
                $seen[$cursor] = true;
                $more = $this->gateway->graphql(<<<'GQL'
query BeadFulfillmentLines($id: ID!, $after: String!) {
  node(id: $id) { ... on Fulfillment { fulfillmentLineItems(first: 250, after: $after) {
    nodes { quantity lineItem { id sku variant { id } } } pageInfo { hasNextPage endCursor }
  } } }
}
GQL, ['id' => $remote['id'], 'after' => $cursor]);
                $connection = data_get($more, 'node.fulfillmentLineItems');
                if (! is_array($connection)) {
                    throw new \RuntimeException('Missing fulfillment page.');
                }
                array_push($nodes, ...$connection['nodes']);
                $page = $connection['pageInfo'];
            }
            if (($page['hasNextPage'] ?? null) !== false) {
                throw new \RuntimeException('Incomplete fulfillment pagination.');
            }
            $status = strtolower($remote['status']);
            $id = $remote['id'];
            $legacyId = substr($id, strrpos($id, '/') + 1);
            $fulfillment = ShopifyFulfillment::query()->whereIn('shopify_fulfillment_id', [$id, $legacyId])->first();
            if ($fulfillment?->processing_status === 'completed' && $fulfillment->shopify_status === $status) {
                continue;
            }
            $fulfillment ??= new ShopifyFulfillment(['shopify_fulfillment_id' => $id]);
            $fulfillment->fill(['shopify_order_id' => $orderId, 'shopify_location_id' => data_get($remote, 'location.id'),
                'shopify_status' => $status, 'fulfilled_at_shopify' => $remote['createdAt'], 'processing_status' => 'pending',
                'payload' => ['line_items' => array_map(fn ($node) => [
                    'admin_graphql_api_id' => $node['lineItem']['id'], 'variant_id' => data_get($node, 'lineItem.variant.id'),
                    'sku' => data_get($node, 'lineItem.sku'), 'quantity' => $node['quantity'],
                ], $nodes)]])->save();
            $this->fulfillments->process($fulfillment);
        }
    }
}
