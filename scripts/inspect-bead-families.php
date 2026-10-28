<?php

// Read-only catalog evidence; never invokes Shopify or writes application data.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$products = App\Models\Product::query()->where('tags', 'like', '%bead-family%')->with('variants')->get();
if (in_array('--remote', $argv, true)) {
    $ids = $products->flatMap(fn ($p) => $p->variants->pluck('shopify_id'))->filter()->unique()->values()->all();
    $gateway = $app->make(App\Contracts\ShopifyGraphqlGateway::class);
    $data = $gateway->graphql(<<<'GQL'
query InspectEngravedBeadStore($ids: [ID!]!) {
  shop { plan { displayName shopifyPlus } }
  nodes(ids: $ids) {
    ... on ProductVariant {
      id sku availableForSale inventoryPolicy
      product { id status tags }
      inventoryItem {
        id tracked
        inventoryLevels(first: 50) {
          pageInfo { hasNextPage }
          nodes { location { id name isActive fulfillsOnlineOrders } quantities(names: ["on_hand", "available", "committed", "reserved", "safety_stock"]) { name quantity } }
        }
      }
    }
  }
}
GQL, ['ids' => $ids]);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit;
}
$rows = $products->map(fn ($p) => [
    'product' => $p->shopify_id, 'tags' => $p->tags,
    'variants' => $p->variants->map(fn ($v) => [
        'sku' => $v->sku, 'variant' => $v->shopify_id,
        'inventory_item' => $v->shopify_inventory_item_id,
        'tracked' => $v->inventory_tracked, 'locations' => $v->inventory_location_count,
    ]),
]);
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
