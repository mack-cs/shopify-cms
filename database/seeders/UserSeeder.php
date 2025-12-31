<?php

namespace Database\Seeders;

use App\Models\User;
use App\Enums\RolesEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Seed a default admin user (safe to rerun)
        $superAdmin=User::updateOrCreate(
            ['email' => 'shonayimack@mackscs.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('#Mack#36#LA#Dev#'), // change immediately in env / after seeding
            ]
        );
        $superAdmin->assignRole(RolesEnum::SuperAdmin->value);
        // Optional: seed a dev user for local testing
        $adminUser = User::updateOrCreate(
            ['email' => 'support@mackscs.com'],
            [
                'name' => 'Mack Shonayi',
                'password' => Hash::make('#Mack#36#LA#Dev#'),
            ]
        );
        $adminUser->assignRole(RolesEnum::Admin->value);

        $adminUser = User::updateOrCreate(
            ['email' => 'admin@leighavenue.co.za'],
            [
                'name' => 'Admin',
                'password' => Hash::make('#Mack#36#LA#Dev#'),
            ]
        );
        $adminUser->assignRole(RolesEnum::Admin->value);
    }
}
