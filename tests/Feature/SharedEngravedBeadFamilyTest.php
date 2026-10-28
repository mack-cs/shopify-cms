<?php

use App\Models\EngravedBeadFamily;
use App\Models\EngravedBeadFamilyMember;
use App\Services\EngravedBeads\EngravedBeadAvailability;
use App\Services\EngravedBeads\EngravedBeadFamilyDiscovery;
use App\Services\EngravedBeads\EngravedBeadStockPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/EngravedBeadFamilyFixture.php';
uses(RefreshDatabase::class);
beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.shopify.inventory_location_id' => null]);
});

it('discovers all seven known families without activating them', function () {
    foreach (['rectangle', 'square-medium', 'rounded-squares', 'rounded', 'large-facets', 'chevron', 'slims'] as $i => $slug) {
        sharedBeadFamily($slug.'-bead-family', 60000 + $i * 10);
    }
    expect(EngravedBeadFamily::query()->count())->toBe(7)
        ->and(EngravedBeadFamilyMember::query()->count())->toBe(28)
        ->and(EngravedBeadFamily::query()->where('status', 'active')->exists())->toBeFalse();
    foreach (EngravedBeadFamily::all() as $family) {
        expect($family->validation_errors)->toBe([])->and($family->members()->count())->toBe(4);
    }
});

it('discovers an unknown family without a code change', function () {
    $fixture = sharedBeadFamily('new-moon-bead-family');
    expect($fixture['family']->validation_errors)->toBe([])
        ->and($fixture['family']->status)->toBe('pending_validation');
});

it('rejects invalid member tags and SKU', function ($change, $message) {
    $fixture = sharedBeadFamily();
    $member = $fixture['members'][2];
    $change($member);
    $groups = app(EngravedBeadFamilyDiscovery::class)->synchronize();
    expect(implode(' ', $groups['test-design-bead-family']['errors']))->toContain($message)
        ->and($fixture['family']->fresh()->status)->toBe('pending_validation');
})->with([
    'missing SKU' => [fn ($m) => $m['variant']->update(['sku' => null]), 'missing SKU'],
    'missing quantity' => [fn ($m) => $m['product']->update(['tags' => 'test-design-bead-family']), 'quantity tag'],
    'conflicting quantities' => [fn ($m) => $m['product']->update(['tags' => 'test-design-bead-family, 2-engraved-beads, 3-engraved-beads']), 'quantity tag'],
    'conflicting families' => [fn ($m) => $m['product']->update(['tags' => 'test-design-bead-family, other-bead-family, 2-engraved-beads']), 'family tag'],
    'duplicate family tag' => [fn ($m) => $m['product']->update(['tags' => 'test-design-bead-family, test-design-bead-family, 2-engraved-beads']), 'family tag'],
    'duplicate source' => [fn ($m) => $m['product']->update(['tags' => 'test-design-bead-family, 1-engraved-beads']), 'each quantity'],
    'tracked member' => [fn ($m) => $m['variant']->update(['inventory_tracked' => true]), 'must be untracked'],
    'missing identity' => [fn ($m) => $m['variant']->update(['shopify_inventory_item_id' => null]), 'InventoryItem identity'],
]);

it('preserves approved mappings and blocks drift even if tags are later restored', function () {
    $fixture = sharedBeadFamily(active: true);
    $original = $fixture['family']->members()->get()->toArray();
    $product = $fixture['members'][2]['product'];
    $tags = $product->tags;
    $product->update(['tags' => 'other-bead-family, 2-engraved-beads']);
    app(EngravedBeadFamilyDiscovery::class)->synchronize();
    expect($fixture['family']->fresh()->status)->toBe('mapping_conflict')
        ->and($fixture['family']->members()->get()->toArray())->toBe($original);
    $product->update(['tags' => $tags]);
    app(EngravedBeadFamilyDiscovery::class)->synchronize();
    expect($fixture['family']->fresh()->status)->toBe('mapping_conflict');
});

it('resolves every family member to the same tracked source', function () {
    $fixture = sharedBeadFamily(active: true);
    foreach ($fixture['members'] as $m) {
        expect(app(EngravedBeadStockPool::class)->source($m['variant'])?->id)->toBe($fixture['members'][1]['variant']->id);
    }
});

it('detects source tracking loss and does not infer a replacement', function () {
    $fixture = sharedBeadFamily(active: true);
    $fixture['members'][1]['variant']->update(['inventory_tracked' => false]);
    expect(app(EngravedBeadStockPool::class)->source($fixture['members'][4]['variant']))->toBeNull();
});

it('uses inclusive thresholds for available balances zero through six', function (int $available) {
    foreach ([1, 2, 3, 4] as $threshold) {
        expect((new EngravedBeadAvailability)->shouldDisable($available, $threshold))->toBe($available <= $threshold);
    }
})->with(range(0, 6));

it('does not subtract already applied remote reservations twice', function () {
    expect((new EngravedBeadAvailability)->calculate(7, 0))->toBe(7)
        ->and((new EngravedBeadAvailability)->calculate(7, 2))->toBe(5)
        ->and((new EngravedBeadAvailability)->calculate(0, 0, 1))->toBe(1);
});
