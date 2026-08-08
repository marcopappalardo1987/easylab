<?php

use App\Enums\StatoIntervento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Azioni interventi in scheda strumento (S3 punto 3): CRUD + spunta "Fatto".
 * Aree rosse (Policy di Code Review): permessi per ruolo, whitelist
 * assegnatario, isolamento tenant/sotto-albero sulle scritture.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

// --- CRUD felice ---

it('creates a planned intervento from the modal', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Controllo pressione valvole')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addMonths(2)->toDateString())
        ->call('saveIntervento')
        ->assertHasNoErrors()
        ->assertSet('showInterventoForm', false);

    $intervento = Intervento::withoutGlobalScopes()->where('descrizione', 'Controllo pressione valvole')->sole();
    expect($intervento->stato)->toBe(StatoIntervento::NonFatto)
        ->and($intervento->tenant_id)->toBe($this->strumento->tenant_id)
        ->and($intervento->strumento_id)->toBe($this->strumento->id)
        ->and($intervento->data_esecuzione)->toBeNull();
});

it('creates a historical done intervento in one step', function () {
    // L'inserimento storico del backlog: mai transitare da scaduto-non-fatto.
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Taratura 2025')
        ->set('interventoForm.tipo', 'taratura_e_certificazione')
        ->set('interventoForm.data_scadenza', '2025-05-28')
        ->set('interventoForm.gia_eseguito', true)
        ->set('interventoForm.data_esecuzione', '2025-05-30')
        ->call('saveIntervento')
        ->assertHasNoErrors();

    $intervento = Intervento::withoutGlobalScopes()->where('descrizione', 'Taratura 2025')->sole();
    expect($intervento->stato)->toBe(StatoIntervento::Fatto)
        ->and($intervento->data_esecuzione->toDateString())->toBe('2025-05-30');
});

it('updates descrizione, tipo and data_scadenza without touching stato', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->fatto('2026-01-12')->create();

    scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $intervento->id)
        ->assertSet('interventoForm.descrizione', $intervento->descrizione)
        ->set('interventoForm.descrizione', 'Descrizione corretta')
        ->set('interventoForm.tipo', 'manutenzione_straordinaria')
        ->set('interventoForm.data_scadenza', '2026-03-01')
        ->call('saveIntervento')
        ->assertHasNoErrors();

    $fresh = $intervento->fresh();
    expect($fresh->descrizione)->toBe('Descrizione corretta')
        ->and($fresh->tipo->value)->toBe('manutenzione_straordinaria')
        ->and($fresh->stato)->toBe(StatoIntervento::Fatto)
        ->and($fresh->data_esecuzione->toDateString())->toBe('2026-01-12');
});

it('soft deletes an intervento after confirmation', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    scheda($this->admin, $this->strumento)
        ->call('openEliminaIntervento', $intervento->id)
        ->assertSet('deletingInterventoId', $intervento->id)
        ->call('eliminaIntervento')
        ->assertSet('deletingInterventoId', null);

    // NB: withoutGlobalScopes() rimuoverebbe anche il SoftDeletingScope,
    // quindi il "non trovato" va verificato con la query scopata.
    expect(Intervento::find($intervento->id))->toBeNull()
        ->and(Intervento::withoutGlobalScopes()->withTrashed()->find($intervento->id)->trashed())->toBeTrue();
});

it('marks an intervento as fatto with the default today date', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    scheda($this->admin, $this->strumento)
        ->call('openCompleta', $intervento->id)
        ->assertSet('dataEsecuzione', today()->toDateString())
        ->call('completa')
        ->assertHasNoErrors();

    expect($intervento->fresh())
        ->stato->toBe(StatoIntervento::Fatto)
        ->data_esecuzione->toDateString()->toBe(today()->toDateString());
});

it('marks an intervento as fatto with an explicit past date', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    scheda($this->admin, $this->strumento)
        ->call('openCompleta', $intervento->id)
        ->set('dataEsecuzione', today()->subDays(3)->toDateString())
        ->call('completa')
        ->assertHasNoErrors();

    expect($intervento->fresh()->data_esecuzione->toDateString())->toBe(today()->subDays(3)->toDateString());
});

it('rejects a data_esecuzione in the future', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    scheda($this->admin, $this->strumento)
        ->call('openCompleta', $intervento->id)
        ->set('dataEsecuzione', today()->addDay()->toDateString())
        ->call('completa')
        ->assertHasErrors('dataEsecuzione');

    expect($intervento->fresh()->stato)->toBe(StatoIntervento::NonFatto);
});

it('reopening clears data_esecuzione through the component', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->fatto('2026-01-12')->create();

    scheda($this->admin, $this->strumento)->call('riapri', $intervento->id);

    expect($intervento->fresh())
        ->stato->toBe(StatoIntervento::NonFatto)
        ->data_esecuzione->toBeNull();
});

// --- Permessi (🔴) ---

it('forbids a Tenant from every intervento action', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    foreach ([
        ['openNuovoIntervento'],
        ['openModificaIntervento', $intervento->id],
        ['saveIntervento'],
        ['openCompleta', $intervento->id],
        ['completa'],
        ['riapri', $intervento->id],
        ['openEliminaIntervento', $intervento->id],
        ['eliminaIntervento'],
    ] as $call) {
        scheda($tenant, $this->strumento)->call(...$call)->assertForbidden();
    }
});

it('lets a Tecnico complete and reopen but not create, update or delete', function () {
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tecnico->assignRole('Tecnico');
    $intervento = Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    scheda($tecnico, $this->strumento)
        ->call('openCompleta', $intervento->id)
        ->call('completa')
        ->assertHasNoErrors();
    expect($intervento->fresh()->stato)->toBe(StatoIntervento::Fatto);

    scheda($tecnico, $this->strumento)->call('riapri', $intervento->id);
    expect($intervento->fresh()->stato)->toBe(StatoIntervento::NonFatto);

    foreach ([
        ['openNuovoIntervento'],
        ['saveIntervento'],
        ['openModificaIntervento', $intervento->id],
        ['openEliminaIntervento', $intervento->id],
        ['eliminaIntervento'],
    ] as $call) {
        scheda($tecnico, $this->strumento)->call(...$call)->assertForbidden();
    }
});

it('ignores tecnico_id from a user with create but without assign', function () {
    // Ruolo ad hoc: nessuno dei 6 ruoli seedati ha create senza assign.
    Role::create(['name' => 'Compilatore'])
        ->givePermissionTo(['strumenti.view', 'interventi.view', 'interventi.create']);
    $compilatore = User::factory()->create(['tenant_id' => $this->ente->id]);
    $compilatore->assignRole('Compilatore');
    $collega = User::factory()->create(['tenant_id' => $this->ente->id]);

    scheda($compilatore, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Senza assegnatario')
        ->set('interventoForm.tipo', 'taratura_e_certificazione')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $collega->id) // payload manipolato
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(Intervento::withoutGlobalScopes()->where('descrizione', 'Senza assegnatario')->sole()->tecnico_id)
        ->toBeNull();
});

// --- Whitelist assegnatario (🔴, speculare a tecnicoLabel) ---

it('assigns a tecnico of the same ente', function () {
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Luca Bianchi']);

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Con assegnatario')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $tecnico->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(Intervento::withoutGlobalScopes()->where('descrizione', 'Con assegnatario')->sole()->tecnico_id)
        ->toBe($tecnico->id);
});

it('accepts an external Tecnico with no tenant (ADR-007)', function () {
    $esterno = User::factory()->create(['tenant_id' => null, 'name' => 'Gino Verdi']);
    $esterno->assignRole('Tecnico');

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Assegnato a esterno')
        ->set('interventoForm.tipo', 'taratura_e_certificazione')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $esterno->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(Intervento::withoutGlobalScopes()->where('descrizione', 'Assegnato a esterno')->sole()->tecnico_id)
        ->toBe($esterno->id);
});

it('rejects a tecnico belonging to another ente', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $estraneo = User::factory()->create(['tenant_id' => $enteB->id]);

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Non deve salvare')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $estraneo->id)
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.tecnico_id');

    expect(Intervento::withoutGlobalScopes()->where('descrizione', 'Non deve salvare')->exists())->toBeFalse();
});

it('rejects a user with no tenant and no Tecnico role', function () {
    // Il ∪ della whitelist è sul RUOLO, non sul solo tenant null.
    $piattaforma = User::factory()->create(['tenant_id' => null]);

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Non deve salvare')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $piattaforma->id)
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.tecnico_id');
});

it('lists tenant users and external Tecnici in the assegnatari select, never other tenants', function () {
    $collega = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Collega A']);
    $esterno = User::factory()->create(['tenant_id' => null, 'name' => 'Tecnico Esterno']);
    $esterno->assignRole('Tecnico');
    $enteB = UnitaOrganizzativa::factory()->ente()->create();
    $estraneo = User::factory()->create(['tenant_id' => $enteB->id, 'name' => 'Estraneo B']);

    $assegnatari = scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->viewData('assegnatari');

    $ids = $assegnatari->pluck('id')->all();
    expect($ids)->toContain($collega->id)
        ->toContain($esterno->id)
        ->not->toContain($estraneo->id);
});

// --- Validazioni di forma ---

it('rejects an invalid tipo and requires descrizione and data_scadenza', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', '')
        ->set('interventoForm.tipo', 'lavaggio')
        ->set('interventoForm.data_scadenza', '')
        ->call('saveIntervento')
        ->assertHasErrors(['interventoForm.descrizione', 'interventoForm.tipo', 'interventoForm.data_scadenza']);
});

it('accepts a past data_scadenza (historical entries are legitimate)', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Scadenza passata')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', '2025-01-01')
        ->call('saveIntervento')
        ->assertHasNoErrors();
});

it('requires data_esecuzione when gia_eseguito is checked and rejects a future one', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Storico incompleto')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', '2025-01-01')
        ->set('interventoForm.gia_eseguito', true)
        ->set('interventoForm.data_esecuzione', '')
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.data_esecuzione');

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Storico futuro')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', '2025-01-01')
        ->set('interventoForm.gia_eseguito', true)
        ->set('interventoForm.data_esecuzione', today()->addDay()->toDateString())
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.data_esecuzione');
});

// --- Isolamento sulle scritture (🔴) ---

it('returns 404 when acting on an intervento of another tenant', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create();
    $interventoB = Intervento::factory()->forStrumento($strumentoB)->create(['descrizione' => 'Di B']);

    foreach ([
        ['openModificaIntervento', $interventoB->id],
        ['openCompleta', $interventoB->id],
        ['riapri', $interventoB->id],
        ['openEliminaIntervento', $interventoB->id],
    ] as $call) {
        expect(fn () => scheda($this->admin, $this->strumento)->call(...$call))
            ->toThrow(ModelNotFoundException::class);
    }

    $survivor = Intervento::withoutGlobalScopes()->withTrashed()->find($interventoB->id);
    expect($survivor)->not->toBeNull()
        ->and($survivor->trashed())->toBeFalse()
        ->and($survivor->stato)->toBe(StatoIntervento::NonFatto);
});

it('returns 404 for a Responsabile acting outside the assigned subtree', function () {
    $deptA2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 2']);
    $strumentoA2 = Strumento::factory()->forNode($deptA2)->create();

    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($deptA2->id); // vede SOLO deptA2, non $this->dept

    // Lo strumento della scheda ($this->dept) è fuori dal suo sotto-albero →
    // dalla rotta il route-model binding scopato dà già 404. (Livewire::test
    // bypassa il binding: il 404 del mount si verifica solo via HTTP.)
    $this->actingAs($resp)->get(route('strumenti.show', $this->strumento))->assertNotFound();

    // E dalla scheda di uno strumento visibile non può raggiungere interventi
    // di strumenti fuori scope: id di un altro strumento → 404 dalla relazione.
    $interventoFuori = Intervento::factory()->forStrumento($this->strumento)->create();
    expect(fn () => scheda($resp, $strumentoA2)->call('openCompleta', $interventoFuori->id))
        ->toThrow(ModelNotFoundException::class);
});

it('returns 404 when the id belongs to another strumento of the same tenant', function () {
    $altroStrumento = Strumento::factory()->forNode($this->dept)->create();
    $interventoAltro = Intervento::factory()->forStrumento($altroStrumento)->create();

    expect(fn () => scheda($this->admin, $this->strumento)->call('riapri', $interventoAltro->id))
        ->toThrow(ModelNotFoundException::class);
});
