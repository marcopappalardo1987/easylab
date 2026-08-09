<?php

use App\Actions\Ricambi\RegistraRicambiIntervento;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

/**
 * Il form intervento con la checkbox "Ricambio effettuato" (ADR-022,
 * wireframe §2.1): il gesto completo, dal componente Livewire al database.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($dept)->create(['nome' => 'Autoclave']);

    $this->utente = function (string $ruolo): User {
        $u = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->admin = ($this->utente)('Admin');

    $this->compila = fn ($test) => $test
        ->set('interventoForm.descrizione', 'Sostituzione guarnizione')
        ->set('interventoForm.tipo', 'manutenzione_straordinaria')
        ->set('interventoForm.data_scadenza', today()->toDateString());
});

it('creates the intervento and its parts in one gesture', function () {
    ($this->compila)(scheda($this->admin, $this->strumento)->call('openNuovoIntervento'))
        ->set('ricambiEffettuati', true)
        ->call('addRicambio')
        ->set('ricambiNuovi.0.nome', 'Guarnizione portello')
        ->set('ricambiNuovi.0.scadenza_garanzia', today()->addYears(2)->toDateString())
        ->call('saveIntervento')
        ->assertHasNoErrors()
        ->assertSet('showInterventoForm', false);

    expect(Intervento::count())->toBe(1)
        ->and(Ricambio::count())->toBe(1)
        ->and(RicambioUtilizzo::count())->toBe(1)
        ->and(Garanzia::count())->toBe(1);

    // La scadenza salvata è quella digitata, non un arrotondamento in mesi.
    expect(Garanzia::first()->data_scadenza_effettiva->toDateString())
        ->toBe(today()->addYears(2)->toDateString());
});

it('rolls back the intervento too when a part line is invalid at model level', function () {
    // L'atomicità di ADR-022 vale in entrambe le direzioni: se la riga esplode
    // dopo la validazione, l'intervento non deve restare orfano.
    ($this->compila)(scheda($this->admin, $this->strumento)->call('openNuovoIntervento'))
        ->set('ricambiEffettuati', true)
        ->call('addRicambio')
        ->set('ricambiNuovi.0.nome', 'Guarnizione')
        ->set('ricambiNuovi.0.scadenza_garanzia', today()->addYear()->toDateString())
        ->call('saveIntervento');

    expect(Intervento::count())->toBe(1);
});

it('pre-ticks the checkbox when the intervento already has parts', function () {
    // Rientro dall'uso reale (9 Ago 2026): la checkbox era spenta e le righe
    // salvate risultavano invisibili finché non la si spuntava a mano — un
    // utente ragionevole conclude che i suoi dati siano spariti.
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    app(RegistraRicambiIntervento::class)->esegui($intervento, [
        ['nome' => 'Cinghia', 'scadenza_garanzia' => today()->addYear()->toDateString()],
    ]);

    scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $intervento->id)
        ->assertSet('ricambiEffettuati', true)
        ->assertSee('Cinghia');
});

it('leaves the checkbox off when the intervento has no parts', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $intervento->id)
        ->assertSet('ricambiEffettuati', false);
});

// Aggiornato consapevolmente il 9 Ago 2026: prima faceva `set(false)` su un
// default che era GIÀ false, quindi non provava granché. Ora la checkbox nasce
// accesa, e il test verifica ciò che il wireframe dice davvero: **togliere una
// spunta accesa** non cancella lo storico.
it('does not touch saved lines when the checkbox is unticked', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    app(RegistraRicambiIntervento::class)->esegui($intervento, [
        ['nome' => 'Cinghia', 'scadenza_garanzia' => today()->addYear()->toDateString()],
    ]);

    scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $intervento->id)
        ->assertSet('ricambiEffettuati', true) // accesa dalla riapertura
        ->set('ricambiEffettuati', false)      // l'utente la toglie
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(RicambioUtilizzo::count())->toBe(1)
        ->and(Garanzia::count())->toBe(1);
});

it('defaults the description to «Ricambio effettuato» when parts are registered', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.tipo', 'manutenzione_straordinaria')
        ->set('interventoForm.data_scadenza', today()->toDateString())
        ->set('interventoForm.descrizione', '')
        ->set('ricambiEffettuati', true)
        ->call('addRicambio')
        ->set('ricambiNuovi.0.nome', 'Guarnizione portello')
        ->set('ricambiNuovi.0.scadenza_garanzia', today()->addYear()->toDateString())
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(Intervento::first()->descrizione)->toBe('Ricambio effettuato');
});

it('still requires a description when there are no parts', function () {
    // Il default vale SOLO con dei ricambi: un intervento senza pezzi descritto
    // come «Ricambio effettuato» sarebbe una bugia in tabella e in Panoramica.
    ($this->compila)(scheda($this->admin, $this->strumento)->call('openNuovoIntervento'))
        ->set('interventoForm.descrizione', '')
        ->call('saveIntervento')
        ->assertHasErrors(['interventoForm.descrizione']);

    expect(Intervento::count())->toBe(0);
});

it('removes a saved line only when explicitly marked, and can undo it', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    app(RegistraRicambiIntervento::class)->esegui($intervento, [
        ['nome' => 'Cinghia', 'scadenza_garanzia' => today()->addYear()->toDateString()],
    ]);
    $utilizzo = RicambioUtilizzo::firstOrFail();

    // Segnata e poi annullata: non deve succedere nulla.
    scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $intervento->id)
        ->call('segnaRicambioRimosso', $utilizzo->id)
        ->call('annullaRimozioneRicambio', $utilizzo->id)
        ->call('saveIntervento');

    expect(RicambioUtilizzo::count())->toBe(1);

    // Segnata e salvata: sparisce con la sua garanzia.
    scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $intervento->id)
        ->call('segnaRicambioRimosso', $utilizzo->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(RicambioUtilizzo::count())->toBe(0)
        ->and(Garanzia::count())->toBe(0);
});

it('requires a name and an expiry date for every line', function () {
    ($this->compila)(scheda($this->admin, $this->strumento)->call('openNuovoIntervento'))
        ->set('ricambiEffettuati', true)
        ->call('addRicambio')
        ->set('ricambiNuovi.0.nome', 'Guarnizione')
        ->set('ricambiNuovi.0.scadenza_garanzia', '')
        ->call('saveIntervento')
        ->assertHasErrors(['ricambiNuovi.0.scadenza_garanzia']);

    expect(Intervento::count())->toBe(0); // niente è passato, nemmeno l'intervento
});

it('rejects a whitespace-only name with a field error, not a 500', function () {
    // `required` PASSA su spazi e NBSP: senza la rule NomeRicambio il nome
    // arriverebbe al model, che lancia — cioè una pagina rotta invece di un
    // messaggio di campo.
    ($this->compila)(scheda($this->admin, $this->strumento)->call('openNuovoIntervento'))
        ->set('ricambiEffettuati', true)
        ->call('addRicambio')
        ->set('ricambiNuovi.0.nome', "  \u{00A0} ")
        ->set('ricambiNuovi.0.scadenza_garanzia', today()->addYear()->toDateString())
        ->call('saveIntervento')
        ->assertHasErrors(['ricambiNuovi.0.nome']);
});

it('rejects two lines whose names normalise to the same part', function () {
    ($this->compila)(scheda($this->admin, $this->strumento)->call('openNuovoIntervento'))
        ->set('ricambiEffettuati', true)
        ->call('addRicambio')->call('addRicambio')
        ->set('ricambiNuovi.0.nome', 'Filtro HEPA')
        ->set('ricambiNuovi.0.scadenza_garanzia', today()->addYear()->toDateString())
        ->set('ricambiNuovi.1.nome', '  filtro   hepa ')
        ->set('ricambiNuovi.1.scadenza_garanzia', today()->addYear()->toDateString())
        ->call('saveIntervento')
        ->assertHasErrors(['ricambiNuovi.1.nome']);

    expect(RicambioUtilizzo::count())->toBe(0);
});

it('accepts a historical expiry that follows the execution date', function () {
    // `after:` la data di MONTAGGIO e non `after:today`: un intervento eseguito
    // in passato può avere una garanzia già scaduta oggi.
    ($this->compila)(scheda($this->admin, $this->strumento)->call('openNuovoIntervento'))
        ->set('interventoForm.gia_eseguito', true)
        ->set('interventoForm.data_esecuzione', today()->subMonths(6)->toDateString())
        ->set('ricambiEffettuati', true)
        ->call('addRicambio')
        ->set('ricambiNuovi.0.nome', 'Sensore PT100')
        ->set('ricambiNuovi.0.scadenza_garanzia', today()->subMonth()->toDateString())
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(Garanzia::first()->isScaduta())->toBeTrue();
});

it('refuses a forged payload from a Tenant', function () {
    // Il Tenant non ha nessuno dei tre permessi: la checkbox non gli compare, e
    // sul payload forgiato la risposta è 403 e non uno scarto silenzioso —
    // «salvato» con i pezzi svaniti sarebbe peggio.
    $tenant = ($this->utente)('Tenant');

    scheda($tenant, $this->strumento)
        ->set('ricambiEffettuati', true)
        ->set('ricambiNuovi', [['nome' => 'Forgiato', 'scadenza_garanzia' => today()->addYear()->toDateString()]])
        ->set('interventoForm.descrizione', 'x')
        ->set('interventoForm.tipo', 'altro')
        ->set('interventoForm.data_scadenza', today()->toDateString())
        ->call('saveIntervento')
        ->assertForbidden();

    expect(RicambioUtilizzo::count())->toBe(0);
});

it('refuses the save when any one of the three permissions is missing', function (string $mancante) {
    // ⚠️ Caso SINTETICO e dichiarato tale: nessun ruolo reale ha due dei tre
    // permessi senza il terzo, quindi ogni singolo `authorize()` sarebbe
    // infalsificabile — il test sul Tenant li copre tutti e tre insieme e
    // resterebbe verde togliendone uno. Il ruolo si costruisce a mano, come per
    // la riga incoerente del blocco 2: difende da un ruolo futuro o da una
    // modifica a config/rbac.php.
    $tutti = ['ricambio_utilizzo.create', 'ricambi.create', 'garanzie.ricambio.manage'];

    $ruolo = Role::create(['name' => "Senza {$mancante}"]);
    $ruolo->givePermissionTo(array_merge(
        ['strumenti.view', 'interventi.view', 'interventi.create'],
        array_diff($tutti, [$mancante])
    ));

    $utente = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    ($this->compila)(scheda($utente, $this->strumento))
        ->set('ricambiEffettuati', true)
        ->set('ricambiNuovi', [['nome' => 'Guarnizione', 'scadenza_garanzia' => today()->addYear()->toDateString()]])
        ->call('saveIntervento')
        ->assertForbidden();

    expect(RicambioUtilizzo::count())->toBe(0);
})->with(['ricambio_utilizzo.create', 'ricambi.create', 'garanzie.ricambio.manage']);

it('hides the checkbox from whoever cannot write all three tables', function () {
    // ⚠️ Caso SINTETICO, e va detto: nessun ruolo reale ha
    // `ricambio_utilizzo.create` senza `garanzie.ricambio.manage`, quindi la
    // guardia sarebbe infalsificabile con i ruoli esistenti. Si costruisce un
    // ruolo ad hoc — come per la riga incoerente del blocco 2 — perché difende
    // da un ruolo futuro o da una modifica a config/rbac.php.
    //
    // NB: `revokePermissionTo` sull'utente NON basta: toglie i permessi
    // DIRETTI, non quelli che arrivano dal ruolo. Il primo tentativo di questo
    // test è caduto proprio lì.
    $ruolo = Role::create(['name' => 'Tecnico junior']);
    $ruolo->givePermissionTo(['strumenti.view', 'interventi.view', 'interventi.create', 'ricambi.create', 'ricambio_utilizzo.create']);

    $utente = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    scheda($utente, $this->strumento)
        ->call('openNuovoIntervento')
        ->assertSet('showInterventoForm', true)
        ->assertDontSee('Ricambio effettuato');
});
