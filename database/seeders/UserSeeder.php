<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Seed a default admin user (safe to rerun)
        User::updateOrCreate(
            ['email' => 'admin@leighavenue.co.za'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'), // change immediately in env / after seeding
            ]
        );

        // Optional: seed a dev user for local testing
        User::updateOrCreate(
            ['email' => 'shonayimack@mackscs.com'],
            [
                'name' => 'Developer',
                'password' => Hash::make('#Mack#36#LA#Dev#'),
            ]
        );
    }
}
