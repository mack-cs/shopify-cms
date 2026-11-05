<?php

namespace App\Services;

use App\Enums\PermissionEnum;
use Illuminate\Contracts\Auth\Authenticatable;

final class InventoryAccessService
{
    public function canAccess(?Authenticatable $user): bool
    {
        return $this->canUpdateInventory($user) || $this->canUpdateStatus($user);
    }

    public function canUpdateInventory(?Authenticatable $user): bool
    {
        return $user !== null && (bool) $user->is_active && $user->can(PermissionEnum::InventoryUpdate->value);
    }

    public function authorizeInventory(?int $userId): void
    {
        abort_unless($this->canUpdateInventory(\App\Models\User::find($userId)), 403, 'Active inventory adjustment permission is required.');
    }

    public function canUpdateStatus(?Authenticatable $user): bool
    {
        return $user?->can(PermissionEnum::InventoryStatusUpdate->value) ?? false;
    }
}
