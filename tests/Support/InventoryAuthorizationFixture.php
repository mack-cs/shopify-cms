<?php

use App\Enums\PermissionEnum;
use App\Models\User;
use Spatie\Permission\Models\Permission;

function inventoryAuthorizedUser(array $attributes = []): User
{
    $user = User::factory()->create(array_merge(['is_active' => true], $attributes));
    Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    $user->givePermissionTo(PermissionEnum::InventoryUpdate->value);

    return $user;
}
