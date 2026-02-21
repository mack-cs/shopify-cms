<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$client = app(App\Services\ShopifyApiClient::class);

$queries = [
  'ProductInput' => 'query T { __type(name: "ProductInput") { name inputFields { name type { kind name ofType { kind name ofType { kind name } } } } } }',
  'Mutation' => 'query T { __type(name: "Mutation") { name fields { name args { name type { kind name ofType { kind name ofType { kind name } } } } } } }',
];

foreach ($queries as $label => $query) {
  echo "=== {$label} ===\n";
  try {
    $data = $client->graphql($query, []);
    if ($label === 'ProductInput') {
      $fields = data_get($data, '__type.inputFields', []);
      foreach ($fields as $f) {
        echo ($f['name'] ?? '') . "\n";
      }
    } else {
      $fields = data_get($data, '__type.fields', []);
      foreach ($fields as $f) {
        if (($f['name'] ?? '') === 'productUpdate' || ($f['name'] ?? '') === 'productSet') {
          echo ($f['name'] ?? '') . " args: ";
          $args = array_map(fn($a) => $a['name'] ?? '', $f['args'] ?? []);
          echo implode(',', $args) . "\n";
        }
      }
    }
  } catch (Throwable $e) {
    echo 'ERR: ' . $e->getMessage() . "\n";
  }
}
