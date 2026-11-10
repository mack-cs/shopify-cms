<?php

use App\Enums\RolesEnum;
use App\Jobs\ImportShopifyProductImagesJob;
use App\Jobs\RebuildShopifyStackImagesJob;
use App\Jobs\SendProductPartialApprovalSlackReminderJob;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\ShopifyImageImportBatch;
use App\Models\ShopifyImageImportItem;
use App\Models\User;
use App\Services\ImageAltTextBackfillService;
use App\Services\ProductPartialApprovalService;
use App\Services\ProductShopifyUpdater;
use App\Services\ShopifyImageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Support/shopify-image-import.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('shopify_product_images');
    Storage::fake('public');
    config()->set('shopify_image_import.disk', 'shopify_product_images');
    Http::preventStrayRequests();
});

it('imports images without a csv and leaves alt text untouched', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'front-photo');
    $product = createImageImportProduct('LA0550');

    [$batch, $result] = runAltTextImport('incoming/2026-11-10');

    $image = $product->images()->first();

    expect($result['total_files'])->toBe(1)
        ->and($result['updated_count'])->toBe(1)
        ->and($result['alt_text_csv_found'])->toBeFalse()
        ->and($result['alt_text_applied'])->toBe(0)
        ->and($batch->alt_text_csv_found)->toBeFalse()
        ->and($image)->not->toBeNull()
        ->and($image->alt_text)->toBeNull()
        ->and($image->alt_text_status)->toBeNull()
        ->and($image->approved_filename)->toBe('la0550-1.jpg')
        ->and(ShopifyImageImportBatch::formatImportResult($result))->toBe('Images imported or updated: 1. Failed: 0. Files: 1.');
});

it('imports quoted utf-8 alt text onto the original filename and leaves it pending', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/la0550.JPG', 'front-photo');
    $product = createImageImportProduct('LA0550');
    $product->forceFill(['approval_version' => 1])->save();
    $requester = User::factory()->create();
    $priceRequest = ProductPartialApprovalRequest::create([
        'product_id' => $product->id,
        'approval_version' => 1,
        'requested_by' => $requester->id,
        'status' => ProductPartialApprovalRequest::STATUS_PENDING,
        'scopes' => [ProductShopifyUpdater::SYNC_SCOPE_PRICE, ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT],
        'core_fields' => ['variant_price'],
    ]);
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "\xEF\xBB\xBFfilename,alt_text\r\nLA0550.jpg,\"Elegant gold necklace, with café detailing\"\r\n");

    [$batch] = runAltTextImport('incoming/2026-11-10');
    $image = $product->images()->first();

    expect($batch->alt_text_applied)->toBe(1)
        ->and($batch->alt_text_missing)->toBe(0)
        ->and($batch->alt_text_error_count)->toBe(0)
        ->and($image->alt_text)->toBe('Elegant gold necklace, with café detailing')
        ->and($image->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($image->imported_alt_text)->toBe('Elegant gold necklace, with café detailing')
        ->and($image->approved_filename)->toBe('la0550-1.jpg')
        ->and($image->imageAsset?->original_filename)->toBe('la0550.JPG')
        ->and(app(ProductPartialApprovalService::class)->approvedAlt($image))->toBeNull()
        ->and(app(ImageAltTextBackfillService::class)->approvedUploadAlt($image))->toBeNull()
        ->and($priceRequest->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_PENDING)
        ->and($priceRequest->fresh()->scopes)->toBe([ProductShopifyUpdater::SYNC_SCOPE_PRICE])
        ->and(ShopifyImageImportItem::query()->where('batch_id', $batch->id)->value('message'))->not->toContain('Alt text')
        ->and(ShopifyImageImportItem::query()->where('batch_id', $batch->id)->value('alt_message'))->toBe('Alt text saved for review.')
        ->and($batch->resultSummary())->toContain('Images imported or updated: 1')
        ->and($batch->resultSummary())->toContain('Alt text applied: 1. Alt text missing: 0. Alt-text errors: 0.');

    expect(app(ImageAltTextBackfillService::class)->publishIds([$image->id])['published'])->toBe(0)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);

    approveImportedImageAlt($image->fresh());

    expect($image->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED);
});

it('applies a partial csv without erasing an image that has no new text', function (): void {
    $front = syncedImportImage('LA0550', 'front-photo', 'Existing front', Image::ALT_APPROVED);
    $side = syncedImportImage('LA0550', 'side-photo', 'Existing side', Image::ALT_APPROVED, 2);
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'front-photo');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550-2.jpg', 'side-photo');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nLA0550.jpg,Updated front\nLA0550-2.jpg,\n");

    [$batch] = runAltTextImport('incoming/2026-11-10');

    expect($batch->alt_text_applied)->toBe(1)
        ->and($batch->alt_text_missing)->toBe(1)
        ->and($batch->alt_text_error_count)->toBe(0)
        ->and($front->fresh()->alt_text)->toBe('Updated front')
        ->and($front->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($side->fresh()->alt_text)->toBe('Existing side')
        ->and($side->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($side->fresh()->shopify_alt_text)->toBe('Existing side');
});

it('does not reset approval when the same image and alt text are imported again', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'front-photo');
    $product = createImageImportProduct('LA0550');
    $product->forceFill(['approval_version' => 1])->save();
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nLA0550.jpg,Front view\n");

    runAltTextImport('incoming/2026-11-10');
    $image = $product->images()->first();
    expect($image->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);

    [$second] = runAltTextImport('incoming/2026-11-10');

    expect($second->alt_text_applied)->toBe(0)
        ->and($second->alt_text_unchanged)->toBe(1)
        ->and($second->alt_text_error_count)->toBe(0)
        ->and($image->fresh()->alt_text)->toBe('Front view')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);

    approveImportedImageAlt($image->fresh());
    [$third] = runAltTextImport('incoming/2026-11-10');

    expect($third->alt_text_unchanged)->toBe(1)
        ->and($third->alt_text_applied)->toBe(0)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($image->fresh()->alt_text)->toBe('Front view');
});

it('replaces a photograph and stores the new alt text for review without the previous shopify alt', function (): void {
    $image = syncedImportImage('LA0550', 'old-photo', 'Old description', Image::ALT_PUBLISHED);
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'new-photo');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nLA0550.jpg,New description\n");

    runAltTextImport('incoming/2026-11-10');

    expect($image->fresh()->alt_text)->toBe('New description')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($image->fresh()->shopify_alt_text)->toBeNull()
        ->and($image->fresh()->imported_alt_text)->toBe('New description')
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/555')
        ->and(app(ImageAltTextBackfillService::class)->approvedUploadAlt($image->fresh()))->toBeNull();
});

it('clears the previous alt text when a replacement has no csv description', function (): void {
    $image = syncedImportImage('LA0550', 'old-photo', 'Old description', Image::ALT_PUBLISHED);
    $image->forceFill(['imported_alt_text' => 'Old description'])->save();
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'new-photo');

    [$batch] = runAltTextImport('incoming/2026-11-10');

    expect($batch->alt_text_csv_found)->toBeFalse()
        ->and($image->fresh()->alt_text)->toBeNull()
        ->and($image->fresh()->alt_text_status)->toBeNull()
        ->and($image->fresh()->shopify_alt_text)->toBeNull()
        ->and($image->fresh()->imported_alt_text)->toBeNull()
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/555')
        ->and(app(ImageAltTextBackfillService::class)->approvedUploadAlt($image->fresh()))->toBeNull();
});

it('reports invalid, duplicate, and overlong alt text without corrupting descriptions', function (): void {
    $image = syncedImportImage('LA0550', 'front-photo', 'Keep me', Image::ALT_APPROVED);
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'front-photo');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "image_id,alt_text\n1,Should not apply\n");

    [$invalid] = runAltTextImport('incoming/2026-11-10');

    expect($invalid->alt_text_error_count)->toBe(1)
        ->and($invalid->alt_text_applied)->toBe(0)
        ->and($invalid->altTextReportText())->toContain('filename and alt_text')
        ->and($image->fresh()->alt_text)->toBe('Keep me')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED);

    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nLA0550.jpg,First\nla0550.jpg,Second\n");
    [$duplicates] = runAltTextImport('incoming/2026-11-10');

    expect($duplicates->alt_text_error_count)->toBe(2)
        ->and($duplicates->alt_text_applied)->toBe(0)
        ->and($duplicates->altTextReportText())->toContain('duplicates filename')
        ->and($image->fresh()->alt_text)->toBe('Keep me')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED);

    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', 'filename,alt_text'."\n".'LA0550.jpg,'.str_repeat('a', 256)."\n");
    [$overlong] = runAltTextImport('incoming/2026-11-10');

    expect($overlong->alt_text_error_count)->toBe(1)
        ->and($overlong->altTextReportText())->toContain('255')
        ->and($image->fresh()->alt_text)->toBe('Keep me')
        ->and(mb_strlen((string) $image->fresh()->alt_text))->toBe(7);
});

it('does not apply csv rows to images that were not imported', function (): void {
    $imported = syncedImportImage('LA0550', 'front-photo', 'Old front', Image::ALT_APPROVED);
    $unrelated = syncedImportImage('OTHER1', 'other-photo', 'Leave me', Image::ALT_APPROVED);
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'front-photo');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nLA0550.jpg,Imported front\nOTHER1.jpg,Should not land\n");

    [$batch] = runAltTextImport('incoming/2026-11-10');

    expect($batch->alt_text_applied)->toBe(1)
        ->and($batch->alt_text_error_count)->toBe(1)
        ->and($batch->altTextReportText())->toContain('OTHER1.jpg')
        ->and($imported->fresh()->alt_text)->toBe('Imported front')
        ->and($unrelated->fresh()->alt_text)->toBe('Leave me')
        ->and($unrelated->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED);
});

it('reports alt text separately when the image itself failed to import', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'front-photo');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/MISSING9.jpg', 'orphan-photo');
    createImageImportProduct('LA0550');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nLA0550.jpg,Front view\nMISSING9.jpg,Orphan text\n");

    [$batch] = runAltTextImport('incoming/2026-11-10');
    $failed = ShopifyImageImportItem::query()->where('s3_key', 'incoming/2026-11-10/MISSING9.jpg')->first();
    $updated = ShopifyImageImportItem::query()->where('s3_key', 'incoming/2026-11-10/LA0550.jpg')->first();

    expect($batch->failed_count)->toBe(1)
        ->and($batch->alt_text_applied)->toBe(1)
        ->and($batch->alt_text_error_count)->toBe(1)
        ->and($failed->status)->toBe(ShopifyImageImportItem::STATUS_FAILED)
        ->and($failed->message)->toContain('No product variant matched SKU')
        ->and($failed->alt_message)->toContain('image import failed')
        ->and($updated->status)->toBe(ShopifyImageImportItem::STATUS_UPDATED)
        ->and($updated->alt_message)->toBe('Alt text saved for review.')
        ->and($updated->message)->not->toContain('alt text');
});

it('saves alt text on the stack owned first image and rejects an inherited stack filename', function (): void {
    $stack = createImageImportProduct('STACK001', [
        'handle' => 'stack-one',
        'type' => 'Bundle',
        'is_bundle' => true,
    ]);
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/STACK001.jpg', 'lifestyle');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/STACK001-2.jpg', 'component-slot');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nSTACK001.jpg,Layered jewellery stack\nSTACK001-2.jpg,Do not own this\n");

    [$batch] = runAltTextImport('incoming/2026-11-10');
    $owned = $stack->images()->where('position', 1)->first();
    $inheritedSlot = $stack->images()->where('position', 2)->first();

    expect($batch->alt_text_applied)->toBe(1)
        ->and($batch->alt_text_error_count)->toBe(1)
        ->and($owned->alt_text)->toBe('Layered jewellery stack')
        ->and($owned->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($owned->source_image_id)->toBeNull()
        ->and($inheritedSlot->alt_text)->toBeNull()
        ->and($batch->altTextReportText())->toContain('inherited');
});

it('gives the existing stack rebuild the component alt text imported from the csv', function (): void {
    $component = createImageImportProduct('COMP001', ['handle' => 'component-one']);
    $stack = createImageImportProduct('STACK001', [
        'handle' => 'stack-one',
        'type' => 'Bundle',
        'is_bundle' => true,
    ]);
    $componentImage = syncedImportImage('COMP001', 'old-component', 'Old component', Image::ALT_APPROVED);
    $componentImage->forceFill(['product_id' => $component->id])->save();
    syncedImportImage('STACK001', 'old-stack', 'Old lifestyle', Image::ALT_APPROVED);
    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'STACK001',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'type' => 'Bundle',
        'tags' => 'bundles',
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/COMP001.jpg', 'new-component');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/STACK001.jpg', 'new-lifestyle');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nCOMP001.jpg,Component front\nSTACK001.jpg,Stack lifestyle\n");

    [$batch] = runAltTextImport('incoming/2026-11-10');
    $componentImage = $component->images()->where('position', 1)->first();
    expect($componentImage->alt_text)->toBe('Component front')
        ->and($componentImage->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);

    $result = app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch, [$stack->id]);
    $stackImages = $stack->allImages()->active()->orderBy('position')->get();

    expect($result['rebuilt'])->toBe(1)
        ->and($stackImages)->toHaveCount(2)
        ->and($stackImages[0]->alt_text)->toBe('Stack lifestyle')
        ->and($stackImages[0]->source_image_id)->toBeNull()
        ->and($stackImages[1]->source_image_id)->toBe($componentImage->id)
        ->and($stackImages[1]->alt_text)->toBe('Component front');
});

it('keeps an approved description when the csv text is identical and reviews a changed one', function (): void {
    $same = syncedImportImage('LA0550', 'front-photo', 'Keep this description', Image::ALT_APPROVED);
    $blank = syncedImportImage('LA0551', 'second-photo', 'Keep this description', Image::ALT_APPROVED);
    $changed = syncedImportImage('LA0552', 'third-photo', 'Keep this description', Image::ALT_APPROVED);
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'front-photo');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0551.jpg', 'second-photo');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0552.jpg', 'third-photo');
    Storage::disk('shopify_product_images')->put(
        'incoming/2026-11-10/alt-text.csv',
        "filename,alt_text\nLA0550.jpg,Keep this description\nLA0551.jpg,   \nLA0552.jpg,A changed description\n"
    );

    [$batch] = runAltTextImport('incoming/2026-11-10');

    expect($batch->alt_text_applied)->toBe(1)
        ->and($batch->alt_text_unchanged)->toBe(1)
        ->and($batch->alt_text_missing)->toBe(1)
        ->and($same->fresh()->alt_text)->toBe('Keep this description')
        ->and($same->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($same->fresh()->shopify_alt_text)->toBe('Keep this description')
        ->and($blank->fresh()->alt_text)->toBe('Keep this description')
        ->and($blank->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($changed->fresh()->alt_text)->toBe('A changed description')
        ->and($changed->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and(app(ImageAltTextBackfillService::class)->approvedUploadAlt($changed->fresh()))->toBeNull();
});

it('keeps the existing filename positions when alt text is imported', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550-2.jpg', 'second-image');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'first-image');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550-1.jpg', 'colliding-image');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/KEEP-2.jpg', 'literal-sku');
    $product = createImageImportProduct('LA0550');
    $literal = createImageImportProduct('KEEP-2');
    Storage::disk('shopify_product_images')->put(
        'incoming/2026-11-10/alt-text.csv',
        "filename,alt_text\nLA0550.jpg,First alt\nLA0550-1.jpg,Discarded alt\nLA0550-2.jpg,Second alt\nKEEP-2.jpg,Literal alt\n"
    );

    [$batch] = runAltTextImport('incoming/2026-11-10');
    $first = $product->images()->where('position', 1)->first();
    $second = $product->images()->where('position', 2)->first();

    expect($product->images()->count())->toBe(2)
        ->and($first->imageAsset?->original_filename)->toBe('LA0550.jpg')
        ->and($first->approved_filename)->toBe('la0550-1.jpg')
        ->and($first->alt_text)->toBe('First alt')
        ->and($second->imageAsset?->original_filename)->toBe('LA0550-2.jpg')
        ->and($second->approved_filename)->toBe('la0550-2.jpg')
        ->and($second->alt_text)->toBe('Second alt')
        ->and($literal->images()->where('position', 1)->first()->alt_text)->toBe('Literal alt')
        ->and($literal->images()->where('position', 2)->count())->toBe(0)
        ->and($batch->altTextReportText())->toContain('LA0550-1.jpg')
        ->and($batch->altTextReportText())->toContain('replaced by LA0550.jpg');
});

it('does not replace a newer manual description with the previous csv text', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'front-photo');
    $product = createImageImportProduct('LA0550');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nLA0550.jpg,Imported description\n");

    runAltTextImport('incoming/2026-11-10');
    $image = $product->images()->first();
    app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'Manual description');

    [$repeat] = runAltTextImport('incoming/2026-11-10');

    expect($repeat->alt_text_preserved)->toBe(1)
        ->and($repeat->alt_text_applied)->toBe(0)
        ->and($image->fresh()->alt_text)->toBe('Manual description')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($image->fresh()->imported_alt_text)->toBe('Imported description');

    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nLA0550.jpg,Newer import description\n");
    [$changed] = runAltTextImport('incoming/2026-11-10');

    expect($changed->alt_text_applied)->toBe(1)
        ->and($image->fresh()->alt_text)->toBe('Newer import description')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($image->fresh()->imported_alt_text)->toBe('Newer import description');
});

it('applies alt text before the stack rebuild job is dispatched', function (): void {
    Bus::fake([RebuildShopifyStackImagesJob::class]);
    $component = createImageImportProduct('COMP001', ['handle' => 'component-one']);
    $stack = createImageImportProduct('STACK001', [
        'handle' => 'stack-one',
        'type' => 'Bundle',
        'is_bundle' => true,
    ]);
    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'STACK001',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'type' => 'Bundle',
        'tags' => 'bundles',
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/COMP001.jpg', 'component-photo');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\nCOMP001.jpg,Ready for rebuild\n");
    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/2026-11-10',
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);

    app(ImportShopifyProductImagesJob::class, [
        'batchId' => $batch->id,
    ])->handle(app(ShopifyImageImportService::class));

    expect($component->images()->first()->alt_text)->toBe('Ready for rebuild')
        ->and($component->images()->first()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);

    Bus::assertDispatched(RebuildShopifyStackImagesJob::class);
});

/**
 * @return array{0: ShopifyImageImportBatch, 1: array<string, mixed>}
 */
function runAltTextImport(string $prefix): array
{
    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => $prefix,
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);
    $result = app(ShopifyImageImportService::class)->runBatch($batch);

    return [$batch->fresh(), $result];
}

function approveImportedImageAlt(Image $image): void
{
    $product = $image->product()->first();
    $product->forceFill(['approval_version' => $product->approval_version ?: 1])->save();
    $requester = User::factory()->create();
    $approver = User::factory()->create(['is_active' => true]);
    Role::findOrCreate(RolesEnum::Admin->value);
    $approver->assignRole(RolesEnum::Admin->value);
    Bus::fake([SendProductPartialApprovalSlackReminderJob::class]);
    $service = app(ProductPartialApprovalService::class);
    $service->request(
        collect([$product->fresh()]),
        $requester->id,
        [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT],
        [],
        $approver->id,
    );
    $request = ProductPartialApprovalRequest::query()
        ->where('product_id', $product->id)
        ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
        ->latest('id')
        ->first();
    expect($request)->not->toBeNull();
    $service->approveRequests(collect([$request]), $approver->id);
}

function syncedImportImage(string $sku, string $bytes, string $alt, string $status, int $position = 1): Image
{
    $product = Product::query()->whereHas('variants', fn ($query) => $query->where('sku', $sku))->first();
    if (! $product instanceof Product) {
        $product = createImageImportProduct($sku);
    }
    $extension = 'jpg';
    $asset = createImportTestAsset($bytes, $extension, $sku.($position > 1 ? '-'.$position : '').'.jpg');

    return Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/555',
        'image_asset_id' => $asset->id,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'src' => 'https://cdn.shopify.com/old.jpg',
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'position' => $position,
        'alt_text' => $alt,
        'shopify_alt_text' => $alt,
        'alt_text_status' => $status,
        'approved_filename' => strtolower($sku).'-'.$position.'.jpg',
        'filename_mode' => Image::FILENAME_MODE_MANUAL,
        'last_shopify_synced_image_asset_id' => $asset->id,
        'needs_shopify_image_sync' => false,
    ]));
}
