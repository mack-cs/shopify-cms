<?php

use App\Models\Approval;
use App\Models\EngravedBeadSellabilityOperation;
use App\Models\User;
use App\Services\EngravedBeads\EngravedBeadSellability;
use App\Services\EngravedBeads\EngravedBeadWriteGuard;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Support/EngravedBeadFamilyFixture.php';
require_once __DIR__.'/../Support/BeadSellabilityFake.php';
uses(RefreshDatabase::class);
beforeEach(function () {
    config(['services.shopify.inventory_location_id' => null, 'services.shopify.api_version' => '2026-04',
        'engraved_beads.enabled' => true, 'engraved_beads.inventory_writes_enabled' => true,
        'engraved_beads.store_contract_verified' => true]);
});

function beadSellabilityScenario(int $balance): array
{
    $fixture = sharedBeadFamily(active: true);
    $actors = [User::factory()->create(), User::factory()->create()];
    $fake = new BeadSellabilityFake;
    foreach ($fixture['members'] as $q => $m) {
        foreach ($actors as $actor) {
            Approval::withoutEvents(fn () => Approval::query()->create(['product_id' => $m['product']->id,
                'user_id' => $actor->id, 'approval_version' => $m['product']->fresh()->approval_version ?? 1]));
        }
        $fake->states[$q] = ['tracked' => $q === 1, 'sellable' => $q === 1 ? $balance > 0 : true,
            'available' => $q === 1 ? $balance : 0, 'on_hand' => $q === 1 ? $balance : 0,
            'committed' => 0, 'reserved' => 0, 'safety_stock' => 0, 'policy' => 'DENY', 'status' => 'UNLISTED'];
    }
    $inventory = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $inventory->shouldReceive('moveQuantity')->zeroOrMoreTimes()->andReturnUsing(function ($variant, $quantity, $from, $to) use ($fake): array {
        $fake->states[1][$from] -= $quantity;
        $fake->states[1][$to] += $quantity;
        $fake->states[1]['sellable'] = $fake->states[1]['available'] > 0;

        return [];
    });
    $service = new EngravedBeadSellability($fake, $inventory, new EngravedBeadWriteGuard);

    return [$fixture, $fake, $service];
}

it('enforces all inclusive thresholds while preserving the tracked source on-hand balance', function (int $balance) {
    [$f, $fake, $service] = beadSellabilityScenario($balance);
    $service->synchronize($f['family']->id);
    foreach ([1, 2, 3, 4] as $q) {
        expect($fake->states[$q]['sellable'])->toBe($balance > $q);
        if ($q > 1) {
            expect($fake->states[$q]['tracked'])->toBe($balance <= $q);
        }
    }
    expect($fake->states[1]['tracked'])->toBeTrue()->and($fake->states[1]['on_hand'])->toBe($balance);
    $before = count($fake->calls);
    $service->synchronize($f['family']->id);
    expect(count($fake->calls))->toBe($before);
})->with(range(0, 6));

it('re-enables only automated disables on replenishment and never assigns positive member inventory', function () {
    [$f, $fake, $service] = beadSellabilityScenario(1);
    $service->synchronize($f['family']->id);
    $fake->states[1]['available'] += 6;
    $fake->states[1]['on_hand'] += 6;
    $service->synchronize($f['family']->id);
    foreach ([1, 2, 3, 4] as $q) {
        expect($fake->states[$q]['sellable'])->toBeTrue();
    }
    foreach ([2, 3, 4] as $q) {
        expect($fake->states[$q]['tracked'])->toBeFalse()->and($fake->states[$q]['available'])->toBe(0);
    }
    expect($fake->states[1]['on_hand'])->toBe(7)->and($f['family']->fresh()->automation_held_quantity)->toBe(0);
});

it('keeps a failed transition uncertain and retries the same intent', function () {
    [$f, $fake, $service] = beadSellabilityScenario(4);
    $fake->failZero = true;
    expect(fn () => $service->synchronize($f['family']->id))->toThrow(RuntimeException::class);
    $operation = EngravedBeadSellabilityOperation::query()->firstOrFail();
    expect($operation->status)->toBe('uncertain')->and($f['family']->members()->where('required_beads', 4)->first()->automation_disabled)->toBeFalse();
    $fake->failZero = false;
    $service->synchronize($f['family']->id);
    expect($operation->fresh()->status)->toBe('confirmed')
        ->and(EngravedBeadSellabilityOperation::query()->count())->toBe(1);
    $keys = array_column(array_values(array_filter($fake->calls, fn ($c) => $c[0] === 'zero')), 2);
    expect(array_unique($keys))->toHaveCount(1);
});

it('does not run stale jobs or override manually disabled and unpublished products', function () {
    [$f, $fake, $service] = beadSellabilityScenario(4);
    $service->synchronize($f['family']->id, 0);
    expect($fake->calls)->toBe([]);
    $fake->states[4]['status'] = 'DRAFT';
    $service->synchronize($f['family']->id);
    expect($fake->calls)->toBe([]);
    $fake->states[4]['status'] = 'ACTIVE';
    $f['family']->members()->where('required_beads', 4)->update(['manual_disabled' => true]);
    $service->synchronize($f['family']->id);
    expect($fake->calls)->toBe([]);
});

it('performs no calls when production-write guards are disabled', function () {
    [$f, $fake, $service] = beadSellabilityScenario(1);
    config(['engraved_beads.inventory_writes_enabled' => false]);
    expect(fn () => $service->synchronize($f['family']->id))->toThrow(RuntimeException::class);
    expect($fake->calls)->toBe([])->and(EngravedBeadSellabilityOperation::query()->count())->toBe(0);
});

it('evaluates custom thresholds', function () {
    [$f, $fake, $service] = beadSellabilityScenario(6);
    $f['family']->members()->where('required_beads', 4)->update(['disable_threshold' => 6]);
    $service->synchronize($f['family']->id);
    expect($fake->states[4]['sellable'])->toBeFalse()->and($fake->states[3]['sellable'])->toBeTrue();
});
