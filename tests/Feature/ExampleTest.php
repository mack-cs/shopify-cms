<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns a successful response', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('filament.admin.auth.login'));
});
