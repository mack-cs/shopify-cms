<?php

use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('allows a super admin to see and demote another super admin', function (): void {
    $permission = Permission::findOrCreate(PermissionEnum::UserManage->value);
    $superRole = Role::findOrCreate(RolesEnum::SuperAdmin->value);
    $adminRole = Role::findOrCreate(RolesEnum::Admin->value);

    $actor = User::factory()->create();
    $actor->assignRole($superRole);
    $actor->givePermissionTo($permission);

    $target = User::factory()->create(['is_active' => true]);
    $target->assignRole($superRole);

    $this->actingAs($actor);

    expect(UserResource::getEloquentQuery()->pluck('users.id')->all())
        ->toContain($target->id)
        ->and(UserResource::canEdit($target))->toBeTrue();

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm([
            'name' => $target->name,
            'email' => $target->email,
            'roles' => [$adminRole->id],
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole(RolesEnum::SuperAdmin->value))->toBeFalse()
        ->and($target->fresh()->hasRole(RolesEnum::Admin->value))->toBeTrue();
});

it('continues hiding super admins from non-super administrators', function (): void {
    $permission = Permission::findOrCreate(PermissionEnum::UserManage->value);
    $superRole = Role::findOrCreate(RolesEnum::SuperAdmin->value);
    $adminRole = Role::findOrCreate(RolesEnum::Admin->value);

    $actor = User::factory()->create();
    $actor->assignRole($adminRole);
    $actor->givePermissionTo($permission);

    $target = User::factory()->create();
    $target->assignRole($superRole);

    $this->actingAs($actor);

    expect(UserResource::getEloquentQuery()->pluck('users.id')->all())
        ->not->toContain($target->id)
        ->and(UserResource::canEdit($target))->toBeFalse();
});
