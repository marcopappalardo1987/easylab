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

it('forces a superadmin without 2FA to the security settings, on the platform too', function () {
    // Il ruolo di piattaforma che il secondo fattore lo deve avere ancora.
    $superadmin = User::factory()->create(['two_factor_confirmed_at' => null]);
    $superadmin->assignRole('Superadmin');

    $this->actingAs($superadmin)->get(route('piattaforma.index'))->assertRedirect(route('settings.security'));
});

it('lets the developer work without a second factor', function () {
    // 🔗 ADR-046 (6 Ott 2026): il Developer è l'account di debug e non è più
    // fra i ruoli col secondo fattore obbligatorio. Senza questa esenzione ogni
    // pagina lo rimandava alle impostazioni di sicurezza, cabina compresa — cioè
    // il posto da cui si impersona.
    $developer = User::factory()->create(['two_factor_confirmed_at' => null]);
    $developer->assignRole('Developer');

    // Senza Ente la dashboard lo manda alla cabina (ADR-046): conta che NON lo
    // mandi alle impostazioni di sicurezza, che è dove finiva prima.
    $this->actingAs($developer)->get('/dashboard')->assertRedirect(route('piattaforma.index'));
    $this->actingAs($developer)->get(route('piattaforma.index'))->assertOk();
    $this->actingAs($developer)->get(route('strumenti.index'))->assertOk();
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
