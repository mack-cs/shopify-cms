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
        return $user?->can(PermissionEnum::InventoryUpdate->value) ?? false;
    }

    public function canUpdateStatus(?Authenticatable $user): bool
    {
        return $user?->can(PermissionEnum::InventoryStatusUpdate->value) ?? false;
    }
}
