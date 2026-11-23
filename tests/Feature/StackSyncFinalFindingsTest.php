<?php

use App\Enums\RolesEnum;
use App\Filament\Pages\StackImageSyncStatus;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Services\ComponentStackPropagation;
use App\Services\ImageOwnership;
use App\Services\StackImageSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

function phase7Flags(): void
{
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
    ]);
}

function phase7Draft(Product $stack, array $componentIds): void
{
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => (string) $stack->variants()->value('sku'),
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'status' => 'active',
        'bundle_product_ids' => $componentIds,
    ]));
}

it('discovers a tag-only stack without inventing membership from an unrelated tag', function (): void {
    Storage::fake('public');
    Http::preventStrayRequests();
    phase7Flags();
    $component = createImageImportProduct('TAG-COMPONENT');
    $asset = createImportTestAsset('tag-bytes-unique', 'jpg', 'tag.jpg');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'alt_text' => 'Tag sentence',
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    $tagged = createImageImportProduct('TAG-STACK', ['is_bundle' => false, 'tags' => 'stack']);
    $linked = createImageImportProduct('LINKED-STACK', ['is_bundle' => true]);
    $unrelated = createImageImportProduct('STACKABLE', ['is_bundle' => false, 'tags' => 'stackable']);
    phase7Draft($tagged, [$component->id]);
    phase7Draft($linked, [$component->id]);

    $ids = Product::query()->recognizedStacks()->pluck('id')->all();
    expect($tagged->fresh()->isStackProduct())->toBeTrue()
        ->and($unrelated->fresh()->isStackProduct())->toBeFalse()
        ->and($ids)->toContain($tagged->id)
        ->and($ids)->toContain($linked->id)
        ->and($ids)->not->toContain($unrelated->id);

    $this->artisan('images:rebuild-stack-baseline', ['stack' => $unrelated->id])
        ->expectsOutput('No stack products matched.')
        ->assertSuccessful();
    $this->artisan('images:rebuild-stack-baseline', ['stack' => $tagged->id])
        ->expectsOutput('tag-stack: rebuilt the local inherited gallery (1 photograph(s)). Stack-owned images were left in place. Shopify was not updated.')
        ->assertSuccessful();
    $this->artisan('images:rebuild-stack-baseline', ['stack' => $tagged->id])->assertSuccessful();
    $this->artisan('images:rebuild-stack-baseline', ['stack' => $linked->id])->assertSuccessful();

    $copy = Image::query()->where('product_id', $tagged->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    expect($copy)->not->toBeNull()
        ->and(Image::query()->where('product_id', $tagged->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(1)
        ->and(Image::query()->where('product_id', $linked->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(1);

    Image::withoutEvents(fn () => $copy->forceFill(['shopify_id' => 'gid://shopify/MediaImage/tag-1'])->save());
    $owned = Image::withoutEvents(fn () => Image::create([
        'product_id' => $tagged->id,
        'position' => 2,
        'ownership' => ImageOwnership::STACK_OWNED,
        'alt_text' => 'Stack owned',
    ]));
    NewProductDraft::withoutEvents(fn () => NewProductDraft::query()->where('shopify_id', $tagged->shopify_id)->first()->forceFill(['bundle_product_ids' => []])->save());
    app(ComponentStackPropagation::class)->stageStack($tagged->fresh());

    expect(Image::query()->find($copy->id)->shopify_id)->toBe('gid://shopify/MediaImage/tag-1')
        ->and(Image::query()->whereKey($owned->id)->exists())->toBeTrue()
        ->and(Http::recorded())->toBeEmpty();
});

it('keeps authorization evidence when the stack sync migration is rolled back', function (): void {
    Http::preventStrayRequests();
    $product = createImageImportProduct('AUTH-KEEP');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'alt_text' => 'Kept sentence',
        'photograph_version' => 3,
        'alt_text_version' => 5,
    ]));
    $now = now();
    DB::table('component_image_authorizations')->insert([
        [
            'product_id' => $product->id,
            'image_id' => $image->id,
            'scope' => 'images',
            'photograph_version' => 3,
            'alt_text_version' => 1,
            'alt_text' => 'Photograph authorization',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'product_id' => $product->id,
            'image_id' => $image->id,
            'scope' => 'image_alt',
            'photograph_version' => 1,
            'alt_text_version' => 5,
            'alt_text' => 'Alt authorization',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);
    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $product->id,
        'outcome' => 'uncertain',
        'detail' => 'Shopify gallery evidence is incomplete. Nothing was marked verified.',
        'baseline_verified' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('image_publication_reminders')->insert([
        'product_id' => $product->id,
        'attention_since' => $now,
        'kind' => 'initial',
        'status' => 'accepted',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migration = include database_path('migrations/2026_11_20_120000_add_stack_image_sync_versions_and_ledger.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);

    expect(Schema::hasTable('component_image_authorizations'))->toBeTrue()
        ->and(Schema::hasTable('stack_image_sync_ledger'))->toBeTrue()
        ->and(Schema::hasTable('image_publication_reminders'))->toBeTrue()
        ->and(Schema::hasColumn('images', 'photograph_version'))->toBeTrue()
        ->and(Schema::hasColumn('images', 'alt_text_version'))->toBeTrue()
        ->and((int) $image->fresh()->photograph_version)->toBe(3)
        ->and((int) $image->fresh()->alt_text_version)->toBe(5)
        ->and((int) DB::table('component_image_authorizations')->where('image_id', $image->id)->where('scope', 'images')->value('photograph_version'))->toBe(3)
        ->and((int) DB::table('component_image_authorizations')->where('image_id', $image->id)->where('scope', 'image_alt')->value('alt_text_version'))->toBe(5)
        ->and(DB::table('component_image_authorizations')->where('image_id', $image->id)->count())->toBe(2)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $product->id)->count())->toBe(1)
        ->and(DB::table('image_publication_reminders')->where('product_id', $product->id)->count())->toBe(1)
        ->and(Http::recorded())->toBeEmpty();
});

it('shows a failed local rebuild instead of a shopify success', function (): void {
    Storage::fake('public');
    Http::preventStrayRequests();
    phase7Flags();
    config(['images.component_stack_local_rebuild' => true]);
    $component = createImageImportProduct('MISS-COMPONENT');
    $asset = createImportTestAsset('missing-bytes-unique', 'jpg', 'missing.jpg');
    Storage::disk('public')->delete($asset->storage_path);
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'alt_text' => 'Missing file',
    ]));
    $stack = createImageImportProduct('MISS-STACK', ['is_bundle' => true]);
    phase7Draft($stack, [$component->id]);
    $failed = app(ComponentStackPropagation::class)->stageStack($stack);
    $source = Image::query()->where('product_id', $component->id)->first();
    expect($failed['rebuilt'])->toBeFalse()
        ->and($failed['message'])->toContain('local rebuild failed')
        ->and($failed['message'])->toContain('Image '.$source->id.' on MISS-COMPONENT (missing.jpg) was not copied: the backup file is not readable on the CMS disk.')
        ->and($failed['message'])->not->toContain('rebuilt the local inherited gallery');

    $sync = app(StackImageSync::class);
    $this->travel(-2)->hours();
    $sync->note($stack, null, null, 'uncertain', 'Shopify gallery evidence is incomplete. Nothing was marked verified. The file read failed.', false, null, false, false, StackImageSync::OPERATION_GALLERY_READ);
    $this->travel(2)->hours();

    expect($sync->rebuildDependents((int) $component->id))->toBe(0)
        ->and($sync->describe($stack->fresh())['phase'])->toBe('local_rebuild_failed')
        ->and(collect($sync->outstandingReminderRows())->pluck('product_id')->all())->toContain($stack->id)
        ->and(Http::recorded())->toBeEmpty();

    $held = createImageImportProduct('HELD-STACK', ['is_bundle' => true]);
    $verified = createImageImportProduct('VERIFIED-STACK', ['is_bundle' => true]);
    $sync->note($held, null, null, 'shopify_authorization_pending', 'Local gallery is current. Shopify publication is not authorized.', false);
    $sync->note($verified, null, null, 'verified', 'Shopify read-back matched the authorized gallery.', true);
    expect($sync->describe($held)['phase'])->toBe('shopify_update_pending_authorization')
        ->and($sync->describe($verified)['phase'])->toBe('shopify_synchronization_verified')
        ->and($sync->describe($stack->fresh())['phase'])->toBe('local_rebuild_failed');

    $admin = User::factory()->create(['is_active' => true]);
    Role::findOrCreate(RolesEnum::Admin->value);
    $admin->assignRole(RolesEnum::Admin->value);
    $this->actingAs($admin);
    Livewire::test(StackImageSyncStatus::class)
        ->loadTable()
        ->callTableAction('rebuild', $stack)
        ->assertNotified('Local gallery was not rebuilt')
        ->assertSee('local rebuild failed')
        ->assertSee('Image '.$source->id.' on MISS-COMPONENT (missing.jpg) was not copied: the backup file is not readable on the CMS disk.')
        ->assertNotNotified('Local gallery rebuilt')
        ->assertCanSeeTableRecords([$stack, $held, $verified])
        ->assertSee('local_rebuild_failed')
        ->assertSee('shopify_update_pending_authorization')
        ->assertSee('shopify_synchronization_verified');

    expect($sync->describe($stack->fresh())['phase'])->toBe('local_rebuild_failed')
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'local_rebuilt')->count())->toBe(0);
});
