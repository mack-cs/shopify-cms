<?php

use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\Variant;
use App\Services\Inventory\InventoryAuditContext;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use App\Services\Shopify\StackInventoryMovementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// A separate process against an explicitly named disposable SQLite database only.
$path = $argv[1] ?? '';
$directory = realpath(__DIR__.'/../../storage/framework/testing');
if ($directory === false || dirname($path) !== $directory || ! preg_match('/^bead-race-[a-f0-9-]+\.sqlite$/D', basename($path))) {
    throw new RuntimeException('Refusing any database outside the disposable bead concurrency fixture.');
}
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'bootstrap/cache/config-testing.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path, 'CACHE_STORE' => 'database'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
config(['cache.default' => 'database', 'services.shopify.inventory_location_id' => null,
    'services.shopify.api_version' => '2026-04', 'engraved_beads.enabled' => true,
    'engraved_beads.inventory_writes_enabled' => true, 'engraved_beads.store_contract_verified' => true]);
DB::statement('PRAGMA busy_timeout = 10000');
if (($argv[2] ?? '') === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    require __DIR__.'/EngravedBeadFamilyFixture.php';
    $f = sharedBeadFamily(active: true);
    $ids = [];
    foreach ([4 => 12, 3 => 6] as $q => $quantity) {
        $source = $f['members'][1]['variant'];
        $member = $f['members'][$q]['variant'];
        $record = ShopifyStackInventoryReservation::query()->create([
            'shopify_order_id' => 'gid://shopify/Order/'.$q, 'shopify_order_line_item_id' => 'gid://shopify/LineItem/'.$q,
            'configured_component_product_id' => $source->product_id, 'stack_product_id' => $member->product_id,
            'stack_variant_id' => $member->id, 'component_product_id' => $source->product_id, 'component_variant_id' => $source->id,
            'shopify_inventory_item_id' => $source->shopify_inventory_item_id, 'shopify_location_id' => $f['family']->source_location_id,
            'component_quantity_per_stack' => $q, 'stack_quantity_ordered' => $quantity / $q,
            'total_component_quantity_required' => $quantity, 'reserved_quantity' => 0, 'consumed_quantity' => 0,
            'released_quantity' => 0, 'bead_family_id' => $f['family']->id,
            'ledger_document_uri' => 'leighavenue-cms://engraved-bead-reservations/'.$q,
            'status' => 'pending_processing',
        ]);
        $ids[$q] = $record->id;
    }
    echo json_encode($ids);
    exit;
}
if (($argv[2] ?? '') === 'inspect') {
    echo json_encode(ShopifyStackInventoryReservation::query()->pluck('reserved_quantity', 'id')->all());
    exit;
}
$inventory = new class($path.'.remote.json') extends ShopifyInventoryAdjustmentService
{
    public function __construct(private string $journal) {}

    public function currentQuantities(Variant $variant, ?string $locationId = null): array
    {
        return ['location_id' => $locationId, 'available' => 100, 'on_hand' => 100, 'committed' => 0];
    }

    public function moveQuantity(Variant $variant, int $quantity, string $from, string $to, string $reason,
        string $referenceUri, string $ledgerDocumentUri, string $idempotencyKey, ?string $locationId = null,
        ?InventoryAuditContext $audit = null, ?string $sourceLedgerDocumentUri = null): array
    {
        $file = fopen($this->journal, 'c+');
        flock($file, LOCK_EX);
        $data = json_decode(stream_get_contents($file) ?: '{}', true);
        $data[$idempotencyKey] ??= $quantity; // Simulated Shopify idempotency boundary.
        ftruncate($file, 0);
        rewind($file);
        fwrite($file, json_encode($data));
        fflush($file);
        flock($file, LOCK_UN);
        fclose($file);
        usleep(100000); // Increase overlap while remaining inside the pool lease.

        return ['fake_confirmation' => $idempotencyKey];
    }
};
if (($argv[2] ?? '') === 'fail-local') {
    ShopifyStackInventoryMovement::updating(function ($movement): void {
        if ($movement->status === 'completed') {
            throw new RuntimeException('Simulated local completion failure');
        }
    });
}
$reservation = ShopifyStackInventoryReservation::query()->findOrFail((int) $argv[3]);
try {
    (new StackInventoryMovementService($inventory))->execute(
        $reservation, 'reserve', (int) $reservation->total_component_quantity_required, 'race-event:'.$reservation->id);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage());
    exit(2);
}
echo 'confirmed';
