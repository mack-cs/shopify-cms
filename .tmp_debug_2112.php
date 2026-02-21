<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$p = App\Models\Product::query()->find(2112);
echo "PRODUCT\n";
var_export($p?->only(['id','import_id','handle','type','title']));
echo "\n\nPRIMARY ROW DATA (selected)\n";
$row = App\Models\ShopifyRow::query()
    ->where('import_id', $p?->import_id)
    ->where('handle', $p?->handle)
    ->where('row_type', 'product_primary')
    ->first();
$data = $row?->data ?? [];
$keys = [
    'Product category',
    'Product category (product.category)',
    'Jewelry material (product.metafields.shopify.jewelry-material)',
    'Age group (product.metafields.shopify.age-group)',
    'Jewelry type (product.metafields.shopify.jewelry-type)',
    'Target gender (product.metafields.shopify.target-gender)',
    'Bracelet design (product.metafields.shopify.bracelet-design)',
    'Materials and Dimensions (product.metafields.custom.materials_and_dimensions)',
];
foreach ($keys as $k) {
    if (array_key_exists($k, $data)) {
        echo $k . " => " . (is_scalar($data[$k]) ? (string) $data[$k] : json_encode($data[$k])) . "\n";
    }
}

echo "\nMETAFIELD DEFINITIONS SNAPSHOT\n";
$rows = App\Models\ShopifyMetafield::query()
    ->where('import_id', $p?->import_id)
    ->whereIn('key', ['age-group','jewelry-type','target-gender','bracelet-design','jewelry-material','materials_and_dimensions'])
    ->get(['namespace','key','type','value']);
foreach ($rows as $r) {
    echo $r->namespace . '.' . $r->key . ' | ' . $r->type . ' | ' . $r->value . "\n";
}
