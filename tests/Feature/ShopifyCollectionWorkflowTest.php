<?php

use App\Filament\Resources\ShopifyCollectionResource;
use App\Models\Import;
use App\Models\ShopifyCollection;
use App\Models\User;
use App\Services\ShopifyCollectionSeoImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates a pending collection handle from the approved title', function () {
    $firstApprover = User::factory()->create();
    $secondApprover = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-collections',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $firstApprover->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $collection = ShopifyCollection::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Collection/1',
        'handle' => 'summer-edit',
        'title' => 'Summer Edit',
        'draft_title' => 'Fresh Linen',
        'draft_seo_title' => 'Fresh Linen',
        'draft_seo_description' => 'Fresh Linen description',
        'approval_version' => 1,
    ]);

    ShopifyCollection::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Collection/2',
        'handle' => 'fresh-linen',
        'title' => 'Existing Fresh Linen',
        'approval_version' => 1,
    ]);

    ShopifyCollectionResource::approveRecord($collection, $firstApprover->id);
    $result = ShopifyCollectionResource::approveRecord($collection->fresh(), $secondApprover->id);

    expect($result['status'])->toBe('approved');

    $collection->refresh();

    expect($collection->title)->toBe('Fresh Linen')
        ->and($collection->handle)->toBe('summer-edit')
        ->and($collection->draft_handle)->toBe('fresh-linen-2');
});

it('keeps existing draft values when a collection seo csv row leaves a mapped field blank', function () {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-collections',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $collection = ShopifyCollection::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Collection/11',
        'handle' => 'linen-edit',
        'title' => 'Linen Edit',
        'draft_title' => 'Keep This Title',
        'draft_seo_title' => 'Old SEO Title',
        'approval_version' => 1,
    ]);

    $path = tempnam(sys_get_temp_dir(), 'collection-seo-');
    file_put_contents($path, "handle,title,seo_title\nlinen-edit,,Updated SEO Title\n");

    $result = app(ShopifyCollectionSeoImporter::class)->importFromPath($import, $path);

    expect($result['updated'])->toBe(1);

    $collection->refresh();

    expect($collection->draft_title)->toBe('Keep This Title')
        ->and($collection->draft_seo_title)->toBe('Updated SEO Title');

    @unlink($path);
});
