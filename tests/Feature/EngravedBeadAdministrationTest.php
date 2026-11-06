<?php

use App\Filament\Resources\EngravedBeadFamilyResource\Pages\ListEngravedBeadFamilies;
use App\Models\Product;
use App\Models\User;
use App\Services\EngravedBeads\EngravedBeadFamilyAdministration;
use App\Services\EngravedBeads\EngravedBeadProcurementContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/EngravedBeadFamilyFixture.php';
uses(RefreshDatabase::class);
beforeEach(fn () => config(['services.shopify.inventory_location_id' => null]));

it('requires authorization for threshold edits', function () {
    $f = sharedBeadFamily();
    expect(fn () => app(EngravedBeadFamilyAdministration::class)->threshold($f['family']->members->first(), 5, User::factory()->create()))
        ->toThrow(HttpException::class);
});

it('renders members and stock in the family administration page and audits threshold edits', function () {
    Permission::findOrCreate('ShopifyPushProducts', 'web');
    $actor = User::factory()->create();
    $actor->givePermissionTo('ShopifyPushProducts');
    $this->actingAs($actor);
    $f = sharedBeadFamily();
    Livewire::test(ListEngravedBeadFamilies::class)->assertSuccessful()->assertCanSeeTableRecords([$f['family']])
        ->mountTableAction('inspect', $f['family'])->assertSee('BEAD-50001');
    $member = $f['family']->members()->where('required_beads', 1)->first();
    app(EngravedBeadFamilyAdministration::class)->threshold($member, 5, $actor);
    $four = $f['family']->members()->where('required_beads', 4)->first();
    expect($member->fresh()->disable_threshold)->toBe(5)->and($f['family']->fresh()->recalculate_at)->not->toBeNull()
        ->and($f['family']->audits()->where('action', 'threshold_changed')->count())->toBe(1);
    expect(fn () => app(EngravedBeadFamilyAdministration::class)->threshold($four, 8, $actor))->toThrow(InvalidArgumentException::class);
});

it('never permits activation without store contract verification', function () {
    Permission::findOrCreate('ShopifyPushProducts', 'web');
    $actor = User::factory()->create();
    $actor->givePermissionTo('ShopifyPushProducts');
    $f = sharedBeadFamily();
    config(['engraved_beads.store_contract_verified' => false]);
    expect(fn () => app(EngravedBeadFamilyAdministration::class)->activate($f['family'], $actor))
        ->toThrow(RuntimeException::class, 'verification');
});

it('exports only the ordinary tracked forecasting source without family demand multipliers', function () {
    $f = sharedBeadFamily(active: true);
    $contract = app(EngravedBeadProcurementContract::class)->export();
    expect($contract['families'])->toHaveCount(1)
        ->and($contract['forecast_mode'])->toBe('ordinary_tracked_source')
        ->and($contract['families'][0]['source_variant_id'])->toBe($f['members'][1]['variant']->shopify_id)
        ->and(array_key_exists('members', $contract['families'][0]))->toBeFalse();
    expect(Product::query()->procurementCatalogEligible()->count())->toBe(1);
});
