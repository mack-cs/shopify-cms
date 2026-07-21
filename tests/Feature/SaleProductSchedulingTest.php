<?php

use App\Jobs\RunScheduledSaleJob;
use App\Models\Import;
use App\Models\Product;
use App\Models\SaleImportItem;
use App\Models\SaleProductUpdate;
use App\Models\ScheduledJob;
use App\Models\ScheduledJobItem;
use App\Models\User;
use App\Models\Variant;
use App\Services\SaleProductSchedulingService;
use App\Services\SaleProductUpdateImporter;
use App\Services\TagNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('imports sale CSV rows by SKU and stages matched products without pushing local sale values live', function (): void {
    $user = User::factory()->create();
    $import = createSaleTestImport($user);
    $product = createSaleTestProduct($import, 'sunset-bracelet', 'LRB0001', '99.00', 'exclude-from-the-sale, bracelets, livi-road');
    createSaleTestProduct($import, 'failed-bracelet', 'BADSALE', '80.00', 'exclude-from-the-sale, bracelets');

    $path = tempnam(sys_get_temp_dir(), 'sale-import-');
    file_put_contents($path, implode("\n", [
        'sku,old price / current price,compare to price,sale price',
        'LRB0001,99.00,99.00,79.00',
        'NOPE,100.00,100.00,70.00',
        'BADSALE,80.00,80.00,90.00',
    ]));

    $result = app(SaleProductUpdateImporter::class)->importFromPath($path, $user->id, 'sale.csv');

    $product->refresh();
    $variant = $product->variants()->firstOrFail();
    $update = SaleProductUpdate::query()->firstOrFail();
    $preparedTags = TagNormalizer::parseTokens((string) $update->prepared_tags);

    expect($result['total'])->toBe(3)
        ->and($result['matched'])->toBe(1)
        ->and($result['unmatched'])->toBe(1)
        ->and($result['failed'])->toBe(1)
        ->and($result['pending'])->toBe(1)
        ->and($update->status)->toBe(SaleProductUpdate::STATUS_PENDING)
        ->and($update->sku)->toBe('LRB0001')
        ->and((string) $update->sale_price)->toBe('79.00')
        ->and((string) $update->compare_at_price)->toBe('99.00')
        ->and($preparedTags)->toContain('sale')
        ->and($preparedTags)->toContain('bracelets-sale')
        ->and($preparedTags)->toContain('livi-road-sale')
        ->and($preparedTags)->not->toContain('exclude-from-the-sale')
        ->and($product->tags)->toBe('exclude-from-the-sale, bracelets, livi-road')
        ->and((string) $variant->price)->toBe('99.00');

    expect(SaleImportItem::query()->where('status', SaleImportItem::STATUS_UNMATCHED)->value('sku'))->toBe('NOPE')
        ->and(SaleImportItem::query()->where('status', SaleImportItem::STATUS_FAILED)->value('sku'))->toBe('BADSALE');
});

it('adds sale by type and sale by collection tags when importing sale products', function (): void {
    $user = User::factory()->create();
    $import = createSaleTestImport($user);
    $product = createSaleTestProduct($import, 'untamed-charm', 'CHARM01', '440.00', 'exclude-from-the-sale, charms, untamed, bracelets-sale');
    $product->forceFill(['type' => 'Charms'])->save();

    $path = tempnam(sys_get_temp_dir(), 'sale-import-tags-');
    file_put_contents($path, implode("\n", [
        'SKU,Sale Price,Compare-at Price',
        'CHARM01,220.00,440.00',
    ]));

    app(SaleProductUpdateImporter::class)->importFromPath($path, $user->id, 'sale-tags.csv');

    $tags = TagNormalizer::parseTokens((string) SaleProductUpdate::query()->firstOrFail()->prepared_tags);

    expect($tags)->toContain('sale')
        ->and($tags)->toContain('charms-sale')
        ->and($tags)->toContain('untamed-sale')
        ->and($tags)->not->toContain('bracelets-sale')
        ->and($tags)->not->toContain('exclude-from-the-sale');
});

it('falls back to exported Shopify product ID when sale import SKU is blank', function (): void {
    $user = User::factory()->create();
    $import = createSaleTestImport($user);
    $product = createSaleTestProduct($import, 'test-bracelet-stack-copy', '', '1030.00', 'exclude-from-the-sale, bracelets');
    $product->forceFill([
        'shopify_id' => 'gid://shopify/Product/8960859570312',
    ])->save();
    $variant = $product->variants()->firstOrFail();
    $variant->forceFill([
        'sku' => null,
        'shopify_id' => 'gid://shopify/ProductVariant/111222333',
    ])->save();

    $path = tempnam(sys_get_temp_dir(), 'sale-import-gid-');
    file_put_contents($path, implode("\n", [
        'Draft ID,Handle,Shopify ID,SKU,Price,Compare-at Price',
        '470,test-bracelet-stack-copy,gid://shopify/Product/8960859570312,,799.00,1030.00',
    ]));

    $result = app(SaleProductUpdateImporter::class)->importFromPath($path, $user->id, 'draft-export-sale.csv');

    $update = SaleProductUpdate::query()->firstOrFail();

    expect($result['total'])->toBe(1)
        ->and($result['matched'])->toBe(1)
        ->and($result['pending'])->toBe(1)
        ->and($update->product_id)->toBe($product->id)
        ->and($update->variant_id)->toBe($variant->id)
        ->and($update->sku)->toBe('gid://shopify/Product/8960859570312')
        ->and((string) $update->sale_price)->toBe('799.00')
        ->and((string) $update->compare_at_price)->toBe('1030.00')
        ->and($update->metadata['shopify_product_id'])->toBe('gid://shopify/Product/8960859570312')
        ->and($update->metadata['shopify_variant_id'])->toBe('gid://shopify/ProductVariant/111222333');
});

it('uses product context to disambiguate duplicate SKUs in sale imports', function (): void {
    $user = User::factory()->create();
    $import = createSaleTestImport($user);
    $first = createSaleTestProduct($import, 'first-duplicate-sku', 'DUP01', '200.00');
    $second = createSaleTestProduct($import, 'second-duplicate-sku', 'DUP01', '300.00');
    $second->forceFill([
        'shopify_id' => 'gid://shopify/Product/900200300',
    ])->save();

    $path = tempnam(sys_get_temp_dir(), 'sale-import-duplicate-context-');
    file_put_contents($path, implode("\n", [
        'Handle,Shopify ID,SKU,Sale Price,Compare-at Price',
        'second-duplicate-sku,gid://shopify/Product/900200300,DUP01,250.00,300.00',
    ]));

    $result = app(SaleProductUpdateImporter::class)->importFromPath($path, $user->id, 'duplicate-context.csv');

    $update = SaleProductUpdate::query()->firstOrFail();

    expect($result['matched'])->toBe(1)
        ->and($update->product_id)->toBe($second->id)
        ->and($update->variant_id)->toBe($second->variants()->firstOrFail()->id)
        ->and((string) $update->sale_price)->toBe('250.00')
        ->and($update->product_id)->not->toBe($first->id);
});

it('fails duplicate SKU sale rows without product context instead of guessing', function (): void {
    $user = User::factory()->create();
    $import = createSaleTestImport($user);
    createSaleTestProduct($import, 'first-ambiguous-sku', 'DUP02', '200.00');
    createSaleTestProduct($import, 'second-ambiguous-sku', 'DUP02', '300.00');

    $path = tempnam(sys_get_temp_dir(), 'sale-import-duplicate-no-context-');
    file_put_contents($path, implode("\n", [
        'SKU,Sale Price,Compare-at Price',
        'DUP02,150.00,200.00',
    ]));

    $result = app(SaleProductUpdateImporter::class)->importFromPath($path, $user->id, 'duplicate-no-context.csv');

    expect($result['matched'])->toBe(0)
        ->and($result['unmatched'])->toBe(1)
        ->and(SaleProductUpdate::query()->count())->toBe(0)
        ->and(SaleImportItem::query()->firstOrFail()->message)->toContain('Multiple local variants matched SKU DUP02');
});

it('schedules only sale-approved updates and queues the scheduled sale job', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $import = createSaleTestImport($user);
    $approvedProduct = createSaleTestProduct($import, 'approved-bracelet', 'LRB0002', '120.00');
    $pendingProduct = createSaleTestProduct($import, 'pending-bracelet', 'LRB0003', '110.00');

    $approved = SaleProductUpdate::create([
        'product_id' => $approvedProduct->id,
        'variant_id' => $approvedProduct->variants()->firstOrFail()->id,
        'sku' => 'LRB0002',
        'status' => SaleProductUpdate::STATUS_APPROVED,
        'current_price' => '120.00',
        'sale_price' => '95.00',
        'compare_at_price' => '120.00',
        'prepared_tags' => 'bracelets, sale',
        'approved_at' => now(),
        'approved_by' => $user->id,
    ]);
    $pending = SaleProductUpdate::create([
        'product_id' => $pendingProduct->id,
        'variant_id' => $pendingProduct->variants()->firstOrFail()->id,
        'sku' => 'LRB0003',
        'status' => SaleProductUpdate::STATUS_PENDING,
        'current_price' => '110.00',
        'sale_price' => '88.00',
        'compare_at_price' => '110.00',
        'prepared_tags' => 'bracelets, sale',
    ]);

    $scheduledAt = now('Africa/Johannesburg')->addHour();
    $job = app(SaleProductSchedulingService::class)->createSaleJob($scheduledAt, $user->id);

    $approved->refresh();
    $pending->refresh();

    expect($job->status)->toBe(ScheduledJob::STATUS_SCHEDULED)
        ->and($job->type)->toBe(ScheduledJob::TYPE_SALE_PRODUCT_UPDATE)
        ->and($job->total_items)->toBe(1)
        ->and($approved->status)->toBe(SaleProductUpdate::STATUS_SCHEDULED)
        ->and($approved->scheduled_job_id)->toBe($job->id)
        ->and($pending->status)->toBe(SaleProductUpdate::STATUS_PENDING)
        ->and(ScheduledJobItem::query()->count())->toBe(1)
        ->and(ScheduledJobItem::query()->firstOrFail()->sku)->toBe('LRB0002');

    Queue::assertPushed(RunScheduledSaleJob::class, fn (RunScheduledSaleJob $queued): bool => $queued->scheduledJobId === $job->id);
});

it('clears approved sale products from the scheduling queue without touching pending rows', function (): void {
    $user = User::factory()->create();
    $import = createSaleTestImport($user);
    $approvedProduct = createSaleTestProduct($import, 'clear-approved-bracelet', 'CLR001', '120.00');
    $pendingProduct = createSaleTestProduct($import, 'keep-pending-bracelet', 'CLR002', '110.00');

    $approved = SaleProductUpdate::create([
        'product_id' => $approvedProduct->id,
        'variant_id' => $approvedProduct->variants()->firstOrFail()->id,
        'sku' => 'CLR001',
        'status' => SaleProductUpdate::STATUS_APPROVED,
        'current_price' => '120.00',
        'sale_price' => '95.00',
        'compare_at_price' => '120.00',
        'prepared_tags' => 'bracelets, sale',
        'approved_at' => now(),
        'approved_by' => $user->id,
    ]);
    $pending = SaleProductUpdate::create([
        'product_id' => $pendingProduct->id,
        'variant_id' => $pendingProduct->variants()->firstOrFail()->id,
        'sku' => 'CLR002',
        'status' => SaleProductUpdate::STATUS_PENDING,
        'current_price' => '110.00',
        'sale_price' => '88.00',
        'compare_at_price' => '110.00',
        'prepared_tags' => 'bracelets, sale',
    ]);

    $count = app(SaleProductSchedulingService::class)->clearApprovedForScheduling($user->id);

    expect($count)->toBe(1)
        ->and($approved->refresh()->status)->toBe(SaleProductUpdate::STATUS_CANCELLED)
        ->and($approved->scheduled_job_id)->toBeNull()
        ->and($approved->scheduled_at)->toBeNull()
        ->and($pending->refresh()->status)->toBe(SaleProductUpdate::STATUS_PENDING)
        ->and(app(SaleProductSchedulingService::class)->approvedCount())->toBe(0);
});

it('cancels scheduled sale jobs before they run', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $import = createSaleTestImport($user);
    $firstProduct = createSaleTestProduct($import, 'cancel-scheduled-first', 'CNL001', '120.00');
    $secondProduct = createSaleTestProduct($import, 'cancel-scheduled-second', 'CNL002', '110.00');

    $first = SaleProductUpdate::create([
        'product_id' => $firstProduct->id,
        'variant_id' => $firstProduct->variants()->firstOrFail()->id,
        'sku' => 'CNL001',
        'status' => SaleProductUpdate::STATUS_APPROVED,
        'current_price' => '120.00',
        'sale_price' => '95.00',
        'compare_at_price' => '120.00',
        'prepared_tags' => 'bracelets, sale',
        'approved_at' => now(),
        'approved_by' => $user->id,
    ]);
    $second = SaleProductUpdate::create([
        'product_id' => $secondProduct->id,
        'variant_id' => $secondProduct->variants()->firstOrFail()->id,
        'sku' => 'CNL002',
        'status' => SaleProductUpdate::STATUS_APPROVED,
        'current_price' => '110.00',
        'sale_price' => '88.00',
        'compare_at_price' => '110.00',
        'prepared_tags' => 'bracelets, sale',
        'approved_at' => now(),
        'approved_by' => $user->id,
    ]);

    $service = app(SaleProductSchedulingService::class);
    $job = $service->createSaleJob(now('Africa/Johannesburg')->addHour(), $user->id);

    $result = $service->cancelScheduledSaleJobs($user->id);

    expect($result)->toBe(['jobs' => 1, 'updates' => 2, 'items' => 2])
        ->and($job->refresh()->status)->toBe(ScheduledJob::STATUS_CANCELLED)
        ->and($first->refresh()->status)->toBe(SaleProductUpdate::STATUS_CANCELLED)
        ->and($second->refresh()->status)->toBe(SaleProductUpdate::STATUS_CANCELLED)
        ->and(ScheduledJobItem::query()->where('scheduled_job_id', $job->id)->pluck('status')->all())
        ->toBe([
            ScheduledJobItem::STATUS_SKIPPED,
            ScheduledJobItem::STATUS_SKIPPED,
        ])
        ->and($service->scheduledCount())->toBe(0);
});

function createSaleTestImport(User $user): Import
{
    return Import::create([
        'filename' => 'sale-test.csv',
        'status' => 'ready',
        'mode' => 'overwrite',
        'created_by' => $user->id,
    ]);
}

function createSaleTestProduct(Import $import, string $handle, string $sku, string $price, string $tags = 'bracelets'): Product
{
    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/' . crc32($handle),
        'handle' => $handle,
        'title' => ucwords(str_replace('-', ' ', $handle)),
        'type' => 'Bracelets',
        'tags' => $tags,
        'status' => 'active',
        'approval_version' => 1,
    ]));

    Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/' . crc32($sku),
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => $sku,
        'price' => $price,
        'compare_at_price' => $price,
    ]));

    return $product;
}
