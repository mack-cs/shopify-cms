<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductUrlRedirect;
use App\Models\User;
use App\Models\Variant;
use App\Services\ProductUrlRedirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('archives the source product and redirects its url to another product', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'redirect-import.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $source = Product::create([
        'import_id' => $import->id,
        'title' => 'Old Bracelet',
        'handle' => 'old-bracelet',
        'status' => 'active',
    ]);
    $target = Product::create([
        'import_id' => $import->id,
        'title' => 'Replacement Bracelet',
        'handle' => 'replacement-bracelet',
        'status' => 'active',
    ]);

    Variant::create([
        'product_id' => $source->id,
        'sku' => 'OLD-1',
    ]);
    Variant::create([
        'product_id' => $target->id,
        'sku' => 'NEW-1',
    ]);

    NewProductDraft::create([
        'sku' => 'OLD-1',
        'handle' => 'old-bracelet',
        'title' => 'Old Bracelet',
        'status' => 'active',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'redirect-import-');
    file_put_contents(
        $path,
        "SKU,Redirect To SKU\n"
        ."OLD-1,NEW-1\n"
        ."MISSING-1,NEW-1\n"
        ."OLD-1,OLD-1\n"
    );

    $result = app(ProductUrlRedirectService::class)->importRedirectsFromPath($path, $user->id);
    $redirect = ProductUrlRedirect::query()->where('path', '/products/old-bracelet')->first();

    expect($result['archived'])->toBe(1)
        ->and($result['created'])->toBe(1)
        ->and($result['skipped_missing_product'])->toBe(1)
        ->and($result['skipped_same_product'])->toBe(1)
        ->and($result['missing_products'])->toBe(['MISSING-1'])
        ->and($source->fresh()->status)->toBe('archived')
        ->and($target->fresh()->status)->toBe('active')
        ->and($source->fresh()->handle)->toBe('old-bracelet')
        ->and(NewProductDraft::query()->where('sku', 'OLD-1')->value('status'))->toBe('archived')
        ->and($redirect)->not->toBeNull()
        ->and($redirect->product_id)->toBe($source->id)
        ->and($redirect->target)->toBe('/products/replacement-bracelet')
        ->and($redirect->status)->toBe(ProductUrlRedirect::STATUS_PENDING);

    @unlink($path);
});

it('exports a template for a sku list with a blank redirect column', function (): void {
    Storage::fake('public');

    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'redirect-template.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $product = Product::create([
        'import_id' => $import->id,
        'title' => 'Old Bracelet',
        'handle' => 'old-bracelet',
        'status' => 'active',
    ]);
    Variant::create([
        'product_id' => $product->id,
        'sku' => 'OLD-1',
    ]);

    $service = app(ProductUrlRedirectService::class);
    $export = $service->exportArchiveRedirectTemplate($service->skusFromText("OLD-1\nGONE-1"));
    $csv = Storage::disk('public')->get($export['path']);

    expect($export['row_count'])->toBe(2)
        ->and($export['missing_skus'])->toBe(['GONE-1'])
        ->and($csv)->toContain('Redirect To SKU')
        ->and($csv)->toContain('OLD-1')
        ->and($csv)->toContain('Old Bracelet')
        ->and($csv)->toContain('old-bracelet')
        ->and($csv)->toContain('GONE-1');
});
