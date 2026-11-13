<?php

use App\Filament\Resources\InventoryResource\Pages\ListInventories;
use App\Jobs\InventorySyncJob;
use App\Models\Import;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryAdjustmentRequestItem;
use App\Models\InventoryWriteIntent;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\ProductInventoryCsvImporter;
use App\Services\ProductInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/InventoryAuthorizationFixture.php';

beforeEach(function () {
    config(['inventory_monitor.enabled' => false, 'services.shopify.shop' => 'test-shop.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token']);
    Storage::fake('local');
    Bus::fake();
});

it('uploads one file with one reason and one approver without staging local counts or pushing Shopify', function () {
    $requester = inventoryAuthorizedUser();
    $approver = inventoryAuthorizedUser();
    $first = fileApprovalVariant(8001, 'FILE-A', 4);
    $second = fileApprovalVariant(8002, 'FILE-B', 6);
    Storage::disk('local')->put('imports/inventory/counts.csv', "SKU,On Hand\nFILE-A,0\nFILE-B,9\n");
    $this->actingAs($requester);
    Livewire::test(ListInventories::class)
        ->set('activeTab', 'everyday')
        ->callAction('importStockCsv', ['file' => ['imports/inventory/counts.csv'], 'reason' => 'November stocktake', 'approver_id' => $approver->id])
        ->assertHasNoActionErrors();

    $request = InventoryAdjustmentRequest::with('items')->sole();
    expect($request->status)->toBe('PENDING_APPROVAL')->and($request->type)->toBe('file')
        ->and($request->approver_id)->toBe($approver->id)->and($request->items->pluck('reason')->all())->toBe(['November stocktake', 'November stocktake'])
        ->and($request->items->pluck('requested_on_hand_quantity')->all())->toBe([0, 9])
        ->and($request->items->pluck('original_on_hand_quantity')->all())->toBe([4, 6])
        ->and($first->fresh()->current_on_hand_quantity)->toBe(4)->and($second->fresh()->current_on_hand_quantity)->toBe(6)
        ->and($first->fresh()->inventory_local_dirty)->toBeFalse();
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

it('uses one approval job to apply every file count and marks Applied only after confirmation', function () {
    $requester = inventoryAuthorizedUser();
    $approver = inventoryAuthorizedUser();
    $variants = collect([fileApprovalVariant(8001, 'FILE-A', 4), fileApprovalVariant(8002, 'FILE-B', 6)]);
    $request = fileApprovalRequest("SKU,On Hand\nFILE-A,0\nFILE-B,9\n", $requester, $approver, 'Stocktake');
    Bus::assertNothingDispatched();
    $stocks = [8001 => 4, 8002 => 6];
    $writes = [];
    Http::fake(function ($request) use (&$stocks, &$writes, $variants) {
        $query = $request['query'];
        $variables = $request['variables'];
        if (str_contains($query, 'query ProductInventoryById')) {
            $id = (int) basename($variables['id']);
            $variant = $variants->first(fn ($v) => (int) basename($v->shopify_id) === $id);

            return Http::response(['data' => ['product' => ['id' => 'gid://shopify/Product/'.$id, 'status' => 'ACTIVE',
                'variants' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [['id' => $variant->shopify_id, 'sku' => $variant->sku,
                    'inventoryPolicy' => 'DENY', 'availableForSale' => $stocks[$id] > 0, 'inventoryQuantity' => $stocks[$id],
                    'inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => true,
                        'inventoryLevels' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [[
                            'location' => ['id' => 'gid://shopify/Location/1'], 'quantities' => [
                                ['name' => 'available', 'quantity' => $stocks[$id]], ['name' => 'committed', 'quantity' => 0], ['name' => 'on_hand', 'quantity' => $stocks[$id]],
                            ],
                        ]]],
                    ]],
                ]],
            ]]]);
        }
        if (str_contains($query, 'query Locations')) {
            return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]]);
        }
        if (str_contains($query, 'productVariantsBulkUpdate')) {
            return Http::response(['data' => ['productVariantsBulkUpdate' => ['userErrors' => []]]]);
        }
        if (str_contains($query, 'mutation InventorySetQuantities')) {
            $change = $variables['input']['quantities'][0];
            $id = (int) basename($change['inventoryItemId']);
            expect($change['changeFromQuantity'])->toBe($stocks[$id]);
            $stocks[$id] = $change['quantity'];
            $writes[] = $change;

            return Http::response(['data' => ['inventorySetQuantities' => [
                'inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()], 'userErrors' => [],
            ]]]);
        }
        throw new RuntimeException('Unexpected Shopify call in file approval test.');
    });

    app(InventoryAdjustmentApprovalService::class)->approve($request, $approver->id);
    $job = null;
    Bus::assertDispatched(InventorySyncJob::class, function ($queued) use (&$job, $variants, $request) {
        $job = $queued;

        return $queued->mode === 'push' && $queued->variantIds === $variants->pluck('id')->all()
            && str_starts_with($queued->syncBatchId, 'inventory_approval_'.$request->id.'_');
    });
    Bus::assertDispatchedTimes(InventorySyncJob::class, 1);
    expect($request->fresh()->status)->toBe('APPROVED');
    $job->handle(app(ProductInventorySyncService::class), app(InventoryAdjustmentApprovalService::class));
    expect($request->fresh()->status)->toBe('APPLIED')
        ->and($stocks)->toBe([8001 => 0, 8002 => 9])->and(count($writes))->toBe(2)
        ->and($variants[0]->fresh()->current_on_hand_quantity)->toBe(0)
        ->and($variants[1]->fresh()->current_on_hand_quantity)->toBe(9);
    $job->handle(app(ProductInventorySyncService::class), app(InventoryAdjustmentApprovalService::class));
    expect(count($writes))->toBe(2);
});

it('requires one file reason and an independent approver in the upload form', function () {
    $requester = inventoryAuthorizedUser();
    fileApprovalVariant(8001, 'FILE-A', 4);
    Storage::disk('local')->put('imports/inventory/counts.csv', "SKU,On Hand\nFILE-A,0\n");
    $this->actingAs($requester);
    Livewire::test(ListInventories::class)->set('activeTab', 'everyday')
        ->callAction('importStockCsv', ['file' => ['imports/inventory/counts.csv']])
        ->assertHasActionErrors(['reason' => 'required', 'approver_id' => 'required']);
    expect(InventoryAdjustmentRequest::count())->toBe(0);
    expect(fn () => fileApprovalRequest("SKU,On Hand\nFILE-A,0\n", $requester, $requester, 'Stocktake'))
        ->toThrow(ValidationException::class, 'cannot approve their own');
});

it('rejects the whole file for invalid rows without changing counts or creating partial approvals', function (string $row) {
    $requester = inventoryAuthorizedUser();
    $approver = inventoryAuthorizedUser();
    $variant = fileApprovalVariant(8001, 'FILE-A', 4);
    fileApprovalVariant(8002, 'FILE-B', 6);
    expect(fn () => fileApprovalRequest("SKU,On Hand,Tracked\nFILE-A,0,true\n{$row}\n", $requester, $approver, 'Stocktake'))
        ->toThrow(ValidationException::class);
    expect(InventoryAdjustmentRequest::count())->toBe(0)->and(InventoryAdjustmentRequestItem::count())->toBe(0)
        ->and($variant->fresh()->current_on_hand_quantity)->toBe(4)->and($variant->fresh()->inventory_local_dirty)->toBeFalse();
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
})->with(['FILE-B,-1,true', 'FILE-B,1.5,true', 'FILE-B,2,maybe', 'MISSING,2,true', 'FILE-A,2,true', 'FILE-B,,true']);

it('uses the shared file reason even if the file contains a reason column', function () {
    $requester = inventoryAuthorizedUser();
    $approver = inventoryAuthorizedUser();
    fileApprovalVariant(8001, 'FILE-A', 4);
    $request = fileApprovalRequest("SKU,Stock,Reason\nFILE-A,0,old row reason\n", $requester, $approver, 'One file reason');
    expect($request->items->sole()->reason)->toBe('One file reason')->and($request->items->sole()->requested_on_hand_quantity)->toBe(0)
        ->and($request->original_filename)->toBe('counts.csv');
    $html = view('filament.inventory.approval-request', ['request' => $request])->render();
    expect(substr_count($html, 'One file reason'))->toBe(1)->and($html)->toContain('Reason for this file:');
});

it('shows invalid CSV rows on the upload form without creating a partial request', function () {
    $requester = inventoryAuthorizedUser();
    $approver = inventoryAuthorizedUser();
    fileApprovalVariant(8001, 'FILE-A', 4);
    Storage::disk('local')->put('imports/inventory/bad.csv', "SKU,On Hand\nFILE-A,0\nMISSING,9\n");
    $this->actingAs($requester);
    Livewire::test(ListInventories::class)->set('activeTab', 'everyday')
        ->callAction('importStockCsv', ['file' => ['imports/inventory/bad.csv'], 'reason' => 'Stocktake', 'approver_id' => $approver->id])
        ->assertHasActionErrors(['file']);
    expect(InventoryAdjustmentRequest::count())->toBe(0);
});

it('keeps the original uploaded filename on the file approval', function () {
    $requester = inventoryAuthorizedUser();
    $approver = inventoryAuthorizedUser();
    fileApprovalVariant(8001, 'FILE-A', 4);
    $file = UploadedFile::fake()->createWithContent('November physical counts.csv', "SKU,On Hand\nFILE-A,0\n");
    $this->actingAs($requester);
    Livewire::test(ListInventories::class)->set('activeTab', 'everyday')->mountAction('importStockCsv')
        ->set('mountedActionsData.0.file', [$file])
        ->set('mountedActionsData.0.reason', 'November stocktake')
        ->set('mountedActionsData.0.approver_id', $approver->id)
        ->callMountedAction()->assertHasNoActionErrors();
    expect(InventoryAdjustmentRequest::sole()->original_filename)->toBe('November physical counts.csv');
});

it('rejects empty files, duplicate variant aliases and conflicting identifiers', function () {
    $requester = inventoryAuthorizedUser();
    $approver = inventoryAuthorizedUser();
    $variant = fileApprovalVariant(8001, 'FILE-A', 4);
    foreach (["SKU,On Hand\n", "SKU,On Hand,variant_id\nFILE-A,0,\n,2,{$variant->id}\n", "SKU,On Hand,variant_id\nWRONG,0,{$variant->id}\n"] as $csv) {
        expect(fn () => fileApprovalRequest($csv, $requester, $approver, 'Stocktake'))->toThrow(ValidationException::class);
    }
    expect(InventoryAdjustmentRequest::count())->toBe(0);
});

it('rejects a file request without inventory permission and leaves rejected file counts untouched', function () {
    $requester = inventoryAuthorizedUser();
    $approver = inventoryAuthorizedUser();
    $variant = fileApprovalVariant(8001, 'FILE-A', 4);
    $unauthorized = User::factory()->create(['is_active' => true]);
    expect(fn () => fileApprovalRequest("SKU,On Hand\nFILE-A,0\n", $unauthorized, $approver, 'Stocktake'))
        ->toThrow(HttpException::class);
    $request = fileApprovalRequest("SKU,On Hand\nFILE-A,0\n", $requester, $approver, 'Stocktake');
    app(InventoryAdjustmentApprovalService::class)->reject($request, $approver->id, 'Count needs review');
    expect($request->fresh()->status)->toBe('REJECTED')->and($variant->fresh()->current_on_hand_quantity)->toBe(4);
    Bus::assertNothingDispatched();
});

it('retains the existing uncertain-write block when an approved file includes an unresolved SKU', function () {
    $requester = inventoryAuthorizedUser();
    $approver = inventoryAuthorizedUser();
    $variant = fileApprovalVariant(8001, 'FILE-A', 4);
    InventoryWriteIntent::create(['variant_id' => $variant->id, 'intent_key' => hash('sha256', 'uncertain-file-test'),
        'payload' => ['target' => 7], 'status' => 'started']);
    $request = fileApprovalRequest("SKU,On Hand\nFILE-A,0\n", $requester, $approver, 'Stocktake');
    $request = app(InventoryAdjustmentApprovalService::class)->approve($request, $approver->id, false);
    (new InventorySyncJob([$variant->id], 'push', $approver->id, $request->sync_batch_id))
        ->handle(app(ProductInventorySyncService::class), app(InventoryAdjustmentApprovalService::class));
    expect($request->fresh()->status)->not->toBe('APPLIED')->and($variant->fresh()->current_on_hand_quantity)->toBe(4);
    Http::assertNothingSent();
});

function fileApprovalRequest(string $contents, User $requester, User $approver, string $reason): InventoryAdjustmentRequest
{
    Storage::disk('local')->put('counts.csv', $contents);

    return app(ProductInventoryCsvImporter::class)->createApprovalFromPath(Storage::disk('local')->path('counts.csv'),
        $requester->id, $approver->id, 'counts.csv', $reason);
}

function fileApprovalVariant(int $id, string $sku, int $onHand): Variant
{
    $import = Import::create(['filename' => 'file-approval.csv', 'mode' => 'append', 'status' => 'ready', 'created_by' => inventoryAuthorizedUser()->id]);
    $product = Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/'.$id,
        'handle' => strtolower($sku), 'title' => $sku, 'status' => 'active']));

    return Variant::withoutEvents(fn () => Variant::create(['product_id' => $product->id, 'shopify_id' => 'gid://shopify/ProductVariant/'.$id,
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.$id, 'sku' => $sku, 'inventory_tracked' => true,
        'current_on_hand_quantity' => $onHand, 'current_available_quantity' => $onHand, 'inventory_qty' => $onHand,
        'inventory_local_dirty' => false, 'inventory_policy' => 'deny']));
}
