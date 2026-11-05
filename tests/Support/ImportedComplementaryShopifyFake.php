<?php

/** Explicit remote fixture for tests originally written before live complementary reads. */
function fakeImportedComplementaryShopify(): void
{
    \Illuminate\Support\Facades\Http::fake(function ($request) {
        $query = $request['query'] ?? '';
        $variables = $request['variables'] ?? [];
        $node = function (string $gid): ?array {
            $product = \App\Models\Product::where('shopify_id', $gid)->first();
            if (! $product) {
                return null;
            }
            return ['id' => $gid, 'handle' => $product->handle, 'title' => $product->title,
                'status' => strtoupper($product->status), 'variants' => ['nodes' => $product->variants->map(fn ($v) => [
                    'availableForSale' => $v->inventory_tracked === false || (int) $v->inventory_qty > 0,
                ])->all()]];
        };
        if (str_contains($query, 'query ComplementaryProductStates')) {
            return \Illuminate\Support\Facades\Http::response(['data' => ['nodes' => array_map($node, $variables['ids'])]]);
        }
        if (str_contains($query, 'query ComplementaryProductAudit')) {
            $product = \App\Models\Product::where('shopify_id', $variables['id'])->firstOrFail();
            $result = [];
            foreach (['standardComplementary' => 'shopify--discovery--product_recommendation', 'appComplementary' => '$app'] as $alias => $namespace) {
                $value = \App\Models\ShopifyMetafield::where('import_id', $product->import_id)->where('handle', $product->handle)
                    ->where('namespace', $namespace)->where('key', 'complementary_products')->value('value');
                $ids = json_decode((string) $value, true);
                $ids = is_array($ids) ? $ids : preg_split('/[;,]\s*/', (string) $value, flags: PREG_SPLIT_NO_EMPTY);
                $result[$alias] = ['references' => ['nodes' => array_values(array_filter(array_map($node, $ids)))]];
            }
            return \Illuminate\Support\Facades\Http::response(['data' => ['product' => $result]]);
        }
        throw new RuntimeException('Unexpected HTTP operation in complementary fixture.');
    });
}
