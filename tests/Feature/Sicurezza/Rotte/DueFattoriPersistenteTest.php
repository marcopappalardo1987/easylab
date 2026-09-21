<?php

use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * ORC-1 — che cosa succede su `/livewire/update` se `EnsureTwoFactorIsEnabled`
 * diventa middleware PERSISTENTE di Livewire (security pass S7, T1b — 🔗 ADR-016
 * `two_factor_required_roles`, ADR-013 per il precedente del lockout).
 *
 * Il blocco in sé lo prova già il cacciatore (`Caccia/T1aA/SecondoFattoreSugliUpdateTest`).
 * Qui si prova che il fix è SICURO, cioè che non chiude fuori chi non deve:
 * la pagina di attivazione del 2FA, l'impersonazione, i ruoli senza obbligo.
 *
 * La registrazione è quella VERA di AppServiceProvider (nessuna simulazione):
 * togliere quella riga fa diventare rosso il primo test.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Laboratorio Rossi']);
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Microbiologia']);
    $this->strumento = Strumento::factory()->forNode($dip)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->aggiorna = fn (string $snapshot, string $metodo) => $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['method' => $metodo, 'params' => [], 'path' => '']],
            ]],
        ]);
});

it('answers a blocked update with the redirect to the security page, not with a 500', function () {
    $this->actingAs($this->admin);
    $snapshot = snapshotDa($this->get(route('strumenti.show', $this->strumento))->getContent(), 'strumenti.scheda-strumento');
    expect($snapshot)->not->toBe('');

    $this->admin->forceFill(['two_factor_confirmed_at' => null])->save();

    ($this->aggiorna)($snapshot, 'delete')->assertRedirect(route('settings.security'));

    expect(Strumento::withoutGlobalScopes()->findOrFail($this->strumento->id)->trashed())->toBeFalse();
});

it('still lets a user who must configure 2FA do it from the security page', function () {
    // La trappola che ORC-1 chiedeva di escludere: la pagina di attivazione è
    // essa stessa un componente Livewire. L'esenzione `routeIs('settings.security')`
    // funziona anche sull'update perché Livewire risolve la rotta ORIGINALE sulla
    // richiesta finta (`PersistentMiddleware::getRouteFromRequest`).
    $senza2fa = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => null]);
    $senza2fa->assignRole('Admin');
    $this->actingAs($senza2fa)->withSession(['auth.password_confirmed_at' => time()]);

    $snapshot = snapshotDa($this->get(route('settings.security'))->assertOk()->getContent(), 'settings.two-factor-authentication');
    expect($snapshot)->not->toBe('');

    ($this->aggiorna)($snapshot, 'enable')->assertOk();

    expect($senza2fa->fresh()->two_factor_secret)->not->toBeNull();
});

it('never blocks an impersonator acting through the page of a client without 2FA', function () {
    $clienteSenza2fa = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => null]);
    $clienteSenza2fa->assignRole('Admin');
    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    $this->actingAs($superadmin)->get(route('impersonate', $clienteSenza2fa))->assertRedirect();
    $snapshot = snapshotDa($this->get(route('strumenti.show', $this->strumento))->assertOk()->getContent(), 'strumenti.scheda-strumento');

    ($this->aggiorna)($snapshot, 'delete')->assertOk();

    expect(Strumento::withoutGlobalScopes()->findOrFail($this->strumento->id)->trashed())->toBeTrue();
});

it('never blocks a role that has no 2FA obligation', function () {
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => null]);
    $tecnico->assignRole('Tecnico');
    $this->actingAs($tecnico);

    $snapshot = snapshotDa($this->get(route('campo.index'))->assertOk()->getContent(), 'campo.home');
    expect($snapshot)->not->toBe('');

    ($this->aggiorna)($snapshot, '$refresh')->assertOk();
});
