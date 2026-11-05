<?php

use App\Filament\Resources\InventoryResource\Pages\ListInventories;
use App\Jobs\ProcessSupplierReceiptJob;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProcurementSupplierReceipt;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadMutationGuard;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\Procurement\SupplierOrderCsvService;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierOrderSummaryService;
use App\Services\Procurement\SupplierReceiptMutationAuthorization;
use App\Services\Procurement\SupplierReceiptService;
use App\Services\Procurement\SupplierReceivingEligibility;
use App\Services\ProductInventorySyncService;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/InventoryAuthorizationFixture.php';
require_once __DIR__.'/../Support/EngravedBeadFamilyFixture.php';

function procurementFixVariant(string $sku = 'FIX-1', string $status = 'active'): Variant
{
    $actor = inventoryAuthorizedUser();
    $import = Import::create(['filename' => 'procurement-fix', 'mode' => 'append', 'status' => 'ready', 'created_by' => $actor->id]);
    $product = Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/'.$import->id, 'handle' => strtolower($sku), 'title' => $sku, 'status' => $status, 'is_bundle' => false]));

    return Variant::withoutEvents(fn () => Variant::create(['product_id' => $product->id, 'sku' => $sku, 'shopify_id' => 'gid://shopify/ProductVariant/'.$import->id, 'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.$import->id, 'inventory_tracked' => true, 'inventory_qty' => 5, 'current_available_quantity' => 5, 'current_on_hand_quantity' => 5]));
}

function procurementFixUpload(string $contents, string $type = 'order')
{
    $path = tempnam(sys_get_temp_dir(), 'procurement-fix-');
    file_put_contents($path, $contents);
    try {
        return app(SupplierOrderCsvService::class)->preview($path, $type);
    } finally {
        unlink($path);
    }
}

it('requires explicit partial acceptance and preserves every rejected row and original value', function () {
    Bus::fake();
    procurementFixVariant();
    $batch = procurementFixUpload("SKU,Order ID,Quantity Ordered,ETA Date\nFIX-1,PO-FIX,5,10/11/2026\n missing sku ,PO-FIX, bad ,10/11/2026\n");
    expect($batch->valid_count)->toBe(1)->and($batch->invalid_count)->toBe(1)->and(ProcurementSupplierOrderLine::count())->toBe(0);
    expect(fn () => app(SupplierOrderCsvService::class)->confirm($batch->uuid))->toThrow(ValidationException::class);
    app(SupplierOrderCsvService::class)->confirm($batch->uuid, acceptValidRows: true);
    expect(ProcurementSupplierOrderLine::count())->toBe(1);
    $csv = app(SupplierOrderCsvService::class)->rejectedCsv($batch->fresh());
    expect($csv)->toContain('Error Reason', 'Original Row', ' missing sku ', ' bad ')->not->toContain('FIX-1,PO-FIX,5');
});

it('validates combined receipts consistently for upload and paste before staging', function () {
    Bus::fake();
    $variant = procurementFixVariant();
    app(SupplierOrderService::class)->createForVariant($variant, 'PO-CAPACITY', 5, '2026-11-10');
    $upload = procurementFixUpload("Order ID,SKU,Quantity Received\nPO-CAPACITY,FIX-1,3\nPO-CAPACITY,FIX-1,3\n", 'receipt');
    $paste = app(SupplierOrderCsvService::class)->previewPastedReceipt("Order ID\tSKU\tQuantity Received\nPO-CAPACITY\tFIX-1\t3\nPO-CAPACITY\tFIX-1\t3");
    expect($upload->valid_count)->toBe(0)->and($upload->invalid_count)->toBe(2)->and($paste->invalid_count)->toBe(2);
});

it('rejects unmapped draft receiving with an actionable Shopify mapping explanation', function () {
    Bus::fake();
    $draft = NewProductDraft::create(['title' => 'Not published', 'sku' => 'DRAFT-UNMAPPED', 'handle' => 'unmapped', 'status' => 'draft']);
    app(SupplierOrderService::class)->createForDraft($draft, 'PO-DRAFT', 4, '2026-11-10');
    $batch = procurementFixUpload("Order ID,SKU,Quantity Received\nPO-DRAFT,DRAFT-UNMAPPED,2\n", 'receipt');
    expect($batch->invalid_count)->toBe(1)->and(implode(' ', array_merge(...array_values($batch->errors))))->toContain('Shopify', 'inventory');
    Http::assertNothingSent();
});

it('receives an explicitly approved tracked one-bead source with automation flags disabled', function (string $status) {
    Bus::fake();
    config(['google_sheets.enabled' => false, 'engraved_beads.inventory_writes_enabled' => false, 'engraved_beads.store_contract_verified' => false, 'services.shopify.shop' => 'test.myshopify.com', 'services.shopify.admin_access_token' => 'fake', 'services.shopify.inventory_location_id' => 'gid://shopify/Location/6001']);
    $fixture = sharedBeadFamily('receiving-approved-bead-family', 73000, true);
    $variant = $fixture['members'][1]['variant'];
    Product::withoutEvents(fn () => $variant->product->update(['status' => $status]));
    $actor = inventoryAuthorizedUser();
    $line = app(SupplierOrderService::class)->createForVariant($variant->fresh(), 'PO-BEAD', 4, '2026-11-10', $actor->id);
    $receipt = app(SupplierReceiptService::class)->create($line, 2, 'approved-bead-receipt', $actor->id, dispatch: false);
    $mutations = 0;
    Http::fake(function ($request) use ($variant, &$mutations, $status) {
        if (str_contains($request['query'], 'inventoryAdjustQuantities')) {
            $mutations++;

            return Http::response(['data' => ['inventoryAdjustQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toISOString()], 'userErrors' => []]]]);
        }

        return Http::response(['data' => ['product' => ['id' => $variant->product->shopify_id, 'status' => strtoupper($status), 'variants' => ['nodes' => [['id' => $variant->shopify_id, 'sku' => $variant->sku, 'inventoryQuantity' => 22, 'inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => true, 'inventoryLevels' => ['nodes' => [['location' => ['id' => 'gid://shopify/Location/6001'], 'quantities' => [['name' => 'available', 'quantity' => 22], ['name' => 'on_hand', 'quantity' => 22]]]]]]]]]]]]);
    });
    app(SupplierReceiptService::class)->authorizePush($receipt, $actor->id, dispatch: false);
    (new ProcessSupplierReceiptJob($receipt->id))->handle(app(ShopifyInventoryAdjustmentService::class), app(ProductInventorySyncService::class), app(SupplierOrderSummaryService::class), app(ProcurementSheetSyncService::class));
    (new ProcessSupplierReceiptJob($receipt->id))->handle(app(ShopifyInventoryAdjustmentService::class), app(ProductInventorySyncService::class), app(SupplierOrderSummaryService::class), app(ProcurementSheetSyncService::class));
    expect($receipt->fresh()->status)->toBe('succeeded')->and($mutations)->toBe(1)->and(config('engraved_beads.inventory_writes_enabled'))->toBeFalse()->and(config('engraved_beads.store_contract_verified'))->toBeFalse();
})->with(['active', 'unlisted']);

it('shows every rejected row and does not save anything until explicit UI confirmation', function () {
    Bus::fake();
    $actor = inventoryAuthorizedUser();
    $this->actingAs($actor);
    procurementFixVariant();
    $rows = "SKU\tQuantity Ordered\tOrder ID\tETA Date\nFIX-1\t5\tPO-UI\t10/11/2026\n";
    foreach (range(1, 15) as $i) {
        $rows .= "MISSING-{$i}\tbad\tPO-UI\t10/11/2026\n";
    }
    $page = Livewire\Livewire::test(ListInventories::class)->set('activeTab', 'orders')
        ->callAction('pasteSupplierOrder', data: ['pasted_rows' => $rows])->assertSee('Valid rows')->assertSee('Rejected rows')->assertSee('MISSING-15');
    expect(ProcurementSupplierOrderLine::count())->toBe(0);
    $page->setActionData(['accept_valid_rows' => false])->callMountedAction()->assertHasActionErrors(['accept_valid_rows']);
    expect(ProcurementSupplierOrderLine::count())->toBe(0);
    $page->setActionData(['accept_valid_rows' => true])->callMountedAction()->assertNotified('Purchase order created');
    expect(ProcurementSupplierOrderLine::count())->toBe(1);
});

it('downloads rejected originals securely and accepts a corrected re-upload without repeating saved lines', function () {
    Bus::fake();
    $actor = inventoryAuthorizedUser();
    procurementFixVariant();
    procurementFixVariant('FIX-2');
    $batch = procurementFixUpload("SKU,Order ID,Quantity Ordered,ETA Date\nFIX-1,PO-REUPLOAD,3,10/11/2026\nFIX-2,PO-REUPLOAD, bad ,10/11/2026\n");
    app(SupplierOrderCsvService::class)->confirm($batch->uuid, acceptValidRows: true);
    $this->actingAs($actor);
    $response = $this->get(route('inventory.supplier-imports.rejected', $batch))->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain(' bad ', 'Error Reason', 'Original Row')->not->toContain('FIX-1');
    $corrected = procurementFixUpload(str_replace(' bad ', '4', $csv));
    expect($corrected->valid_count)->toBe(1);
    app(SupplierOrderCsvService::class)->confirm($corrected->uuid);
    app(SupplierOrderCsvService::class)->confirm($corrected->uuid);
    expect(ProcurementSupplierOrderLine::count())->toBe(2)->and(ProcurementSupplierOrderLine::sum('quantity_ordered'))->toBe(7);
    $statusOnly = User::factory()->create(['is_active' => true]);
    $this->actingAs($statusOnly);
    $this->get(route('inventory.supplier-imports.rejected', $batch))->assertForbidden();
});

it('identifies an already recorded order SKU while saving unrelated rows without overwriting quantities', function () {
    Bus::fake();
    $one = procurementFixVariant();
    procurementFixVariant('FIX-2');
    app(SupplierOrderService::class)->createForVariant($one, 'PO-EXISTING', 5, '2026-11-10');
    $batch = procurementFixUpload("SKU,Order ID,Quantity Ordered,ETA Date\nFIX-1,PO-EXISTING,99,10/11/2026\nFIX-2,PO-EXISTING,2,10/11/2026\n");
    expect($batch->errors['2'][0])->toContain('PO-EXISTING', 'FIX-1', 'already recorded', 'Amend Order Line');
    app(SupplierOrderCsvService::class)->confirm($batch->uuid, acceptValidRows: true);
    expect(ProcurementSupplierOrderLine::where('sku', 'FIX-1')->value('quantity_ordered'))->toBe(5)->and(ProcurementSupplierOrderLine::count())->toBe(2);
});

it('stages only valid receipt rows and counts staged stock separately from confirmed outstanding', function () {
    Bus::fake();
    $variant = procurementFixVariant();
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-STAGED', 10, '2026-11-10');
    app(SupplierReceiptService::class)->create($line, 3, 'existing-staged', dispatch: false);
    $batch = procurementFixUpload("Order ID,SKU,Quantity Received\nPO-STAGED,FIX-1,4\nMISSING-ORDER,FIX-1,1\n", 'receipt');
    expect($batch->preview_rows[0]['_confirmed_outstanding'])->toBe(10)->and($batch->preview_rows[0]['_staged_quantity'])->toBe(3)->and($batch->preview_rows[0]['_available_to_receive'])->toBe(7);
    app(SupplierOrderCsvService::class)->confirm($batch->uuid, dispatchReceipts: false, acceptValidRows: true);
    expect($line->fresh()->quantity_outstanding)->toBe(10)->and($line->fresh()->quantity_staged)->toBe(7)->and($line->fresh()->quantity_available_to_receive)->toBe(3);
    $over = procurementFixUpload("Order ID,SKU,Quantity Received\nPO-STAGED,FIX-1,2\nPO-STAGED,FIX-1,2\n", 'receipt');
    expect($over->invalid_count)->toBe(2)->and($over->valid_count)->toBe(0);
    $html = view('filament.inventory.supplier-orders', ['variant' => $variant->fresh()->load('supplierOrderLines.receipts')])->render();
    expect($html)->toContain('Waiting to be pushed', 'Staged 7', 'Confirmed outstanding 10');
});

it('revalidates capacity before staging and requires the changed preview to be reviewed again', function () {
    Bus::fake();
    $variant = procurementFixVariant();
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-RACE', 5, '2026-11-10');
    $batch = procurementFixUpload("Order ID,SKU,Quantity Received\nPO-RACE,FIX-1,4\n", 'receipt');
    app(SupplierReceiptService::class)->create($line, 3, 'concurrent-staging', dispatch: false);
    expect(fn () => app(SupplierOrderCsvService::class)->confirm($batch->uuid, acceptValidRows: true))->toThrow(ValidationException::class, 'preview changed');
    expect($line->receipts()->count())->toBe(1)->and($batch->fresh()->invalid_count)->toBe(1)->and($batch->fresh()->status)->toBe('previewed');
});

it('uses identical upload and paste validation and preserves blank-row numbering', function () {
    Bus::fake();
    procurementFixVariant();
    $csv = procurementFixUpload("SKU,Quantity Ordered,Order ID,ETA Date\nFIX-1,3,PO-SAME,10/11/2026\n\nFIX-1,4,PO-SAME,10/11/2026\nBAD,1,PO-SAME,10/11/2026\n");
    $paste = app(SupplierOrderCsvService::class)->previewPastedOrder("SKU\tQuantity Ordered\tOrder ID\tETA Date\nFIX-1\t3\tPO-SAME\t10/11/2026\n\nFIX-1\t4\tPO-SAME\t10/11/2026\nBAD\t1\tPO-SAME\t10/11/2026\n");
    expect($csv->errors)->toBe($paste->errors)->and(array_column($paste->preview_rows, '_row'))->toBe([2, 4, 5]);
});

it('receives a mapped CMS draft without changing its unpublished status', function () {
    Bus::fake();
    config(['google_sheets.enabled' => false, 'services.shopify.shop' => 'test.myshopify.com', 'services.shopify.admin_access_token' => 'fake', 'services.shopify.inventory_location_id' => 'gid://shopify/Location/1']);
    $variant = procurementFixVariant('FIX-DRAFT', 'draft');
    $draft = NewProductDraft::create(['sku' => 'FIX-DRAFT', 'title' => 'Mapped draft', 'handle' => 'fix-draft', 'shopify_id' => $variant->product->shopify_id, 'status' => 'draft']);
    $line = app(SupplierOrderService::class)->createForDraft($draft, 'PO-MAPPED', 3, '2026-11-10');
    $batch = procurementFixUpload("Order ID,SKU,Quantity Received\nPO-MAPPED,FIX-DRAFT,2\n", 'receipt');
    expect($batch->valid_count)->toBe(1)->and($line->fresh()->variant_id)->toBeNull();
    app(SupplierOrderCsvService::class)->confirm($batch->uuid, dispatchReceipts: false);
    $receipt = $line->receipts()->first();
    $actor = inventoryAuthorizedUser();
    app(SupplierReceiptService::class)->authorizePush($receipt, $actor->id, dispatch: false);
    $writes = 0;
    Http::fake(function ($request) use ($variant, &$writes) {
        if (str_contains($request['query'], 'inventoryAdjustQuantities')) {
            $writes++;

            return Http::response(['data' => ['inventoryAdjustQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toISOString()], 'userErrors' => []]]]);
        }

        return Http::response(['data' => ['product' => ['id' => $variant->product->shopify_id, 'status' => 'DRAFT', 'variants' => ['nodes' => [['id' => $variant->shopify_id, 'sku' => $variant->sku, 'inventoryQuantity' => 7, 'inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => true, 'inventoryLevels' => ['nodes' => [['location' => ['id' => 'gid://shopify/Location/1'], 'quantities' => [['name' => 'available', 'quantity' => 7], ['name' => 'on_hand', 'quantity' => 7]]]]]]]]]]]]);
    });
    (new ProcessSupplierReceiptJob($receipt->id))->handle(app(ShopifyInventoryAdjustmentService::class), app(ProductInventorySyncService::class), app(SupplierOrderSummaryService::class), app(ProcurementSheetSyncService::class));
    expect($writes)->toBe(1)->and($receipt->fresh()->status)->toBe('succeeded')->and($variant->product->fresh()->status)->toBe('draft')->and($line->fresh()->variant_id)->toBe($variant->id);
});

it('uses truthful receiving labels for staged, uncertain and post-processing-only failures', function (string $status, string $post, string $label) {
    $receipt = new ProcurementSupplierReceipt(['status' => $status, 'post_process_status' => $post]);
    expect($receipt->receiving_status_label)->toBe($label);
})->with([
    ['pending', 'pending', 'Waiting to be pushed'], ['processing', 'pending', 'Sending to Shopify'],
    ['manual_review', 'pending', 'Needs reconciliation'], ['succeeded', 'completed', 'Received in Shopify'],
    ['succeeded', 'failed', 'Received in Shopify; stock summary needs updating'], ['invalid', 'pending', 'Not saved'],
]);

it('does not permit an untracked multi-bead member to become a receiving source', function (int $beads) {
    Bus::fake();
    config(['engraved_beads.inventory_writes_enabled' => false, 'engraved_beads.store_contract_verified' => false, 'services.shopify.inventory_location_id' => 'gid://shopify/Location/6001']);
    $fixture = sharedBeadFamily('receiving-protected-bead-family', 74000, true);
    $variant = $fixture['members'][$beads]['variant'];
    expect(fn () => app(SupplierOrderService::class)->createForVariant($variant, 'PO-NO-MEMBER', 3, '2026-11-10'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([2, 3, 4]);

it('keeps unapproved families and queued bead receipts without push approval blocked', function () {
    Bus::fake();
    config(['engraved_beads.inventory_writes_enabled' => false, 'engraved_beads.store_contract_verified' => false]);
    $unapproved = sharedBeadFamily('receiving-pending-bead-family', 76000, false);
    expect(fn () => app(SupplierReceivingEligibility::class)->assertVariant($unapproved['members'][1]['variant']))->toThrow(ValidationException::class);
    $approved = sharedBeadFamily('receiving-active-bead-family', 77000, true);
    $line = app(SupplierOrderService::class)->createForVariant($approved['members'][1]['variant'], 'PO-NO-APPROVAL', 2, '2026-11-10');
    $receipt = app(SupplierReceiptService::class)->create($line, 1, 'no-push-approval', dispatch: false);
    expect(fn () => app(SupplierReceiptMutationAuthorization::class)->validate($receipt, 'gid://shopify/Location/6001'))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('limits the bead exception to the exact approved delta and clears it after exceptions', function () {
    Bus::fake();
    config(['engraved_beads.inventory_writes_enabled' => false, 'engraved_beads.store_contract_verified' => false, 'services.shopify.inventory_location_id' => 'gid://shopify/Location/6001']);
    $fixture = sharedBeadFamily('receiving-capability-bead-family', 78000, true);
    $variant = $fixture['members'][1]['variant'];
    $actor = inventoryAuthorizedUser();
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-CAPABILITY', 4, '2026-11-10');
    $receipt = app(SupplierReceiptService::class)->create($line, 2, 'capability-receipt', dispatch: false);
    app(SupplierReceiptService::class)->authorizePush($receipt, $actor->id, dispatch: false);
    $receipt->refresh()->update(['status' => 'processing', 'shopify_adjustment_started_at' => now()]);
    $query = ShopifyInventoryAdjustmentService::SUPPLIER_RECEIPT_MUTATION;
    $variables = ['input' => ['name' => 'available', 'reason' => 'received', 'referenceDocumentUri' => $receipt->shopify_reference_uri, 'changes' => [['inventoryItemId' => $variant->shopify_inventory_item_id, 'locationId' => 'gid://shopify/Location/6001', 'delta' => 2]]], 'idempotencyKey' => hash('sha256', 'inventory-adjust:'.$receipt->shopify_reference_uri)];
    $authorization = app(SupplierReceiptMutationAuthorization::class);
    $guard = app(EngravedBeadMutationGuard::class);
    expect(fn () => $guard->graphql($query, $variables))->toThrow(RuntimeException::class, 'disabled');
    expect(fn () => $authorization->run($receipt, 'gid://shopify/Location/6001', function () use ($authorization, $guard, $query, $variables) {
        expect($authorization->permits($query, $variables))->toBeTrue();
        $guard->graphql($query, $variables);
        foreach (['quantity', 'location', 'item', 'reference', 'key', 'extra mutation'] as $change) {
            $bad = $variables;
            $badQuery = $query;
            match ($change) {
                'quantity' => $bad['input']['changes'][0]['delta'] = 3,
                'location' => $bad['input']['changes'][0]['locationId'] = 'gid://shopify/Location/999',
                'item' => $bad['input']['changes'][0]['inventoryItemId'] = 'gid://shopify/InventoryItem/78002',
                'reference' => $bad['input']['referenceDocumentUri'] = 'another-receipt',
                'key' => $bad['idempotencyKey'] = 'different-key',
                default => $badQuery .= ' mutation Extra { something }',
            };
            expect($authorization->permits($badQuery, $bad))->toBeFalse();
            expect(fn () => $guard->graphql($badQuery, $bad))->toThrow(RuntimeException::class, 'disabled');
        }
        expect(fn () => $guard->rest('POST', 'inventory_levels/adjust.json', ['inventory_item_id' => 78001, 'available_adjustment' => 2]))->toThrow(RuntimeException::class, 'disabled');
        throw new RuntimeException('callback failed');
    }))->toThrow(RuntimeException::class, 'callback failed');
    expect($authorization->permits($query, $variables))->toBeFalse();
    expect(fn () => $guard->graphql($query, $variables))->toThrow(RuntimeException::class, 'disabled');
    Http::assertNothingSent();
});

it('rechecks push authorization and refuses a changed receiving location', function () {
    Bus::fake();
    config(['services.shopify.inventory_location_id' => 'gid://shopify/Location/6001']);
    $fixture = sharedBeadFamily('receiving-revoked-bead-family', 79000, true);
    $actor = inventoryAuthorizedUser();
    $line = app(SupplierOrderService::class)->createForVariant($fixture['members'][1]['variant'], 'PO-REVOKED', 4, '2026-11-10');
    $receipt = app(SupplierReceiptService::class)->create($line, 2, 'revoked-receipt', dispatch: false);
    app(SupplierReceiptService::class)->authorizePush($receipt, $actor->id, dispatch: false);
    $receipt->refresh();
    $authorization = app(SupplierReceiptMutationAuthorization::class);
    expect(fn () => $authorization->validate($receipt, 'gid://shopify/Location/999'))->toThrow(HttpException::class);
    $actor->update(['is_active' => false]);
    expect(fn () => $authorization->validate($receipt, 'gid://shopify/Location/6001'))->toThrow(HttpException::class);
    expect($receipt->fresh()->shopify_adjustment_started_at)->toBeNull();
    Http::assertNothingSent();
});

it('preserves unexpected extra original cells in review and rejected downloads', function () {
    Bus::fake();
    procurementFixVariant();
    $batch = procurementFixUpload("SKU,Order ID,Quantity Ordered,ETA Date\nFIX-1,PO-EXTRA,2,10/11/2026, original extra value \n");
    expect($batch->invalid_count)->toBe(1)->and(app(SupplierOrderCsvService::class)->rejectedCsv($batch))->toContain('Extra column 5', ' original extra value ', 'Error Reason');
    expect(view('filament.inventory.supplier-import-preview', ['batch' => $batch])->render())->toContain('original extra value');
});

it('quarantines uncertain quantities before accepting any more receipts', function () {
    Bus::fake();
    $variant = procurementFixVariant();
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-UNCERTAIN', 10, '2026-11-10');
    $receipt = app(SupplierReceiptService::class)->create($line, 2, 'uncertain-receipt', dispatch: false);
    $receipt->update(['status' => 'manual_review', 'shopify_adjustment_started_at' => now()]);
    $batch = procurementFixUpload("Order ID,SKU,Quantity Received\nPO-UNCERTAIN,FIX-1,1\n", 'receipt');
    expect($batch->valid_count)->toBe(0)->and($batch->preview_rows[0]['_uncertain_quantity'])->toBe(2)->and($batch->preview_rows[0]['_available_to_receive'])->toBe(0)
        ->and(implode(' ', $batch->errors['2']))->toContain('reconciliation');
    expect($line->fresh()->quantity_outstanding)->toBe(10)->and($line->fresh()->quantity_received)->toBe(0);
    Http::assertNothingSent();
});

it('does not use the approved bead exception after Shopify tracking becomes unknown or false', function ($tracked) {
    Bus::fake();
    config(['engraved_beads.inventory_writes_enabled' => false, 'engraved_beads.store_contract_verified' => false,
        'services.shopify.shop' => 'test.myshopify.com', 'services.shopify.admin_access_token' => 'fake', 'services.shopify.inventory_location_id' => 'gid://shopify/Location/6001']);
    $fixture = sharedBeadFamily('receiving-preflight-bead-family', 80000, true);
    $variant = $fixture['members'][1]['variant'];
    $actor = inventoryAuthorizedUser();
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-PREFLIGHT', 4, '2026-11-10');
    $receipt = app(SupplierReceiptService::class)->create($line, 2, 'preflight-receipt', dispatch: false);
    app(SupplierReceiptService::class)->authorizePush($receipt, $actor->id, dispatch: false);
    Http::fake(fn () => Http::response(['data' => ['product' => ['id' => $variant->product->shopify_id, 'status' => 'UNLISTED', 'variants' => ['nodes' => [[
        'id' => $variant->shopify_id, 'sku' => $variant->sku, 'inventoryQuantity' => 20,
        'inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => $tracked, 'inventoryLevels' => ['nodes' => []]],
    ]]]]]]));
    $job = new ProcessSupplierReceiptJob($receipt->id);
    expect(fn () => $job->handle(app(ShopifyInventoryAdjustmentService::class), app(ProductInventorySyncService::class), app(SupplierOrderSummaryService::class), app(ProcurementSheetSyncService::class)))->toThrow(Exception::class);
    Http::assertNotSent(fn ($request) => str_contains($request['query'], 'mutation'));
    expect($receipt->fresh()->status)->toBe('pending')->and($receipt->fresh()->shopify_adjustment_started_at)->toBeNull()->and($receipt->fresh()->shopify_adjusted_at)->toBeNull();
})->with([false, null]);

it('keeps a confirmed receipt received when summary refresh fails and retries without another delta', function () {
    Bus::fake();
    config(['google_sheets.enabled' => false, 'services.shopify.shop' => 'test.myshopify.com', 'services.shopify.admin_access_token' => 'fake', 'services.shopify.inventory_location_id' => 'gid://shopify/Location/1']);
    $variant = procurementFixVariant();
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-SUMMARY-FAIL', 2, '2026-11-10');
    $receipt = app(SupplierReceiptService::class)->create($line, 2, 'summary-fail', dispatch: false);
    $refreshHealthy = false;
    $writes = 0;
    Http::fake(function ($request) use ($variant, &$refreshHealthy, &$writes) {
        if (str_contains($request['query'], 'inventoryAdjustQuantities')) {
            $writes++;

            return Http::response(['data' => ['inventoryAdjustQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toISOString()], 'userErrors' => []]]]);
        }
        if (! $refreshHealthy) {
            return Http::response(['data' => ['product' => null]]);
        }

        return Http::response(['data' => ['product' => ['id' => $variant->product->shopify_id, 'status' => 'ACTIVE', 'variants' => ['nodes' => [[
            'id' => $variant->shopify_id, 'sku' => $variant->sku, 'inventoryQuantity' => 7,
            'inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => true, 'inventoryLevels' => ['nodes' => [['location' => ['id' => 'gid://shopify/Location/1'], 'quantities' => [['name' => 'available', 'quantity' => 7], ['name' => 'on_hand', 'quantity' => 7]]]]]],
        ]]]]]]);
    });
    $inventory = app(ProductInventorySyncService::class);
    $job = new ProcessSupplierReceiptJob($receipt->id);
    expect(fn () => $job->handle(app(ShopifyInventoryAdjustmentService::class), $inventory, app(SupplierOrderSummaryService::class), app(ProcurementSheetSyncService::class)))->toThrow(RuntimeException::class);
    expect($receipt->fresh()->status)->toBe('succeeded')->and($receipt->fresh()->receiving_status_label)->toBe('Received in Shopify; stock summary needs updating')->and($line->fresh()->quantity_received)->toBe(2);
    $refreshHealthy = true;
    $job->handle(app(ShopifyInventoryAdjustmentService::class), $inventory, app(SupplierOrderSummaryService::class), app(ProcurementSheetSyncService::class));
    expect($writes)->toBe(1)->and($receipt->fresh()->receiving_status_label)->toBe('Received in Shopify')->and($receipt->fresh()->inventory_after)->toBe(7);
});
