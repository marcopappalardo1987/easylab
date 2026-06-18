<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('forces a privileged user without 2FA to the security settings', function () {
    $admin = User::factory()->create(['two_factor_confirmed_at' => null]);
    $admin->assignRole('Admin');

    $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('settings.security'));
});

it('still lets a privileged user reach the security settings to enable 2FA', function () {
    $admin = User::factory()->create(['two_factor_confirmed_at' => null]);
    $admin->assignRole('Admin');

    $this->actingAs($admin)->get(route('settings.security'))->assertOk();
});

it('does not block a non-privileged user without 2FA', function () {
    $tenant = User::factory()->create(['two_factor_confirmed_at' => null]);
    $tenant->assignRole('Tenant');

    $this->actingAs($tenant)->get('/dashboard')->assertOk();
});

it('lets a privileged user with confirmed 2FA through', function () {
    $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    $this->actingAs($admin)->get('/dashboard')->assertOk();
});
