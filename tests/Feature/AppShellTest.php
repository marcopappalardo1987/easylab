<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('renders the app shell for an authenticated user', function () {
    $user = User::factory()->create(['name' => 'Mario Rossi']);
    $user->assignRole('Tenant');

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('Easy Lab')
        ->assertSee('Mario Rossi')
        ->assertSee(route('settings.security'))
        ->assertSee(route('logout'));
});

it('shows the impersonation banner while impersonating', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('Superadmin');
    $tenant = User::factory()->create(['name' => 'Cliente Impersonato']);
    $tenant->assignRole('Tenant');

    $this->actingAs($superadmin)->get(route('impersonate', $tenant));

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Stai impersonando')
        ->assertSee('Cliente Impersonato')
        ->assertSee(route('impersonate.leave'));
});

it('redirects guests away from the shell', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('no longer exposes the temporary TALL check page', function () {
    $this->get('/_tall-check')->assertNotFound();
});
