<?php

use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * La traccia delle scritture sugli interventi (🔗 ADR-027 — S4, 15 Ago 2026).
 *
 * `Intervento` era rimasto fuori dalla copertura quando il trait fu anticipato
 * al blocco 3, e la roadmap lo dichiarava: «il trait su Intervento traccerebbe
 * superfici che quel punto non tocca né testa, e chiudere a metà una casella
 * che sembra chiusa è peggio che lasciarla aperta». Questo file è quelle
 * superfici, testate.
 *
 * Due avvertenze che decidono se un caso è scrivibile:
 *
 * 1. **L'evento `deleted` mette lo snapshot in `old` e RIMUOVE `attributes`**
 *    (`LogsActivity::buildChanges()`): un assert su `attributes` per la
 *    cancellazione non potrebbe passare mai.
 * 2. **Le date si asseriscono con `toContain`, mai per uguaglianza**: passano
 *    da `serializeDate()` (ISO 8601 con microsecondi) e il raw di partenza
 *    differisce fra SQLite (`'2026-08-15 00:00:00'`) e Postgres (una vera
 *    `date`). Per uguaglianza il test passerebbe in locale e cadrebbe in CI.
 *
 * Non c'è un caso per `restored`: nessuna vista ripristina un intervento
 * cestinato, e un test che finge una superficie che non esiste è peggio di un
 * test mancante.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create();

    $this->utente = function (string $ruolo, ?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create([
            'tenant_id' => ($ente ?? $this->ente)->id,
            'two_factor_confirmed_at' => now(),
        ]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->admin = ($this->utente)('Admin');
    $this->audit = fn () => Activity::where('log_name', AuditLog::NAME)->get();
});

// --- Le quattro superfici ---

it('records the creation of an intervento, with who did it', function () {
    $tecnico = ($this->utente)('Tecnico');

    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Sanificazione camera interna')
        ->set('interventoForm.data_scadenza', today()->addMonths(3)->toDateString())
        ->set('interventoForm.tecnico_id', $tecnico->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    $riga = ($this->audit)()->firstWhere('subject_type', Intervento::class);

    expect($riga->description)->toBe('Creazione intervento')
        ->and($riga->causer_id)->toBe($this->admin->id)
        ->and(array_keys($riga->attribute_changes['attributes']))
        ->toContain('descrizione', 'tipo', 'data_scadenza', 'tecnico_id');
});

it('records the closing of an intervento as the two columns that decide it', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    $this->actingAs($this->admin);
    Activity::query()->delete();

    $intervento->segnaFatto(today());

    $righe = ($this->audit)();

    expect($righe)->toHaveCount(1)
        ->and($righe->first()->description)->toBe('Modifica intervento')
        ->and($righe->first()->attribute_changes['old']['stato'])->toBe('non_fatto')
        ->and($righe->first()->attribute_changes['attributes']['stato'])->toBe('fatto')
        // `toContain` e non `toBe`: vedi l'avvertenza 2 nel docblock.
        ->and($righe->first()->attribute_changes['attributes']['data_esecuzione'])
        ->toContain(today()->toDateString());
});

it('records the reopening, so that the cleared date does not vanish silently', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->fatto()->create();
    $this->actingAs($this->admin);
    Activity::query()->delete();

    $intervento->riapri();

    $riga = ($this->audit)()->first();

    // L'azzeramento lo fa l'hook `saving` del model, non il chiamante: senza
    // questo caso resterebbe una scrittura invisibile all'audit.
    expect($riga->attribute_changes['attributes']['stato'])->toBe('non_fatto')
        ->and($riga->attribute_changes['attributes']['data_esecuzione'])->toBeNull();
});

it('records only the changed assignee, and nothing else', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    $altro = ($this->utente)('Tecnico');
    $this->actingAs($this->admin);
    Activity::query()->delete();

    $intervento->update(['tecnico_id' => $altro->id]);

    // È il caso che tiene in piedi `logOnlyDirty()`: senza, ogni riga
    // porterebbe tutte le colonne tracciate e la vista Audit di S6 diventerebbe
    // illeggibile — «cos'è cambiato?» non avrebbe risposta.
    expect(array_keys(($this->audit)()->first()->attribute_changes['attributes']))->toBe(['tecnico_id']);
});

it('records the deletion, with the snapshot in old and not in attributes', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    $this->actingAs($this->admin);
    Activity::query()->delete();

    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openEliminaIntervento', $intervento->id)
        ->call('eliminaIntervento');

    $riga = ($this->audit)()->first();

    expect($riga->description)->toBe('Eliminazione intervento')
        ->and($riga->attribute_changes)->toHaveKey('old')
        ->and($riga->attribute_changes)->not->toHaveKey('attributes');
});

// --- La regola «o il trait o le esplicite», resa verificabile ---

it('never writes two rows for one gesture', function () {
    $tecnico = ($this->utente)('Tecnico');

    $this->actingAs($this->admin);

    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Sostituzione lampada UV')
        ->set('interventoForm.data_scadenza', today()->addMonth()->toDateString())
        ->set('interventoForm.tecnico_id', $tecnico->id)
        ->set('ricambiEffettuati', true)
        ->call('addRicambio')
        ->set('ricambiNuovi.0.nome', 'Lampada UV')
        ->set('ricambiNuovi.0.scadenza_garanzia', today()->addYear()->toDateString())
        ->call('saveIntervento')
        ->assertHasNoErrors();

    $righe = ($this->audit)();
    $coppie = $righe->map(fn (Activity $a) => $a->subject_type.'#'.$a->subject_id);

    // Quattro scritture, quattro soggetti distinti: intervento, catalogo,
    // utilizzo, garanzia. Nessun soggetto compare due volte — è la traduzione
    // verificabile della regola di ADR-027.
    expect($coppie->count())->toBe($coppie->unique()->count())
        ->and($righe->pluck('subject_type')->unique())->toHaveCount(4);
});

it('keeps tracking when nobody is logged in, so seeders and imports stay traceable', function () {
    Intervento::factory()->forStrumento($this->strumento)->create();

    expect(($this->audit)()->first()->causer_id)->toBeNull();
});

it('does not track bulk inserts, and says so on purpose', function () {
    // Dichiarazione, non lode: è ciò che salva DemoSeeder da decine di migliaia
    // di righe, ed è lo stesso buco che il trasferimento cross-tenant di
    // ADR-015 erediterà quando sposterà gli interventi con un update by-query.
    Intervento::insert([[
        'tenant_id' => $this->ente->id,
        'strumento_id' => $this->strumento->id,
        'descrizione' => 'Inserita in blocco',
        'tipo' => 'manutenzione_ordinaria',
        'stato' => 'non_fatto',
        'data_scadenza' => today()->addMonth()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]]);

    expect(($this->audit)())->toHaveCount(0);
});

it('leaves no row when a save changes nothing that matters', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    $this->actingAs($this->admin);
    Activity::query()->delete();

    // `reseller_id` è fillable ma escluso dal set tracciato (ADR-002: sempre
    // NULL in V1, sarebbe rumore). È l'unico caso che falsifica davvero
    // `dontLogEmptyChanges()`.
    $intervento->update(['reseller_id' => 7]);

    expect(($this->audit)())->toHaveCount(0);
});

// --- Negativi: un tentativo respinto non lascia traccia di dominio ---

it('writes nothing when an Admin of another Ente aims at this intervento', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $intruso = ($this->utente)('Admin', $altroEnte);
    Activity::query()->delete();

    $this->actingAs($intruso)->get(route('strumenti.show', $this->strumento))->assertNotFound();

    expect(($this->audit)())->toHaveCount(0)
        ->and($intervento->fresh()->trashed())->toBeFalse();
});

it('writes nothing when the role lacks the permission', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    $tenant = ($this->utente)('Tenant'); // niente `interventi.delete`
    Activity::query()->delete();

    // `assertForbidden` e non `toThrow`: Livewire converte l'AuthorizationException
    // in una risposta 403 invece di lasciarla propagare fuori dal componente.
    Livewire::actingAs($tenant)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->call('openEliminaIntervento', $intervento->id)
        ->assertForbidden();

    expect(($this->audit)())->toHaveCount(0)
        ->and($intervento->fresh()->trashed())->toBeFalse();
});

it('writes nothing when a Responsabile aims outside their sub-tree', function () {
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 2']);
    $fuori = Strumento::factory()->forNode($altroDept)->create();
    $intervento = Intervento::factory()->forStrumento($fuori)->create();

    $resp = ($this->utente)('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);
    Activity::query()->delete();

    // Dalla ROTTA e non dal componente: è il route-model binding scopato a
    // produrre il 404, e montare il componente con il model già in mano
    // salterebbe proprio la guardia che si vuole verificare.
    $this->actingAs($resp)->get(route('strumenti.show', $fuori))->assertNotFound();

    expect(($this->audit)())->toHaveCount(0)
        ->and($intervento->fresh()->trashed())->toBeFalse();
});
