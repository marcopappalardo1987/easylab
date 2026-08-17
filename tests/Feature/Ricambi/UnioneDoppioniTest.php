<?php

use App\Livewire\Ricambi\RicercaRicambi;
use App\Models\Garanzia;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Unione dei doppioni di catalogo (🔗 ADR-008 «l'admin può unire doppioni»,
 * peso aumentato da ADR-022 — S4 STRETCH).
 *
 * ADR-022 ha spostato la chiave del collega-o-crea dal codice al NOME, e sul
 * campo si scrive quello che si ha in testa: «Guarnizione O-Ring» e «Guarnizione
 * OR» sono lo stesso pezzo per un tecnico e due voci per il database. Ogni voce
 * di troppo degrada la ricerca incrociata, che è la ragione per cui il catalogo
 * esiste — quindi l'unione non è una comodità, è la manutenzione di ciò che
 * ADR-008 promette.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create();
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create();

    $this->tenere = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Guarnizione O-Ring']);
    $this->doppione = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Guarnizione OR']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

it('moves the mountings and bins the duplicate, without recreating anything', function () {
    $utilizzo = RicambioUtilizzo::factory()->forStrumento($this->strumento)->forRicambio($this->doppione)->create();
    $garanzia = Garanzia::factory()->forRicambio($utilizzo)->create();

    Auth::login($this->admin);
    $spostati = $this->doppione->unisciIn($this->tenere);

    expect($spostati)->toBe(1)
        ->and($this->doppione->fresh()->trashed())->toBeTrue()
        // ⚠️ STESSO id: i montaggi si RIETICHETTANO, non si ricreano. Ricrearli
        // avrebbe staccato la garanzia — che punta a `ricambio_utilizzo`, non al
        // catalogo — e riscritto uno storico che è la prova di cosa è stato
        // montato e quando.
        ->and($utilizzo->fresh()->ricambio_id)->toBe($this->tenere->id)
        ->and($utilizzo->fresh()->id)->toBe($utilizzo->id)
        ->and($garanzia->fresh()->ricambio_utilizzo_id)->toBe($utilizzo->id);
});

it('leaves the name reusable, because the duplicate is binned and not deleted', function () {
    Auth::login($this->admin);
    $this->doppione->unisciIn($this->tenere);

    // L'unique del catalogo è parziale su `deleted_at is null`: dopo l'unione
    // il collega-o-crea può ricreare quel nome senza sbattere sul vincolo.
    $rinata = Ricambio::collegaOCrea('Guarnizione OR', $this->ente->id);

    expect($rinata->id)->not->toBe($this->doppione->id)
        ->and($rinata->nome)->toBe('Guarnizione OR');
});

it('writes in the audit what a deleted_at could never say', function () {
    RicambioUtilizzo::factory()->forStrumento($this->strumento)->forRicambio($this->doppione)->create();

    Auth::login($this->admin);

    // Il conteggio si misura come DELTA: le fixture (catalogo, montaggio,
    // strumento) scrivono righe di audit a loro volta, e un totale assoluto
    // misurerebbe quelle invece del gesto.
    $prima = Activity::query()->where('log_name', 'audit')->count();
    $this->doppione->unisciIn($this->tenere);

    // In tabella si legge solo un `deleted_at` valorizzato, indistinguibile da
    // una cancellazione qualunque: «è confluita in quest'altra, con N montaggi»
    // non esiste da nessuna parte se non la si scrive.
    // ⚠️ **UNA riga, non due.** Senza `disableLogging()` il trait scriverebbe
    // anche la propria «Cancellazione ricambio», che è fuorviante: dice che la
    // voce è stata cancellata, mentre è confluita in un'altra. È la regola
    // «o il trait o le activity() esplicite» di ADR-027, e questo è il caso in
    // cui l'eccezione del guardrail si guadagna il posto.
    expect(Activity::query()->where('log_name', 'audit')->count() - $prima)->toBe(1);

    $riga = Activity::query()->where('log_name', 'audit')->latest('id')->first();

    expect($riga->description)->toContain('Unito il ricambio «Guarnizione OR» in «Guarnizione O-Ring»')
        ->and($riga->properties['montaggi_spostati'])->toBe(1)
        ->and($riga->properties['unita_da_id'])->toBe($this->doppione->id)
        ->and($riga->causer_id)->toBe($this->admin->id);
});

// --- I negativi ---

it('refuses to merge a voice into itself', function () {
    Auth::login($this->admin);

    expect(fn () => $this->doppione->unisciIn($this->doppione))
        ->toThrow(InvalidArgumentException::class, 'sé stessa');
});

it('never merges across Enti, not even from the console', function () {
    // Il metodo è di dominio e deve reggere dove gli scope NON filtrano:
    // seeder, comandi, import futuri.
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $estranea = Ricambio::factory()->forTenant($altroEnte)->create(['nome' => 'Guarnizione OR']);

    expect(fn () => $this->doppione->unisciIn($estranea))
        ->toThrow(InvalidArgumentException::class, 'Enti diversi');
});

it('refuses a binned destination', function () {
    $this->tenere->delete();

    expect(fn () => $this->doppione->unisciIn($this->tenere->fresh()))
        ->toThrow(InvalidArgumentException::class, 'cestinata');
});

it('forbids the merge to whoever lacks ricambi.merge', function () {
    // Il Responsabile amministra il catalogo (view/create/update/delete) ma NON
    // unisce: la matrice dello Schema Ruoli glielo nega, ed è la sola azione del
    // catalogo che gli manca — un merge sbagliato riscrive righe di reparti che
    // non sono i suoi.
    $resp = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $resp->assignRole('Responsabile Reparto');

    expect($resp->can('ricambi.merge'))->toBeFalse();

    Livewire::actingAs($resp)->test(RicercaRicambi::class)
        ->call('apriUnione', $this->doppione->id)
        ->assertForbidden();
});

it('never opens the modal on a voice of another Ente', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $estranea = Ricambio::factory()->forTenant($altroEnte)->create();

    // `findOrFail` sul model riapplica gli scope: una voce di un altro Ente
    // «non esiste», ed è la risposta giusta — un 403 confermerebbe che c'è.
    expect(fn () => Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        ->call('apriUnione', $estranea->id))
        ->toThrow(ModelNotFoundException::class);
});

// --- Il giro completo dalla pagina ---

it('merges from the page and says what happened', function () {
    RicambioUtilizzo::factory()->forStrumento($this->strumento)->forRicambio($this->doppione)->create();

    Livewire::actingAs($this->admin)->test(RicercaRicambi::class)
        ->set('search', 'guarnizione')
        ->call('apriUnione', $this->doppione->id)
        ->set('destinazioneId', $this->tenere->id)
        ->call('unisci')
        ->assertHasNoErrors()
        ->assertSee('«Guarnizione OR» è confluito in «Guarnizione O-Ring»')
        ->assertSee('1 montaggio spostato')
        // Modale chiusa e catalogo ripulito: la ricerca ora trova una voce sola.
        ->assertSet('unendoId', null);

    expect(Ricambio::where('tenant_id', $this->ente->id)->count())->toBe(1);
});
