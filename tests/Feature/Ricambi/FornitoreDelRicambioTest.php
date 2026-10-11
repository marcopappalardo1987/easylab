<?php

use App\Livewire\Fornitori\ElencoFornitori;
use App\Models\Fornitore;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Il fornitore di un ricambio (🔗 ADR-051; ADR-008/022 i ricambi, ADR-023 il
 * fornitore della macchina).
 *
 * Sta sul **pezzo montato**, non sulla voce di catalogo: la voce è un nome, e
 * lo stesso pezzo si compra da fornitori diversi in anni diversi. È facoltativo,
 * e si sceglie con lo stesso selettore della macchina: nella riga del form
 * intervento, quando il pezzo si registra, e nella correzione dal tab Ricambi.
 *
 * Mondo: l'Ente A con una macchina e due fornitori, uno dei quali cestinato;
 * l'Ente B, di un altro cliente, col suo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($reparto)->create(['nome' => 'Autoclave']);

    $this->alfa = Fornitore::factory()->forTenant($this->ente)->create(['ragione_sociale' => 'Alfa Ricambi']);
    $this->beta = Fornitore::factory()->forTenant($this->ente)->create(['ragione_sociale' => 'Beta Service']);
    $this->cestinato = Fornitore::factory()->forTenant($this->ente)->create(['ragione_sociale' => 'Gamma Parts']);
    $this->cestinato->delete();

    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $this->altrui = Fornitore::factory()->forTenant($altroEnte)->create(['ragione_sociale' => 'Fornitore altrui']);

    $this->utente = function (string|Role $ruolo): User {
        $u = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u->fresh();
    };

    $this->admin = ($this->utente)('Admin');
    $this->tecnico = ($this->utente)('Tecnico');

    /** Il form intervento aperto, con una riga di ricambio già compilata nel nome e nella garanzia. */
    $this->formConRicambi = function (User $chi, int $righe = 1) {
        $form = scheda($chi, $this->strumento)
            ->call('openNuovoIntervento')
            ->set('interventoForm.descrizione', 'Sostituzione pezzi')
            ->set('interventoForm.tipo', 'manutenzione_straordinaria')
            ->set('interventoForm.data_scadenza', today()->toDateString())
            ->set('interventoForm.tecnico_id', $this->tecnico->id)
            ->set('ricambiEffettuati', true);

        foreach (range(0, $righe - 1) as $i) {
            $form->call('addRicambio')
                ->set("ricambiNuovi.{$i}.nome", 'Pezzo '.($i + 1))
                ->set("ricambiNuovi.{$i}.scadenza_garanzia", today()->addYear()->toDateString());
        }

        return $form;
    };

    /** Un pezzo già montato sulla macchina, col fornitore che gli si dà. */
    $this->pezzoMontato = function (?Fornitore $fornitore = null, string $nome = 'Guarnizione'): RicambioUtilizzo {
        $ricambio = Ricambio::collegaOCrea($nome, $this->ente->id);

        return RicambioUtilizzo::factory()->forStrumento($this->strumento)->forRicambio($ricambio)
            ->create(['fornitore_id' => $fornitore?->id]);
    };

    /** Un ruolo che registra e corregge ricambi ma non vede i fornitori. */
    $this->senzaFornitori = function (): User {
        $ruolo = Role::create(['name' => 'Senza fornitori', 'guard_name' => 'web']);
        $ruolo->givePermissionTo([
            'unita_organizzativa.view', 'strumenti.view',
            'interventi.view', 'interventi.create', 'interventi.assign',
            'ricambi.view', 'ricambi.create',
            'ricambio_utilizzo.view', 'ricambio_utilizzo.create', 'ricambio_utilizzo.update',
            'garanzie.ricambio.view', 'garanzie.ricambio.manage',
        ]);

        return ($this->utente)($ruolo);
    };
});

// ─── Nel form intervento ─────────────────────────────────────────────────────

it('saves the supplier of each part with the intervention, and none where none was said', function () {
    ($this->formConRicambi)($this->admin, 2)
        ->call('apriFornitori', 'ricambio.0')
        ->assertSee('Alfa Ricambi')
        ->assertDontSee('Fornitore altrui')
        ->assertDontSee('Gamma Parts')
        ->call('scegliFornitore', $this->alfa->id)
        ->assertSet('ricambiNuovi.0.fornitore_id', $this->alfa->id)
        // 🔴 Solo nella sua riga.
        ->assertSet('ricambiNuovi.1.fornitore_id', null)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    $righe = RicambioUtilizzo::withoutGlobalScopes()->orderBy('id')->get();

    expect($righe)->toHaveCount(2)
        ->and($righe[0]->fornitore_id)->toBe($this->alfa->id)
        // Facoltativo: un pezzo si registra anche senza dire da chi arriva.
        ->and($righe[1]->fornitore_id)->toBeNull();
});

it('keeps one selector open at a time, and writes in the row it was opened from', function () {
    ($this->formConRicambi)($this->admin, 2)
        ->call('apriFornitori', 'ricambio.0')
        ->set('cercaFornitore', 'alfa')
        ->call('apriFornitori', 'ricambio.1')
        ->assertSet('selettoreFornitore', 'ricambio.1')
        // Aprirne un altro riparte da capo: la ricerca era dell'altra riga.
        ->assertSet('cercaFornitore', '')
        ->assertSeeHtml('data-pannello-fornitori="ricambio.1"')
        ->assertDontSeeHtml('data-pannello-fornitori="ricambio.0"')
        ->call('scegliFornitore', $this->beta->id)
        ->assertSet('ricambiNuovi.0.fornitore_id', null)
        ->assertSet('ricambiNuovi.1.fornitore_id', $this->beta->id);
});

it('creates a missing supplier from the row of the part, and goes on with the intervention', function () {
    $form = ($this->formConRicambi)($this->admin)
        ->call('apriFornitori', 'ricambio.0')
        ->set('cercaFornitore', 'Delta Ricambi')
        ->call('apriNuovoFornitore')
        ->call('creaFornitore')
        ->assertHasNoErrors();

    $delta = Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Delta Ricambi')->sole();

    expect($delta->tenant_id)->toBe($this->ente->id);

    // 🔴 Il form dell'intervento è ancora aperto, con ciò che c'era scritto.
    $form->assertSet('ricambiNuovi.0.fornitore_id', $delta->id)
        ->assertSet('showInterventoForm', true)
        ->assertSet('ricambiNuovi.0.nome', 'Pezzo 1')
        ->assertSet('interventoForm.descrizione', 'Sostituzione pezzi')
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(RicambioUtilizzo::withoutGlobalScopes()->sole()->fornitore_id)->toBe($delta->id);
});

it('refuses a forged supplier on a part row, and writes nothing of the intervention', function (string $quale) {
    // La property è pubblica: l'id ci arriva senza passare dal selettore.
    ($this->formConRicambi)($this->admin)
        ->set('ricambiNuovi.0.fornitore_id', $this->{$quale}->id)
        // E la pagina non ne scrive nemmeno il nome.
        ->assertDontSee('Fornitore altrui')
        ->call('saveIntervento')
        ->assertHasErrors('ricambiNuovi.0.fornitore_id');

    expect(Intervento::withoutGlobalScopes()->count())->toBe(0)
        ->and(RicambioUtilizzo::withoutGlobalScopes()->count())->toBe(0);
})->with(['altrui', 'cestinato']);

it('opens a selector only for a row that is there', function () {
    ($this->formConRicambi)($this->admin)->call('apriFornitori', 'ricambio.1')->assertNotFound();
    ($this->formConRicambi)($this->admin)->call('apriFornitori', 'ricambio.x')->assertNotFound();

    // A form chiuso le righe non sono campi.
    scheda($this->admin, $this->strumento)->call('apriFornitori', 'ricambio.0')->assertNotFound();
});

it('closes the selector when a row is removed, because the rows have just been renumbered', function () {
    ($this->formConRicambi)($this->admin, 3)
        ->call('apriFornitori', 'ricambio.2')
        ->call('removeRicambio', 0)
        // «ricambio.2» ora non esiste più, e «ricambio.1» è un'altra riga.
        ->assertSet('selettoreFornitore', null)
        ->assertDontSeeHtml('data-pannello-fornitori');
});

it('ignores a supplier sent by whoever cannot see suppliers, instead of saving it unchecked', function () {
    // 🔴 Senza `fornitori.view` la regola del campo è `nullable`: l'id non è
    // validato da nulla. Chi il campo non lo vede non lo scrive.
    $utente = ($this->senzaFornitori)();

    ($this->formConRicambi)($utente)
        ->assertDontSeeHtml('data-selettore-fornitore')
        ->set('ricambiNuovi.0.fornitore_id', $this->altrui->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(RicambioUtilizzo::withoutGlobalScopes()->sole()->fornitore_id)->toBeNull();

    ($this->formConRicambi)($utente)->call('apriFornitori', 'ricambio.0')->assertForbidden();
});

// ─── Nella correzione dal tab Ricambi ────────────────────────────────────────

it('changes the supplier of a mounted part, and takes it away, from the correction', function () {
    $pezzo = ($this->pezzoMontato)($this->alfa);

    scheda($this->admin, $this->strumento)
        ->call('openCorreggiRicambio', $pezzo->id)
        ->assertSet('ricambioForm.fornitore_id', $this->alfa->id)
        ->assertSee('Alfa Ricambi')
        ->call('apriFornitori', 'correzione')
        ->call('scegliFornitore', $this->beta->id)
        ->assertSet('ricambioForm.fornitore_id', $this->beta->id)
        ->call('salvaRicambio')
        ->assertHasNoErrors();

    expect($pezzo->fresh()->fornitore_id)->toBe($this->beta->id);

    // «Nessun fornitore»: si offre solo dove il campo è facoltativo e pieno.
    scheda($this->admin, $this->strumento)
        ->call('openCorreggiRicambio', $pezzo->id)
        ->call('apriFornitori', 'correzione')
        ->assertSee('Nessun fornitore')
        ->call('togliFornitore')
        ->assertSet('ricambioForm.fornitore_id', null)
        ->assertSet('selettoreFornitore', null)
        ->call('salvaRicambio')
        ->assertHasNoErrors();

    expect($pezzo->fresh()->fornitore_id)->toBeNull();
});

it('lets a correction keep a supplier that went to the bin, and refuses any other one', function () {
    $pezzo = ($this->pezzoMontato)($this->alfa);
    // `Fornitore` rifiuta di cestinarsi finché ha pezzi: qui si prova ciò che
    // resta quando è successo comunque.
    DB::table('fornitori')->where('id', $this->alfa->id)->update(['deleted_at' => now()]);

    // Correggere la quantità non deve inciampare su un campo che nessuno ha toccato.
    scheda($this->admin, $this->strumento)
        ->call('openCorreggiRicambio', $pezzo->id)
        ->assertSee('Alfa Ricambi (cestinato)')
        ->set('ricambioForm.quantita', 3)
        ->call('salvaRicambio')
        ->assertHasNoErrors();

    expect($pezzo->fresh()->fornitore_id)->toBe($this->alfa->id)
        ->and($pezzo->fresh()->quantita)->toBe(3);

    // E il selettore lo offre ancora, lui solo fra i cestinati: riaprirlo e
    // richiuderlo sulla stessa voce non deve essere un errore.
    scheda($this->admin, $this->strumento)
        ->call('openCorreggiRicambio', $pezzo->id)
        ->call('apriFornitori', 'correzione')
        ->assertSeeHtml('wire:click="scegliFornitore('.$this->alfa->id.')"')
        ->assertDontSeeHtml('wire:click="scegliFornitore('.$this->cestinato->id.')"')
        ->call('scegliFornitore', $this->alfa->id)
        ->assertSet('ricambioForm.fornitore_id', $this->alfa->id);

    // 🔴 Un altro cestinato, o quello di un altro Ente, no.
    foreach ([$this->cestinato, $this->altrui] as $vietato) {
        scheda($this->admin, $this->strumento)
            ->call('openCorreggiRicambio', $pezzo->id)
            ->set('ricambioForm.fornitore_id', $vietato->id)
            ->call('salvaRicambio')
            ->assertHasErrors('ricambioForm.fornitore_id');
    }

    expect($pezzo->fresh()->fornitore_id)->toBe($this->alfa->id);
});

it('leaves the supplier where it is when the correction is made by whoever cannot see suppliers', function () {
    // 🔴 Il Tecnico corregge i pezzi montati e non vede i fornitori: il campo
    // per lui non c'è. Se la correzione scrivesse ciò che il form porta, ogni
    // suo salvataggio cancellerebbe il fornitore indicato da un altro.
    $pezzo = ($this->pezzoMontato)($this->alfa);
    $utente = ($this->senzaFornitori)();

    scheda($utente, $this->strumento)
        ->call('openCorreggiRicambio', $pezzo->id)
        ->assertSet('ricambioForm.fornitore_id', null)
        ->assertDontSeeHtml('data-selettore-fornitore')
        ->assertDontSee('Alfa Ricambi')
        ->set('ricambioForm.quantita', 2)
        // Nemmeno forgiandolo.
        ->set('ricambioForm.fornitore_id', $this->beta->id)
        ->call('salvaRicambio')
        ->assertHasNoErrors();

    expect($pezzo->fresh()->fornitore_id)->toBe($this->alfa->id)
        ->and($pezzo->fresh()->quantita)->toBe(2);
});

it('has a correction selector only while a part is being corrected', function () {
    scheda($this->admin, $this->strumento)->call('apriFornitori', 'correzione')->assertNotFound();

    // E chiudere la correzione chiude anche il selettore rimasto aperto.
    $pezzo = ($this->pezzoMontato)($this->alfa);

    scheda($this->admin, $this->strumento)
        ->call('openCorreggiRicambio', $pezzo->id)
        ->call('apriFornitori', 'correzione')
        ->assertSet('selettoreFornitore', 'correzione')
        ->call('closeRicambioForm')
        ->assertSet('selettoreFornitore', null);
});

it('offers «no supplier» only on a part that has one', function () {
    $senza = ($this->pezzoMontato)(null, 'Cinghia');

    scheda($this->admin, $this->strumento)
        ->call('openCorreggiRicambio', $senza->id)
        ->assertSee('— Nessun fornitore —')
        ->call('apriFornitori', 'correzione')
        ->assertDontSeeHtml('wire:click="togliFornitore"');
});

it('forgets an open selector when the intervention form is closed', function () {
    ($this->formConRicambi)($this->admin)
        ->call('apriFornitori', 'ricambio.0')
        ->set('cercaFornitore', 'alfa')
        ->call('closeInterventoForm')
        ->assertSet('selettoreFornitore', null)
        ->assertSet('cercaFornitore', '');
});

// ─── Dove si legge ───────────────────────────────────────────────────────────

it('shows the supplier of each mounted part in the tab, in one query however many parts there are', function () {
    ($this->pezzoMontato)($this->alfa, 'Guarnizione');
    ($this->pezzoMontato)($this->beta, 'Filtro');
    ($this->pezzoMontato)($this->alfa, 'Valvola');
    ($this->pezzoMontato)(null, 'Cinghia');

    $this->actingAs($this->admin);

    DB::enableQueryLog();
    $html = $this->get(route('strumenti.show', $this->strumento))->assertOk()->getContent();
    $query = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $q) => str_contains($q, 'from "fornitori"') && str_contains($q, '"fornitori"."id" in'));
    DB::disableQueryLog();

    expect($html)->toContain('Alfa Ricambi')->toContain('Beta Service')
        // Una query per tutti i fornitori dei pezzi, non una per riga.
        ->and($query)->toHaveCount(1);
});

it('says that the supplier of a part is in the bin, instead of leaving the cell empty', function () {
    ($this->pezzoMontato)($this->alfa);
    DB::table('fornitori')->where('id', $this->alfa->id)->update(['deleted_at' => now()]);

    $this->actingAs($this->admin)
        ->get(route('strumenti.show', $this->strumento))
        ->assertOk()
        ->assertSee('Alfa Ricambi')
        ->assertSee('cestinato');
});

it('does not show the supplier column to whoever cannot see suppliers, nor reads it for them', function () {
    ($this->pezzoMontato)($this->alfa);

    $this->actingAs(($this->senzaFornitori)());

    DB::enableQueryLog();
    $risposta = $this->get(route('strumenti.show', $this->strumento));
    $query = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q) => str_contains($q, 'from "fornitori"'));
    DB::disableQueryLog();

    $risposta->assertOk()
        ->assertSee('Guarnizione')
        ->assertDontSee('Alfa Ricambi')
        // La cella e l'intestazione della colonna, non la parola: «Fornitore»
        // compare anche altrove nella scheda.
        ->assertDontSeeHtml('data-etichetta="Fornitore"')
        ->assertDontSeeHtml('>Fornitore</th>');

    // Chi non li può vedere non paga nemmeno la query.
    expect($query->all())->toBe([]);

    // Chi li può vedere, la colonna ce l'ha.
    $this->actingAs($this->admin)
        ->get(route('strumenti.show', $this->strumento))
        ->assertSeeHtml('data-etichetta="Fornitore"')
        ->assertSeeHtml('>Fornitore</th>');
});

// ─── Le due guardie del dominio ──────────────────────────────────────────────

it('refuses a mounted part pointing at a supplier of another Ente', function () {
    // Nel model e non solo nel form: vale per l'import di domani e per la
    // console. Una riga così mostrerebbe la ragione sociale di un fornitore
    // di un altro cliente.
    expect(fn () => ($this->pezzoMontato)($this->altrui))
        ->toThrow(InvalidArgumentException::class, 'il fornitore deve appartenere allo stesso Ente');

    $pezzo = ($this->pezzoMontato)($this->alfa);

    expect(fn () => $pezzo->update(['fornitore_id' => $this->altrui->id]))
        ->toThrow(InvalidArgumentException::class, 'il fornitore deve appartenere allo stesso Ente');

    expect($pezzo->fresh()->fornitore_id)->toBe($this->alfa->id);
});

it('refuses to bin a supplier that still has mounted parts, and counts them on its page', function () {
    $primo = ($this->pezzoMontato)($this->alfa, 'Guarnizione');
    $secondo = ($this->pezzoMontato)($this->alfa, 'Filtro');

    $pagina = Livewire::actingAs($this->admin)->test(ElencoFornitori::class);

    // Il numero spiega perché non si può cancellare, prima di provarci.
    expect($pagina->html())->toContain('data-ricambi="2"')->toContain('data-ricambi="0"');

    $pagina->call('confermaElimina', $this->alfa->id)
        ->call('elimina')
        ->assertSee('ancora associato a dei ricambi montati');

    expect($this->alfa->fresh()->trashed())->toBeFalse();

    // Tolti i pezzi, il fornitore si può cestinare.
    $primo->cestinaConGaranzia();
    $secondo->cestinaConGaranzia();

    Livewire::actingAs($this->admin)->test(ElencoFornitori::class)
        ->call('confermaElimina', $this->alfa->id)
        ->call('elimina');

    expect($this->alfa->fresh()->trashed())->toBeTrue();
});
