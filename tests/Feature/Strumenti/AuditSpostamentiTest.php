<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * La traccia degli spostamenti (🔗 ADR-027 — S4, 15 Ago 2026).
 *
 * `spostamenti_strumento` è append-only e porta già `eseguito_da` e `data`:
 * sembra un audit e non lo è. `data` è la data di BUSINESS scelta nel form,
 * quindi retrodatabile; `eseguito_da` è nullable e `nullOnDelete()`, quindi
 * cancellando l'utente sparisce l'unico riferimento a chi ha spostato; e la
 * vista Audit di S6 filtra per canale, dove questa tabella non c'era.
 *
 * Le sorgenti di `SpostamentoStrumento::create()` nel codice sono TRE e nascono
 * da componenti diversi: la scheda (spostamento interno), l'anagrafica
 * (ingresso alla creazione) e l'import CSV (ingresso in massa). Vanno esercitate
 * tutte, o la copertura è dichiarata e non verificata.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 1']);
    $this->dept2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 2']);
    $this->strumento = Strumento::factory()->forNode($this->dept1)->create();

    $this->utente = function (string $ruolo, ?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create([
            'tenant_id' => ($ente ?? $this->ente)->id,
            'two_factor_confirmed_at' => now(),
        ]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->admin = ($this->utente)('Admin');
    $this->audit = fn () => Activity::where('log_name', AuditLog::NAME)
        ->where('subject_type', SpostamentoStrumento::class)->get();
});

// --- Le tre sorgenti ---

it('records a move made from the strumento card', function () {
    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openMove')
        ->set('destinazioneId', $this->dept2->id)
        ->set('dataSpostamento', today()->toDateString())
        ->call('move')
        ->assertHasNoErrors();

    $riga = ($this->audit)()->first();

    expect($riga->description)->toBe('Creazione spostamento')
        ->and($riga->causer_id)->toBe($this->admin->id)
        ->and($riga->attribute_changes['attributes']['da_nodo_id'])->toBe($this->dept1->id)
        ->and($riga->attribute_changes['attributes']['a_nodo_id'])->toBe($this->dept2->id);
});

it('records the ingresso written when a strumento is created with a provenance', function () {
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->dept1->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Autoclave di seconda mano')
        ->set('provenienza', 'Laboratorio Rossi')
        ->call('saveStrumento')
        ->assertHasNoErrors();

    $riga = ($this->audit)()->first();

    expect($riga->attribute_changes['attributes']['tipo_spostamento'])->toBe('ingresso')
        ->and($riga->attribute_changes['attributes']['da_esterno'])->toBe('Laboratorio Rossi');
});

it('leaves no trace when a strumento is created without provenance', function () {
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->dept1->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Autoclave nuova')
        ->call('saveStrumento')
        ->assertHasNoErrors();

    expect(($this->audit)())->toHaveCount(0);
});

// --- Append-only: le guardie del model, e l'ordine dei listener ---

it('keeps the append-only guard, which is why only created can ever fire', function () {
    $spostamento = SpostamentoStrumento::create([
        'tenant_id' => $this->ente->id,
        'strumento_id' => $this->strumento->id,
        'da_nodo_id' => $this->dept1->id,
        'a_nodo_id' => $this->dept2->id,
        'tipo_spostamento' => 'interno',
        'data' => today()->toDateString(),
    ]);

    Activity::query()->delete();

    // Le guardie stanno in `booted()`, che gira DOPO `bootTraits()`: il trait ha
    // già registrato i propri listener, ma l'eccezione parte prima che si
    // arrivi a scrivere. Senza questo caso, l'affermazione resterebbe nel
    // docblock e nessuno saprebbe se è vera.
    expect(fn () => $spostamento->update(['nota' => 'correzione']))->toThrow(RuntimeException::class)
        ->and(fn () => $spostamento->delete())->toThrow(RuntimeException::class)
        ->and(($this->audit)())->toHaveCount(0);
});

// --- Negativi: un tentativo respinto non lascia traccia di dominio ---

it('writes nothing when the user cannot move strumenti', function () {
    $tenant = ($this->utente)('Tenant'); // niente `strumenti.move`

    Livewire::actingAs($tenant)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('move')
        ->assertForbidden();

    expect(($this->audit)())->toHaveCount(0);
});

it('writes nothing when the destination is outside the Ente', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptAltrui = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();

    // `findOrFail` in scope: il nodo altrui non esiste per questo utente, e
    // l'eccezione esce dal componente perché non è una AuthorizationException.
    expect(fn () => Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openMove')
        ->set('destinazioneId', $deptAltrui->id)
        ->set('dataSpostamento', today()->toDateString())
        ->call('move'))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->audit)())->toHaveCount(0)
        ->and($this->strumento->fresh()->unita_organizzativa_id)->toBe($this->dept1->id);
});
