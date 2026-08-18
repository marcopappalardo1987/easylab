<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * La pagina /bloccato e la fuga (🔗 ADR-013). Setup: un utente membro di DUE
 * account — uno bloccato (dov'è il suo tenant) e uno sano — più un terzo
 * account bloccato per il negativo: la fuga offre e concede SOLO le sedi sane.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->bloccato = Account::factory()->bloccato()->create(['ragione_sociale' => 'Gruppo Moroso']);
    $this->sedeBloccata = UnitaOrganizzativa::factory()->ente()->perAccount($this->bloccato)->create(['nome' => 'Sede Morosa']);

    $this->sano = Account::factory()->create();
    $this->sedeSana = UnitaOrganizzativa::factory()->ente()->perAccount($this->sano)->create(['nome' => 'Sede Sana']);

    $this->altroBloccato = Account::factory()->bloccato()->create();
    $this->sedeAltroBloccato = UnitaOrganizzativa::factory()->ente()->perAccount($this->altroBloccato)->create(['nome' => 'Sede Terza']);

    $this->utente = User::factory()->create(['tenant_id' => $this->sedeBloccata->id]);
    $this->utente->assignRole('Tenant');
    $this->bloccato->aggiungiMembro($this->utente);
    $this->sano->aggiungiMembro($this->utente);
    $this->altroBloccato->aggiungiMembro($this->utente);
});

it('shows the page with logout and only the healthy sedi', function () {
    $this->actingAs($this->utente)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertSee('Accesso sospeso')
        ->assertSee('Sede Morosa')
        ->assertSee(route('logout'))
        ->assertSee('Sede Sana')
        ->assertDontSee('Sede Terza');
});

it('never shows the locked_reason to the locked user', function () {
    // La ragione è un'annotazione operativa interna (solleciti, riferimenti):
    // il suo destinatario è la dashboard S6, non il moroso.
    $this->bloccato->sblocca();
    $this->bloccato->blocca('Sollecito n.3 — pratica legale 42/2026');

    $this->actingAs($this->utente)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertDontSee('Sollecito')
        ->assertDontSee('42/2026');
});

it('redirects a non-locked user away from the page', function () {
    $altro = User::factory()->create(['tenant_id' => $this->sedeSana->id]);
    $altro->assignRole('Tenant');

    $this->actingAs($altro)
        ->get(route('bloccato'))
        ->assertRedirect(route('dashboard'));
});

it('lets the user escape to a healthy sede', function () {
    $this->actingAs($this->utente)
        ->post(route('bloccato.passa', $this->sedeSana->id))
        ->assertRedirect(route('dashboard'));

    expect($this->utente->fresh()->tenant_id)->toBe($this->sedeSana->id)
        ->and(Activity::inLog(AuditLog::NAME)->where('description', 'Ente attivo cambiato')->count())->toBe(1);
});

it('refuses the escape towards a sede of another locked account', function () {
    $this->actingAs($this->utente)
        ->post(route('bloccato.passa', $this->sedeAltroBloccato->id))
        ->assertRedirect(route('bloccato'));

    expect($this->utente->fresh()->tenant_id)->toBe($this->sedeBloccata->id)
        ->and(Activity::inLog(AuditLog::NAME)->where('description', 'Ente attivo cambiato')->count())->toBe(0);
});

it('fails closed on a missing ente', function () {
    $this->actingAs($this->utente)
        ->post(route('bloccato.passa', 99999))
        ->assertRedirect(route('bloccato'));

    expect($this->utente->fresh()->tenant_id)->toBe($this->sedeBloccata->id);
});

it('requires authentication', function () {
    $this->get(route('bloccato'))->assertRedirect(route('login'));
});
