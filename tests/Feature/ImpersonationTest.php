<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets only users with utenti.impersonate impersonate', function () {
    $developer = User::factory()->create();
    $developer->assignRole('Developer');
    $superadmin = User::factory()->create();
    $superadmin->assignRole('Superadmin');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $tenant = User::factory()->create();
    $tenant->assignRole('Tenant');

    expect($developer->canImpersonate())->toBeTrue();
    expect($superadmin->canImpersonate())->toBeTrue();
    expect($admin->canImpersonate())->toBeFalse();
    expect($tenant->canImpersonate())->toBeFalse();
});

it('protects platform-privileged accounts from being impersonated', function () {
    $developer = User::factory()->create();
    $developer->assignRole('Developer');
    $superadmin = User::factory()->create();
    $superadmin->assignRole('Superadmin');
    $tenant = User::factory()->create();
    $tenant->assignRole('Tenant');

    expect($tenant->canBeImpersonated())->toBeTrue();
    expect($developer->canBeImpersonated())->toBeFalse();
    expect($superadmin->canBeImpersonated())->toBeFalse();
});

it('allows a Superadmin to take and leave impersonation', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('Superadmin');
    $tenant = User::factory()->create();
    $tenant->assignRole('Tenant');

    $this->actingAs($superadmin)->get(route('impersonate', $tenant))->assertRedirect('/');
    expect(app('impersonate')->isImpersonating())->toBeTrue();

    $this->get(route('impersonate.leave'))->assertRedirect('/');
    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

it('forbids impersonation for users without the permission', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $tenant = User::factory()->create();
    $tenant->assignRole('Tenant');

    $this->actingAs($admin)->get(route('impersonate', $tenant))->assertForbidden();
    expect(app('impersonate')->isImpersonating())->toBeFalse();
});
