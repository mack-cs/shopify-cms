<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get(route('filament.admin.pages.dashboard'))->assertRedirect(route('filament.admin.auth.login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->create([
        'email' => 'dashboard-test@leighavenue.co.za',
        'is_active' => true,
    ]));

    $this->get(route('filament.admin.pages.dashboard'))->assertOk();
});
