<?php

use App\Enums\StatoSemaforo;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Forzatura manuale del semaforo (S3 punto 5, ADR-005): il forzato vince sul
 * calcolato, è tracciato e finisce nell'audit log.
 * Aree rosse (Policy di Code Review): permessi e isolamento sulla scrittura.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

// --- Forzatura dalla modale ---

it('forces each stato from the modal', function () {
    foreach ([StatoSemaforo::Verde, StatoSemaforo::Arancione, StatoSemaforo::Rosso] as $stato) {
        scheda($this->admin, $this->strumento)
            ->call('openForza')
            ->set('forzaForm.stato', $stato->value)
            ->set('forzaForm.motivo', 'Verifica sul campo')
            ->call('forza')
            ->assertHasNoErrors()
            ->assertSet('showForzaForm', false);

        expect($this->strumento->fresh()->forced_state)->toBe($stato)
            ->and($this->strumento->fresh()->statoSemaforoEffettivo())->toBe($stato);
    }
});

it('defaults the modal to rosso, the primary use case of the ADR', function () {
    scheda($this->admin, $this->strumento)
        ->call('openForza')
        ->assertSet('forzaForm.stato', StatoSemaforo::Rosso->value);
});

it('reopens the modal on the state already forced', function () {
    $this->actingAs($this->admin);
    $this->strumento->forzaSemaforo(StatoSemaforo::Arancione, 'Da rivedere');

    scheda($this->admin, $this->strumento->fresh())
        ->call('openForza')
        ->assertSet('forzaForm.stato', StatoSemaforo::Arancione->value)
        ->assertSet('forzaForm.motivo', 'Da rivedere');
});

// --- Motivo obbligatorio solo sul rosso ---

it('rejects rosso without a motivo, from the form', function () {
    scheda($this->admin, $this->strumento)
        ->call('openForza')
        ->set('forzaForm.stato', StatoSemaforo::Rosso->value)
        ->set('forzaForm.motivo', '')
        ->call('forza')
        ->assertHasErrors(['forzaForm.motivo' => 'required']);

    expect($this->strumento->fresh()->forced_state)->toBeNull();
});

it('rejects rosso without a motivo, from the model itself', function () {
    // La guardia non dipende dal form: vale per ogni chiamante futuro.
    $this->actingAs($this->admin);

    expect(fn () => $this->strumento->forzaSemaforo(StatoSemaforo::Rosso, null))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->strumento->forzaSemaforo(StatoSemaforo::Rosso, '   '))
        ->toThrow(InvalidArgumentException::class);

    expect($this->strumento->fresh()->forced_state)->toBeNull();
});

it('allows verde and arancione without a motivo', function () {
    foreach ([StatoSemaforo::Verde, StatoSemaforo::Arancione] as $stato) {
        scheda($this->admin, $this->strumento)
            ->call('openForza')
            ->set('forzaForm.stato', $stato->value)
            ->set('forzaForm.motivo', '')
            ->call('forza')
            ->assertHasNoErrors();

        expect($this->strumento->fresh())
            ->forced_state->toBe($stato)
            ->forced_reason->toBeNull();
    }
});

// --- Tracciamento ---

it('records who forced it and when, server-side', function () {
    scheda($this->admin, $this->strumento)
        ->call('openForza')
        ->set('forzaForm.stato', StatoSemaforo::Rosso->value)
        ->set('forzaForm.motivo', 'Guarnizione rotta')
        ->call('forza');

    $fresh = $this->strumento->fresh();

    // forced_by non è una proprietà del form: arriva sempre da auth().
    expect($fresh->forced_by)->toBe($this->admin->id)
        ->and($fresh->forced_at)->not->toBeNull()
        ->and($fresh->forced_reason)->toBe('Guarnizione rotta')
        ->and($fresh->forcedBy->name)->toBe($this->admin->name);
});

it('cannot be forced through mass assignment', function () {
    // I forced_* sono fuori da $fillable: l'unica via è forzaSemaforo().
    $this->strumento->update([
        'forced_state' => StatoSemaforo::Rosso->value,
        'forced_by' => $this->admin->id,
        'forced_reason' => 'iniettato',
    ]);

    expect($this->strumento->fresh()->forced_state)->toBeNull();
});

// --- Il forzato vince, e si può togliere ---

it('lets the forced state win over the calculated one', function () {
    $this->actingAs($this->admin);
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);

    $this->strumento->forzaSemaforo(StatoSemaforo::Verde);

    expect($this->strumento->fresh())
        ->statoSemaforoEffettivo()->toBe(StatoSemaforo::Verde)   // il forzato
        ->statoSemaforoCalcolato()->toBe(StatoSemaforo::Arancione); // il reale resta visibile
});

it('restores the calculated state when the forzatura is removed', function () {
    $this->actingAs($this->admin);
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    $this->strumento->forzaSemaforo(StatoSemaforo::Verde);

    scheda($this->admin, $this->strumento->fresh())
        ->call('openForza')
        ->call('rimuoviForzatura')
        ->assertSet('showForzaForm', false);

    expect($this->strumento->fresh())
        ->forced_state->toBeNull()
        ->forced_by->toBeNull()
        ->forced_at->toBeNull()
        ->forced_reason->toBeNull()
        ->statoSemaforoEffettivo()->toBe(StatoSemaforo::Arancione);
});

// --- Audit log (ADR-005 lo chiede esplicitamente) ---

it('writes an audit entry when forcing', function () {
    $this->actingAs($this->admin);
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    $this->strumento->forzaSemaforo(StatoSemaforo::Rosso, 'Non idoneo dopo verifica');

    $voce = Activity::where('log_name', AuditLog::NAME)->where('description', 'Semaforo forzato')->latest('id')->first();

    expect($voce)->not->toBeNull()
        ->and($voce->causer_id)->toBe($this->admin->id)
        ->and($voce->subject_id)->toBe($this->strumento->id)
        ->and($voce->properties['forced_state'])->toBe('rosso')
        ->and($voce->properties['stato_calcolato'])->toBe('arancione')
        ->and($voce->properties['motivo'])->toBe('Non idoneo dopo verifica');
});

it('writes an audit entry when removing the forzatura', function () {
    // Riabilitare uno strumento dichiarato non idoneo è sensibile quanto forzarlo.
    $this->actingAs($this->admin);
    $this->strumento->forzaSemaforo(StatoSemaforo::Rosso, 'Non idoneo');
    $this->strumento->rimuoviForzatura();

    $voce = Activity::where('log_name', AuditLog::NAME)->where('description', 'Forzatura semaforo rimossa')->latest('id')->first();

    expect($voce)->not->toBeNull()
        ->and($voce->causer_id)->toBe($this->admin->id)
        ->and($voce->properties['forced_state_rimosso'])->toBe('rosso')
        ->and($voce->properties['stato_calcolato'])->toBe('verde');
});

// --- Permessi (🔴) ---

it('forbids Tenant and Tecnico from every forzatura action', function () {
    foreach (['Tenant', 'Tecnico'] as $ruolo) {
        $utente = User::factory()->create(['tenant_id' => $this->ente->id]);
        $utente->assignRole($ruolo);
        if ($ruolo === 'Tecnico') {
            // S7 (T1a): la scheda rilegge la macchina con gli scope a ogni
            // richiesta; il Tecnico la vede solo dal portafoglio (ADR-007/030),
            // altrimenti 404 come sulla rotta. Col portafoglio il 403 misura il
            // permesso, non lo scope.
            $utente->portafoglioClienti()->attach($this->ente->id);
        }

        foreach ([['openForza'], ['forza'], ['rimuoviForzatura']] as $azione) {
            scheda($utente, $this->strumento)->call(...$azione)->assertForbidden();
        }
    }

    expect($this->strumento->fresh()->forced_state)->toBeNull();
});

it('lets a Responsabile force a strumento in its subtree', function () {
    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);

    scheda($resp, $this->strumento)
        ->call('openForza')
        ->set('forzaForm.stato', StatoSemaforo::Rosso->value)
        ->set('forzaForm.motivo', 'Fuori servizio')
        ->call('forza')
        ->assertHasNoErrors();

    expect($this->strumento->fresh()->forced_by)->toBe($resp->id);
});

it('returns 404 over HTTP for a Responsabile outside its subtree', function () {
    // La forzatura agisce sullo strumento già bindato: l'isolamento è il
    // route-model binding, che Livewire::test bypassa → si prova via HTTP.
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($altroDept->id);

    $this->actingAs($resp)->get(route('strumenti.show', $this->strumento))->assertNotFound();
});

it('returns 404 over HTTP for a strumento of another tenant', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create();
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create();

    $this->actingAs($this->admin)->get(route('strumenti.show', $strumentoB))->assertNotFound();
});

// --- UI ---

it('shows the forzato pill in scheda and elenco, and hides it otherwise', function () {
    scheda($this->admin, $this->strumento)->assertDontSee('⚑');
    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)->assertDontSee('⚑');

    $this->actingAs($this->admin);
    $this->strumento->forzaSemaforo(StatoSemaforo::Rosso, 'Guarnizione rotta');

    scheda($this->admin, $this->strumento->fresh())
        ->assertSee('⚑')
        ->assertSee('Non idoneo')
        ->assertSee('Guarnizione rotta');   // il tooltip porta chi/quando/perché

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)->assertSee('⚑');
});

it('hides the forza button from who lacks semaforo.force', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    scheda($tenant, $this->strumento)->assertDontSee('Forza semaforo');
    scheda($this->admin, $this->strumento)->assertSee('Forza semaforo');
});
