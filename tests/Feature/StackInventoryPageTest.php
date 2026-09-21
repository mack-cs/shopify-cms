<?php

use App\Filament\Resources\StackInventoryResource\Pages\ListStackInventories;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\StackInventoryAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows stack component available and on hand inventory', function (): void {
    $user = User::factory()->create();
    $import = Import::query()->create([
        'filename' => 'stack-inventory-page.csv',
        'mode' => 'append',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);

    $component = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'title' => 'Gold Bracelet',
        'handle' => 'gold-bracelet',
        'status' => 'active',
    ]));
    Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $component->id,
        'sku' => 'BRACELET-001',
        'inventory_tracked' => true,
        'inventory_qty' => 8,
        'current_available_quantity' => 8,
        'current_on_hand_quantity' => 11,
    ]));
    $stack = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::query()->create([
        'title' => 'Golden Stack',
        'sku' => 'STACK-001',
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
        'bundle_component_quantities' => [['product_id' => $component->id, 'quantity' => 2]],
    ]));

    $this->actingAs($user);

    Livewire::test(ListStackInventories::class)
        ->assertCanSeeTableRecords([$stack])
        ->assertSee('Golden Stack')
        ->assertSee('STACK-001')
        ->assertSee('Gold Bracelet')
        ->assertSee('BRACELET-001')
        ->assertSee('Ready')
        ->assertSee('11');

    $page = Livewire::test(ListStackInventories::class)
        ->assertActionExists('exportCsv')
        ->searchTable('Golden Stack');
    $response = $page->instance()->exportCsv();
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();
    $csv = \League\Csv\Reader::createFromString($content);
    $csv->setHeaderOffset(0);
    $rows = iterator_to_array($csv->getRecords());

    expect($rows)->toHaveCount(1);
    $row = array_values($rows)[0];
    expect($row['Stack SKU'])->toBe('STACK-001')
        ->and($row['Stack Status'])->toBe('Ready')
        ->and($row['Component SKU'])->toBe('BRACELET-001')
        ->and($row['Quantity Per Stack'])->toBe('2')
        ->and($row['Available'])->toBe('8')
        ->and($row['On Hand'])->toBe('11');

    $page->searchTable('No matching stack');
    ob_start();
    $page->instance()->exportCsv()->sendContent();
    $emptyCsv = \League\Csv\Reader::createFromString(ob_get_clean());
    $emptyCsv->setHeaderOffset(0);
    expect(iterator_to_array($emptyCsv->getRecords()))->toBe([]);
});

it('explains which component makes a stack out of stock', function (): void {
    $user = User::factory()->create();
    $import = Import::query()->create([
        'filename' => 'stack-inventory-warning.csv',
        'mode' => 'append',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
    $component = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'title' => 'Low Stock Bracelet',
        'handle' => 'low-stock-bracelet',
        'status' => 'active',
    ]));
    Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $component->id,
        'sku' => 'LOW-001',
        'inventory_tracked' => true,
        'inventory_qty' => 1,
        'current_available_quantity' => 1,
        'current_on_hand_quantity' => 4,
    ]));
    $stack = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::query()->create([
        'title' => 'Two Bracelet Stack',
        'sku' => 'STACK-LOW',
        'bundle_product_ids' => [$component->id],
        'bundle_component_quantities' => [['product_id' => $component->id, 'quantity' => 2]],
    ]));

    $health = app(StackInventoryAuditService::class)->health($stack);

    expect($health['status'])->toBe('Out of stock')
        ->and($health['reason'])->toContain('Component 1: Low Stock Bracelet')
        ->and($health['reason'])->toContain('Needs 2 per stack; 1 available.');
});
