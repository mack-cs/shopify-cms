<?php

use App\Models\DropdownOption;
use App\Models\PrepopulationRule;
use App\Services\HeaderStore;
use App\Services\PrepopulationRuleImportService;
use App\Services\PrepopulationRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports and applies collection prepopulation rules idempotently', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'prepopulation-rules-');
    $handle = fopen($path, 'w');
    fputcsv($handle, [
        'Behavior',
        'Trigger Rule',
        'Collection Name',
        'Handle',
        'Layer',
        'Where It Appears',
        'Parents / Depends On',
        'Full Auto Tags - ADD',
        'Remove Tags',
        'Auto Vendor',
        'Auto Type',
        'Auto CMS Collection',
        'Auto Product Category',
        'Auto Google Product Category',
        'Auto Design (apply when trigger fires)',
        'Auto Colour Style (apply when trigger fires)',
        'Auto Jewelry Type',
        'Auto Target Gender',
        'Auto Age Group',
        'Auto Status',
        'Flag',
        'Notes',
        'Source Refs',
    ]);
    fputcsv($handle, [
        'AUTO_ON_COLLECTION_SELECTION',
        'AUTO',
        'Livi Road Bracelets',
        'livi-road-bracelets',
        'Main type',
        'Main nav',
        'Bracelets',
        'all-products, livi-road, bracelets, livi-road-bracelets, exclude-from-the-sale',
        'exclude-from-the-sale',
        'Livi Road',
        'Bracelets',
        'Livi Road Bracelets',
        'Apparel & Accessories > Jewelry > Bracelets',
        '191',
        'beaded',
        'solid',
        'handcrafted-jewellery',
        'unisex',
        'universal',
        'draft',
        'OK',
        '',
        'Test!1',
    ]);
    fclose($handle);

    expect(app(PrepopulationRuleImportService::class)->import($path))->toBe(1);
    @unlink($path);

    DropdownOption::query()->create([
        'header' => HeaderStore::PATTERN_CATEGORY,
        'value' => 'Existing value',
        'collection_style' => 'Existing Collection',
        'active' => true,
    ]);

    $service = app(PrepopulationRuleService::class);
    $rule = $service->ruleForCollection('Livi Road Bracelets');
    $first = $service->applyRule($rule, ['manual-tag', 'exclude-from-the-sale'], 'Bracelets');
    $second = $service->applyRule($rule, $first['tags'], 'Bracelets');

    expect($first['vendor'])->toBe('Livi Road')
        ->and($first['type'])->toBe('Bracelets')
        ->and($first['product_design'])->toBe('beaded')
        ->and($first['colour_style'])->toBe('solid')
        ->and($first['tags'])->toContain('livi-road-bracelets')
        ->and($first['tags'])->not->toContain('exclude-from-the-sale')
        ->and($second['tags'])->toBe($first['tags'])
        ->and(DropdownOption::query()->where('header', HeaderStore::PATTERN_CATEGORY)->where('value', 'Existing value')->exists())->toBeTrue()
        ->and(DropdownOption::query()->where('header', HeaderStore::PATTERN_CATEGORY)->get()
            ->contains(fn (DropdownOption $option): bool => DropdownOption::canonicalValue(HeaderStore::PATTERN_CATEGORY, $option->value) === 'solid'))->toBeTrue()
        ->and(DropdownOption::query()->where('header', HeaderStore::BRACELET_DESIGN)->get()
            ->contains(fn (DropdownOption $option): bool => DropdownOption::canonicalValue(HeaderStore::BRACELET_DESIGN, $option->value) === 'beaded'))->toBeTrue();
});

it('keeps shop your vibe as an explicit rule and applies it only by handle', function (): void {
    PrepopulationRule::query()->create([
        'behavior' => PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION,
        'handle' => 'bracelets',
        'collection_name' => 'Bracelets',
        'add_tags' => ['bracelets'],
        'remove_tags' => [],
    ]);
    PrepopulationRule::query()->create([
        'behavior' => PrepopulationRule::BEHAVIOR_SHOP_YOUR_VIBE_DROPDOWN,
        'handle' => 'bracelets-bold-colours',
        'collection_name' => 'BOLD BRACELETS',
        'add_tags' => ['bold-colours-bracelets'],
        'remove_tags' => [],
        'auto_design' => 'beaded',
        'auto_colour_style' => 'multicolour',
        'auto_type' => 'Bracelets',
    ]);

    $service = app(PrepopulationRuleService::class);
    $base = $service->applyRule($service->ruleForCollection('Bracelets'), [], 'Bracelets');

    expect($base['tags'])->toBe(['bracelets'])
        ->and($base)->not->toHaveKey('product_design')
        ->and($base)->not->toHaveKey('colour_style');

    $vibe = $service->applyRule($service->shopYourVibeRuleForHandle('bracelets-bold-colours'), $base['tags'], 'Bracelets');

    expect($vibe['tags'])->toContain('bold-colours-bracelets')
        ->and($vibe['product_design'])->toBe('beaded')
        ->and($vibe['colour_style'])->toBe('multicolour');
});

it('removes previous collection architecture tags before applying a newly selected collection', function (): void {
    PrepopulationRule::query()->create([
        'behavior' => PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION,
        'handle' => 'livi-road-bracelets',
        'collection_name' => 'Livi Road Bracelets',
        'add_tags' => ['all-products', 'livi-road', 'bracelets', 'livi-road-bracelets'],
        'remove_tags' => [],
        'auto_vendor' => 'Livi Road',
    ]);
    $elevated = PrepopulationRule::query()->create([
        'behavior' => PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION,
        'handle' => 'elevated-basics-bundles',
        'collection_name' => 'Elevated Basics Bundles',
        'add_tags' => ['all-products', 'elevated-basics', 'bundles', 'elevated-basics-bundles'],
        'remove_tags' => [],
        'auto_vendor' => 'Elevated Basics Bundles',
    ]);

    $service = app(PrepopulationRuleService::class);
    $updates = $service->applyCollectionRuleReplacingManagedTags($elevated, [
        'manual-tag',
        'all-products',
        'livi-road',
        'bracelets',
        'livi-road-bracelets',
        'new-in',
    ]);

    expect($updates['tags'])->toContain('manual-tag')
        ->and($updates['tags'])->toContain('new-in')
        ->and($updates['tags'])->toContain('elevated-basics')
        ->and($updates['tags'])->toContain('elevated-basics-bundles')
        ->and($updates['tags'])->not->toContain('livi-road')
        ->and($updates['tags'])->not->toContain('livi-road-bracelets')
        ->and($updates['tags'])->not->toContain('bracelets');
});
