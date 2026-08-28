<?php

use App\Livewire\Settings\TwoFactorAuthentication;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

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

it('protects only the Developer from being impersonated', function () {
    $developer = User::factory()->create();
    $developer->assignRole('Developer');
    $superadmin = User::factory()->create();
    $superadmin->assignRole('Superadmin');
    $tenant = User::factory()->create();
    $tenant->assignRole('Tenant');

    expect($developer->canBeImpersonated())->toBeFalse();
    // Il Superadmin è impersonabile (dal Developer); il proprietario di
    // piattaforma non è un account intoccabile come il Developer.
    expect($superadmin->canBeImpersonated())->toBeTrue();
    expect($tenant->canBeImpersonated())->toBeTrue();
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

// --- Il 2FA e l'impersonazione, che si pestavano i piedi ---
//
// 🔴 Segnalato da Marco il 28 Ago 2026: impersonando un Admin che il 2FA non
// l'ha ancora attivato, il Developer finiva sulla pagina di sicurezza **di quel
// cliente** e non poteva andare da nessun'altra parte. L'impersonazione, che
// esiste per guardare l'applicazione con gli occhi del cliente, era un vicolo
// cieco.
//
// ⛔ Ma il guasto peggiore non era il blocco: era il PULSANTE. Su quella pagina
// l'impersonatore poteva premere «Abilita 2FA» e legare il secondo fattore del
// cliente alla propria app di autenticazione — e il registro di audit avrebbe
// detto che l'aveva attivato il cliente. Un'impersonazione deve poter
// GUARDARE, non acquisire le credenziali di chi si impersona.

it('does not force the impersonated user to set up 2FA', function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create();

    $developer = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');

    // L'Admin del cliente: ruolo che il 2FA lo esige, e non l'ha attivato.
    $admin = User::factory()->create([
        'tenant_id' => $ente->id,
        'two_factor_confirmed_at' => null,
    ]);
    $admin->assignRole('Admin');

    $this->actingAs($developer)->get(route('impersonate', $admin))->assertRedirect();

    // Da impersonato, la dashboard si apre: niente rimbalzo su /settings/security.
    $this->get(route('dashboard'))->assertOk();
});

it('refuses to let an impersonator touch the 2FA of the person they are impersonating', function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create();

    $developer = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');

    $admin = User::factory()->create([
        'tenant_id' => $ente->id,
        'two_factor_confirmed_at' => null,
    ]);
    $admin->assignRole('Admin');

    $this->actingAs($developer)->get(route('impersonate', $admin));

    // ⛔ Le quattro azioni, non solo il bottone in pagina: le property di un
    // componente Livewire sono pubbliche e i metodi si chiamano da `$wire`.
    foreach (['enable', 'confirm', 'regenerateRecoveryCodes', 'disable'] as $azione) {
        Livewire::test(TwoFactorAuthentication::class)->call($azione)->assertForbidden();
    }

    // E il secondo fattore del cliente non è stato toccato.
    expect($admin->fresh()->two_factor_secret)->toBeNull();
});
