<?php

use App\Enums\RolesEnum;
use App\Filament\Resources\NewProductDraftResource\Pages\ListNewProductDrafts;
use App\Models\NewProductDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('resets the drafts table back to page 1 after a draft is created', function (): void {
    $user = User::factory()->create();

    Role::findOrCreate(RolesEnum::Admin->value);
    $user->assignRole(RolesEnum::Admin->value);

    foreach (range(1, 30) as $index) {
        NewProductDraft::create([
            'title' => "Draft {$index}",
            'sku' => "SKU-{$index}",
            'status' => 'draft',
            'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
            'approval_version' => 1,
        ]);
    }

    $this->actingAs($user);

    $component = Livewire::test(ListNewProductDrafts::class)
        ->set('tableRecordsPerPage', 10);

    $pageName = $component->instance()->getTablePaginationPageName();

    $component->call('gotoPage', 2, $pageName);

    expect($component->instance()->getTablePage())->toBe(2);

    $component->call('handleDraftCreated');

    expect($component->instance()->getTablePage())->toBe(1);
});
