<?php

use App\Enums\RolesEnum;
use App\Filament\Resources\DropdownOptionResource\Pages\ListDropdownOptions;
use App\Models\DropdownOption;
use App\Models\User;
use App\Services\DropdownReviewWorkbookExporter;
use App\Services\HeaderStore;
use App\Services\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('surfaces active review dropdowns for materials colors and target collections', function (): void {
    Role::findOrCreate(RolesEnum::SuperAdmin->value);
    $user = User::factory()->create();
    $user->assignRole(RolesEnum::SuperAdmin->value);

    $wanted = DropdownOption::withoutEvents(fn (): DropdownOption => DropdownOption::query()->create([
        'header' => HeaderStore::MATERIALS_AND_DIMENSIONS,
        'value' => 'Japanese Miyuki beads',
        'collection_style' => 'Livi Road Bundles',
        'collection_tag_primary' => 'livi-road',
        'collection_tag_secondary' => 'livi-road-bundles',
        'active' => true,
    ]));
    $inactive = DropdownOption::withoutEvents(fn (): DropdownOption => DropdownOption::query()->create([
        'header' => HeaderStore::COLOR_METAFIELD,
        'value' => 'retired-blue',
        'collection_style' => 'Elevated Basics Bracelets',
        'active' => false,
    ]));
    $wrongCollection = DropdownOption::withoutEvents(fn (): DropdownOption => DropdownOption::query()->create([
        'header' => HeaderStore::JEWELRY_MATERIAL,
        'value' => 'Resin',
        'collection_style' => 'Pata Pata Bundles',
        'active' => true,
    ]));

    $this->actingAs($user);

    Livewire::test(ListDropdownOptions::class)
        ->assertTableFilterVisible('materials_colors_review')
        ->assertTableFilterVisible('livi_elevated_stacks_review')
        ->assertCanSeeTableRecords([$wanted, $wrongCollection])
        ->assertCanNotSeeTableRecords([$inactive])
        ->filterTable('materials_colors_review')
        ->filterTable('livi_elevated_stacks_review')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$inactive, $wrongCollection])
        ->assertTableBulkActionVisible('export')
        ->assertTableBulkActionVisible('delete');
});

it('exports a review workbook with a sheet per collection and dropdown headers as columns', function (): void {
    if (! class_exists(\ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive is required to build XLSX exports.');
    }

    DropdownOption::withoutEvents(fn (): DropdownOption => DropdownOption::query()->create([
        'header' => HeaderStore::COLOR_METAFIELD,
        'value' => 'black',
        'collection_style' => 'Livi Road Bracelets',
        'active' => true,
    ]));
    DropdownOption::withoutEvents(fn (): DropdownOption => DropdownOption::query()->create([
        'header' => HeaderStore::JEWELRY_MATERIAL,
        'value' => 'Sterling Silver',
        'collection_style' => 'Livi Road Bracelets',
        'active' => true,
    ]));
    DropdownOption::withoutEvents(fn (): DropdownOption => DropdownOption::query()->create([
        'header' => HeaderStore::MATERIALS_AND_DIMENSIONS,
        'value' => '10mm',
        'collection_style' => 'Livi Road Bracelets',
        'active' => true,
    ]));

    $contents = app(DropdownReviewWorkbookExporter::class)->export(
        \App\Filament\Resources\DropdownOptionResource::reviewHeaders(),
        \App\Filament\Resources\DropdownOptionResource::reviewCollections(),
    );
    $path = tempnam(sys_get_temp_dir(), 'dropdown-review-test-');
    file_put_contents($path, $contents);

    $zip = new ZipArchive();
    $zip->open($path);
    $workbook = $zip->getFromName('xl/workbook.xml');
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($path);

    expect($contents)->toStartWith('PK')
        ->and($workbook)->toContain('Livi Road Bracelets')
        ->and($sheet)->toContain('Color')
        ->and($sheet)->toContain('Jewelry Material')
        ->and($sheet)->toContain('Materials and Dimensions')
        ->and($sheet)->toContain('black')
        ->and($sheet)->toContain('sterling-silver')
        ->and($sheet)->toContain('10mm');
});

it('conditionally deactivates review values missing from an imported workbook', function (): void {
    if (! class_exists(\ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive is required to build XLSX exports.');
    }

    DropdownOption::withoutEvents(fn (): DropdownOption => DropdownOption::query()->create([
        'header' => HeaderStore::COLOR_METAFIELD,
        'value' => 'black',
        'collection_style' => 'Livi Road Bracelets',
        'active' => true,
    ]));

    $contents = app(DropdownReviewWorkbookExporter::class)->export(
        \App\Filament\Resources\DropdownOptionResource::reviewHeaders(),
        \App\Filament\Resources\DropdownOptionResource::reviewCollections(),
    );

    $extra = DropdownOption::withoutEvents(fn (): DropdownOption => DropdownOption::query()->create([
        'header' => HeaderStore::COLOR_METAFIELD,
        'value' => 'rainbow',
        'collection_style' => 'Livi Road Bracelets',
        'active' => true,
    ]));

    $path = tempnam(sys_get_temp_dir(), 'dropdown-review-import-');
    file_put_contents($path, $contents);

    app(\App\Services\DropdownReviewWorkbookImporter::class)->import(
        $path,
        \App\Filament\Resources\DropdownOptionResource::reviewHeaders(),
        \App\Filament\Resources\DropdownOptionResource::reviewCollections(),
        false,
    );
    expect($extra->fresh()->active)->toBeTrue();

    app(\App\Services\DropdownReviewWorkbookImporter::class)->import(
        $path,
        \App\Filament\Resources\DropdownOptionResource::reviewHeaders(),
        \App\Filament\Resources\DropdownOptionResource::reviewCollections(),
        true,
    );
    @unlink($path);

    expect($extra->fresh()->active)->toBeFalse();
});

it('stores imported jewelry material labels as Shopify handles', function (): void {
    if (! class_exists(\ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive is required to build XLSX exports.');
    }

    DropdownOption::withoutEvents(fn (): DropdownOption => DropdownOption::query()->create([
        'header' => HeaderStore::JEWELRY_MATERIAL,
        'value' => 'Gold',
        'collection_style' => 'Livi Road Bracelets',
        'active' => true,
    ]));

    $contents = app(DropdownReviewWorkbookExporter::class)->export(
        \App\Filament\Resources\DropdownOptionResource::reviewHeaders(),
        \App\Filament\Resources\DropdownOptionResource::reviewCollections(),
    );
    $path = tempnam(sys_get_temp_dir(), 'dropdown-review-handle-import-');
    file_put_contents($path, $contents);

    app(\App\Services\DropdownReviewWorkbookImporter::class)->import(
        $path,
        \App\Filament\Resources\DropdownOptionResource::reviewHeaders(),
        \App\Filament\Resources\DropdownOptionResource::reviewCollections(),
        false,
    );
    @unlink($path);

    expect(DropdownOption::query()
        ->where('header', HeaderStore::JEWELRY_MATERIAL)
        ->where('collection_style', 'Livi Road Bracelets')
        ->where('value', 'gold')
        ->exists())->toBeTrue();
});

it('normalizes captured jewelry material labels to Shopify handles', function (): void {
    $normalizer = app(Normalizer::class);
    $method = new ReflectionMethod($normalizer, 'parseDropdownValues');

    expect($method->invoke($normalizer, HeaderStore::JEWELRY_MATERIAL, 'Sterling Silver'))
        ->toBe(['sterling-silver'])
        ->and($method->invoke($normalizer, HeaderStore::JEWELRY_MATERIAL, 'Gold'))
        ->toBe(['gold'])
        ->and($method->invoke($normalizer, HeaderStore::JEWELRY_MATERIAL, 'Fresh Water Pearls; Japanese Miyuki Beads; natural-stones'))
        ->toBe(['fresh-water-pearls', 'japanese-miyuki-beads', 'natural-stones']);
});
