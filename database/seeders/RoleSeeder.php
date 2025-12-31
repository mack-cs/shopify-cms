<?php

namespace Database\Seeders;

use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // ── Create Roles ──────────────────────────────
        $roles = [];
        foreach (RolesEnum::cases() as $role) {
            $roles[$role->name] = Role::firstOrCreate(['name' => $role->value]);
        }

        // ── Create Permissions ────────────────────────
        foreach (PermissionEnum::cases() as $permission) {
            Permission::firstOrCreate(['name' => $permission->value]);
        }

        $allPermissions = array_map(fn ($p) => $p->value, PermissionEnum::cases());

        // ── SuperAdmin → everything ────────────────────
        $roles['SuperAdmin']->syncPermissions($allPermissions);

        $roles['Admin']->syncPermissions([
            PermissionEnum::UserManage->value,

            PermissionEnum::ImportViewCurrent->value,
            PermissionEnum::ImportCreate->value, // optional (if admins can load new files)
            // NO: ImportViewAll
            // NO: ImportDelete

            PermissionEnum::ProductView->value,
            PermissionEnum::ProductCreate->value,
            PermissionEnum::ProductEdit->value,

            PermissionEnum::SeoReview->value,
            PermissionEnum::AuditViewLogs->value,

            PermissionEnum::ShopifyPushProducts->value,
        ]);
        // ── User (Editor) ─────────────────────────────
        $roles['User']->syncPermissions([
            PermissionEnum::ImportViewCurrent->value,
            PermissionEnum::ImportCreate->value,

            PermissionEnum::AuditViewLogs->value,
        ]);
      
    }
}
