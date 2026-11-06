<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('public registration is disabled for this staff CMS', function () {
    expect(\Laravel\Fortify\Features::enabled(\Laravel\Fortify\Features::registration()))->toBeFalse();
    expect(\Illuminate\Support\Facades\Route::has('register'))->toBeFalse();
    $this->get('/register')->assertNotFound();
});

test('anonymous users cannot create staff accounts through registration', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
});
