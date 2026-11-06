<?php

use App\Jobs\SynchronizeEngravedBeadFamilyJob;
use App\Models\Approval;
use App\Models\EngravedBeadSellabilityOperation;
use App\Models\User;
use App\Services\EngravedBeads\EngravedBeadRecalculation;
use App\Services\EngravedBeads\EngravedBeadSellability;
use App\Services\EngravedBeads\EngravedBeadWriteGuard;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

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

it('dispatches sellability immediately when a numeric inventory webhook matches the tracked source', function () {
    [$f] = beadSellabilityScenario(8);
    Bus::fake();
    $numeric = (string) preg_replace('/\D/', '', (string) $f['family']->source_inventory_item_id);

    app(EngravedBeadRecalculation::class)->inventoryItem($numeric);

    Bus::assertDispatched(SynchronizeEngravedBeadFamilyJob::class, function (SynchronizeEngravedBeadFamilyJob $job) use ($f): bool {
        return $job->familyId === $f['family']->id
            && $job->configurationVersion === (int) $f['family']->configuration_version
            && $job->uniqueId() === 'bead-family:'.$f['family']->id.':'.$f['family']->configuration_version;
    });
});

it('does not dispatch sellability when either inventory flag is off', function (array $flags) {
    [$f] = beadSellabilityScenario(8);
    config($flags);
    Bus::fake();

    app(EngravedBeadRecalculation::class)->inventoryItem($f['family']->source_inventory_item_id);
    app(EngravedBeadRecalculation::class)->dispatchPending();

    expect($f['family']->fresh()->recalculate_at)->not->toBeNull();
    Bus::assertNotDispatched(SynchronizeEngravedBeadFamilyJob::class);
})->with([
    'writes off' => [['engraved_beads.inventory_writes_enabled' => false, 'engraved_beads.store_contract_verified' => true]],
    'contract off' => [['engraved_beads.inventory_writes_enabled' => true, 'engraved_beads.store_contract_verified' => false]],
]);

it('keeps a recalculation requested after sellability started and clears an older one', function () {
    [$f, , $service] = beadSellabilityScenario(8);
    $f['family']->update(['recalculate_at' => now()->subMinute()]);
    $service->synchronize($f['family']->id);
    expect($f['family']->fresh()->recalculate_at)->toBeNull();

    $f['family']->update(['recalculate_at' => now()->addMinute()]);
    $service->synchronize($f['family']->id);
    expect($f['family']->fresh()->recalculate_at)->not->toBeNull()
        ->and($f['family']->fresh()->recalculate_at->greaterThan(now()))->toBeTrue();
});

it('records local dispatch time for immediate sellability without calling Shopify', function () {
    [$f] = beadSellabilityScenario(8);
    Bus::fake();
    $service = app(EngravedBeadRecalculation::class);
    $samples = [];
    for ($i = 0; $i < 31; $i++) {
        $start = hrtime(true);
        $service->inventoryItem($f['family']->source_inventory_item_id);
        $samples[] = (hrtime(true) - $start) / 1e6;
    }
    sort($samples);
    fwrite(STDERR, 'BEAD_DISPATCH_MS '.json_encode(['median' => round($samples[15], 2), 'slowest' => round($samples[30], 2)])."\n");
    expect($samples[30])->toBeLessThan(2000.0);
    Bus::assertDispatched(SynchronizeEngravedBeadFamilyJob::class);
});

it('records a family recalculation without queueing Shopify sellability while writes are disabled', function () {
    [$f] = beadSellabilityScenario(8);
    config(['engraved_beads.inventory_writes_enabled' => false, 'engraved_beads.store_contract_verified' => false]);
    Bus::fake();

    app(EngravedBeadRecalculation::class)->inventoryItem($f['family']->source_inventory_item_id);
    app(EngravedBeadRecalculation::class)->dispatchPending();

    expect($f['family']->fresh()->recalculate_at)->not->toBeNull();
    Bus::assertNotDispatched(SynchronizeEngravedBeadFamilyJob::class);
});

it('queues sellability for a family that the inventory ledger marked for recalculation', function () {
    [$f] = beadSellabilityScenario(8);
    $f['family']->update(['recalculate_at' => now(), 'last_reconciled_at' => now()]);
    Bus::fake();

    app(EngravedBeadRecalculation::class)->dispatchPending();

    Bus::assertDispatched(SynchronizeEngravedBeadFamilyJob::class, function (SynchronizeEngravedBeadFamilyJob $job) use ($f): bool {
        return $job->familyId === $f['family']->id;
    });
});

it('performs no calls when production-write guards are disabled', function () {
    [$f, $fake, $service] = beadSellabilityScenario(1);
    config(['engraved_beads.inventory_writes_enabled' => false]);
    expect(fn () => $service->synchronize($f['family']->id))->toThrow(RuntimeException::class);
    expect($fake->calls)->toBe([])->and(EngravedBeadSellabilityOperation::query()->count())->toBe(0);
});

it('evaluates custom thresholds', function () {
    [$f, $fake, $service] = beadSellabilityScenario(6);
    config(['engraved_beads.thresholds.4' => 6]);
    $service->synchronize($f['family']->id);
    expect($fake->states[4]['sellable'])->toBeFalse()->and($fake->states[3]['sellable'])->toBeTrue();
});

it('stops each multi-bead product at its configured buffer', function () {
    config(['engraved_beads.thresholds.2' => 5, 'engraved_beads.thresholds.3' => 7, 'engraved_beads.thresholds.4' => 10]);
    [$f, $fake, $service] = beadSellabilityScenario(10);
    $service->synchronize($f['family']->id);
    expect($fake->states[4]['sellable'])->toBeFalse()
        ->and($fake->states[3]['sellable'])->toBeTrue()
        ->and($fake->states[2]['sellable'])->toBeTrue()
        ->and($fake->states[1]['sellable'])->toBeTrue();
});

it('uses the physical bead count when a safety buffer is missing or invalid', function () {
    config(['engraved_beads.thresholds.4' => '-1', 'engraved_beads.thresholds.3' => '3.5', 'engraved_beads.thresholds.2' => '']);
    $thresholds = app(\App\Services\EngravedBeads\EngravedBeadSafetyThresholds::class);
    expect($thresholds->configured(4))->toBe(4)
        ->and($thresholds->configured(3))->toBe(3)
        ->and($thresholds->configured(2))->toBe(2);
    config(['engraved_beads.thresholds.2' => 0]);
    expect($thresholds->configured(2))->toBe(0);
});

it('stores a changed safety buffer without queueing Shopify while writes are disabled', function () {
    [$f] = beadSellabilityScenario(8);
    config([
        'engraved_beads.thresholds.4' => 10,
        'engraved_beads.inventory_writes_enabled' => false,
        'engraved_beads.store_contract_verified' => false,
    ]);
    Bus::fake();
    app(EngravedBeadRecalculation::class)->dispatchPending();
    expect($f['family']->members()->where('required_beads', 4)->value('disable_threshold'))->toBe(10)
        ->and($f['family']->fresh()->recalculate_at)->not->toBeNull()
        ->and($f['family']->fresh()->configuration_version)->toBeGreaterThan(1);
    Bus::assertNotDispatched(SynchronizeEngravedBeadFamilyJob::class);
});

it('queues the existing order job when a recent family sale has no webhook', function () {
    [$f] = beadSellabilityScenario(8);
    config(['engraved_beads.missed_order_recovery' => true]);
    $variantId = $f['members'][4]['variant']->shopify_id;
    app()->instance(\App\Contracts\ShopifyGraphqlGateway::class, new class($variantId) implements \App\Contracts\ShopifyGraphqlGateway
    {
        public function __construct(private string $variantId) {}

        public function graphql(string $query, array $variables = []): array
        {
            return ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => [[
                'id' => 'gid://shopify/Order/900',
                'name' => '#900',
                'updatedAt' => now()->toIso8601String(),
                'cancelledAt' => null,
                'lineItems' => ['nodes' => [[
                    'id' => 'gid://shopify/LineItem/900',
                    'quantity' => 1,
                    'currentQuantity' => 1,
                    'variant' => ['id' => $this->variantId],
                ]]],
            ]]]];
        }
    });
    Bus::fake();
    $recovery = app(\App\Services\EngravedBeads\EngravedBeadMissedOrderRecovery::class);
    $recovery->recover();
    $recovery->recover();
    expect(\App\Models\ShopifyStackOrderEvent::query()->where('shopify_order_id', 'gid://shopify/Order/900')->count())->toBe(1);
    Bus::assertDispatchedTimes(\App\Jobs\ProcessShopifyStackOrderEventJob::class, 1);

    config(['engraved_beads.store_contract_verified' => false]);
    app()->instance(\App\Contracts\ShopifyGraphqlGateway::class, new class implements \App\Contracts\ShopifyGraphqlGateway
    {
        public function graphql(string $query, array $variables = []): array
        {
            throw new RuntimeException('A disabled contract must not read Shopify for order recovery.');
        }
    });
    app(\App\Services\EngravedBeads\EngravedBeadMissedOrderRecovery::class)->recover();
});
