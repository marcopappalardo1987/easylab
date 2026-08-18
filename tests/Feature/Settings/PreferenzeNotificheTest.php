<?php

use App\Livewire\Settings\PreferenzeNotifiche;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Le preferenze di notifica (🔗 ADR-011): il diritto di opposizione del registro
 * dei trattamenti (T4) con un posto dove esercitarlo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $this->utente = User::factory()->create(['tenant_id' => $ente->id]);
    $this->utente->assignRole('Tenant');
});

it('starts with the digest enabled, because the reminder is the service', function () {
    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->assertSet('riceveEmailScadenze', true);
});

it('persists the opt-out', function () {
    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->set('riceveEmailScadenze', false)
        ->call('salva');

    expect($this->utente->fresh()->riceve_email_scadenze)->toBeFalse();
});

it('lets the user opt back in', function () {
    $this->utente->forceFill(['riceve_email_scadenze' => false])->save();
    $this->actingAs($this->utente);

    Livewire::test(PreferenzeNotifiche::class)
        ->assertSet('riceveEmailScadenze', false)
        ->set('riceveEmailScadenze', true)
        ->call('salva');

    expect($this->utente->fresh()->riceve_email_scadenze)->toBeTrue();
});

it('is reachable by any authenticated user, without a permission', function () {
    // Nessun `can:` sulla rotta: è la propria casella di posta, non un dato
    // dell'Ente. Il Tenant è il ruolo con meno permessi di tutti ed è
    // esattamente per questo che è lui a provarci.
    $this->actingAs($this->utente)
        ->get(route('settings.notifiche'))
        ->assertSuccessful();
});

it('never lets the preference be forged by mass assignment', function () {
    // La colonna sta fuori dall'attributo Fillable: senza quell'esclusione un
    // form dell'anagrafica potrebbe spegnere le email di un altro utente.
    $this->utente->fill(['riceve_email_scadenze' => false]);

    expect($this->utente->riceve_email_scadenze)->toBeTrue();
});

it('requires authentication', function () {
    $this->get(route('settings.notifiche'))->assertRedirect(route('login'));
});
