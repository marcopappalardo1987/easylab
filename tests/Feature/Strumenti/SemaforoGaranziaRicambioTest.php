<?php

use App\Enums\StatoSemaforo;
use App\Enums\TipoMotivoSemaforo;
use App\Enums\VisibilitaGaranzieRicambio;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Semaforo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * La garanzia del ricambio nel semaforo (🔗 ADR-020 — S4 blocco 4).
 *
 * File a sé, come `GaranziaDepartmentScopeTest` per il blocco 2, perché ogni
 * caso incrocia tre cose che altrove stanno separate: il **motore** (una fonte
 * in più), la **privacy** (il bypass di `GaranziaRicambioPrivacyScope`, unico
 * autorizzato del progetto) e lo **scope** (il doppio salto deve restare dentro
 * Ente e sotto-albero). Un caso qui che fallisce non dice "il semaforo è
 * sbagliato": dice quale delle tre regole si è rotta.
 *
 * Fixture: Ente A → Dip → Autoclave, con un pezzo montato («Guarnizione
 * O-Ring») e la sua garanzia. Il nome del pezzo è deliberatamente riconoscibile:
 * mezzo file lo cerca per NON trovarlo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Guarnizione O-Ring']);
    $this->utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)
        ->forRicambio($this->ricambio)
        ->create();

    $this->utente = function (string $ruolo): User {
        $u = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->admin = ($this->utente)('Admin');
});

// --- Il negativo obbligatorio di ADR-020 ---

/**
 * Il test che l'ADR chiede parola per parola, e la ragione per cui questo
 * blocco è area rossa: al Tenant resta nascosto il DATO (la riga, il nome del
 * pezzo, la scadenza come dettaglio), non il suo EFFETTO sul semaforo del
 * proprio bene. Le due metà si verificano insieme, perché passare una sola è
 * facile: nascondere tutto lascia il pallino verde e bugiardo, mostrare tutto
 * viola ADR-004.
 */
it('shows the Tenant an arancione caused only by a spare-part warranty, without ever naming the part', function () {
    Garanzia::factory()->forRicambio($this->utilizzo)->scaduta()->create();

    // Aggiornato il 15 Ago 2026 (ADR-029). Il divieto assoluto non esiste più:
    // il Tenant HA il permesso, ed è l'Ente a poterglielo restringere. Il test
    // che ADR-020 impone resta però valido e necessario — cambia solo la
    // premessa che lo mette in scena: prima era la matrice RBAC, ora è
    // l'impostazione dell'Ente. Senza il terzo stato `nascosta` questo caso non
    // sarebbe più costruibile, ed è metà della ragione per cui esiste.
    $tenant = ($this->utente)('Tenant');
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);
    $tenant = $tenant->fresh();

    expect($tenant->can('garanzie.ricambio.view'))->toBeTrue()
        ->and(Gate::forUser($tenant)->allows('view', Garanzia::class))->toBeFalse();

    // 1. L'effetto: il pallino è arancione, in tutte e tre le forme (per-model,
    //    bulk dell'elenco, header della scheda).
    $this->actingAs($tenant);
    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);

    $elenco = Livewire::actingAs($tenant)->test(ElencoStrumenti::class);
    expect($elenco->viewData('semafori')[$this->strumento->id])->toBe(StatoSemaforo::Arancione);

    $scheda = Livewire::actingAs($tenant)->test(SchedaStrumento::class, ['strumento' => $this->strumento]);
    expect($scheda->viewData('semaforo'))->toBe(StatoSemaforo::Arancione);

    // 2. Il dato: nessuna etichetta del semaforo dice di che garanzia si tratta.
    //
    // ⚠️ **Ristretto il 15 Ago 2026, consapevolmente.** Fino al tab Ricambi
    // questo caso asseriva anche l'assenza del NOME del pezzo, e passava per
    // assenza di superficie: non esisteva schermata in cui potesse comparire.
    // Il tab l'ha creata, e ha fatto emergere una contraddizione fra documenti
    // — ADR-020 diceva «non vede da nessuna parte il nome del pezzo», mentre lo
    // Schema Ruoli (nota ³) e la matrice dicono che il Tenant vede i ricambi
    // montati sulle proprie macchine. Sciolta a favore dei secondi: ADR-004
    // protegge la COPERTURA del pezzo, che è una condizione commerciale, non il
    // fatto che sulla macchina del cliente sia stata montata una guarnizione.
    // Quel che resta protetto è la garanzia, e l'etichetta che la nomina.
    $elenco->assertDontSee('Garanzia ricambio');
    $scheda->assertDontSee('Garanzia ricambio');

    // Il nome del pezzo NON compare nell'etichetta del semaforo: è lì che il
    // divieto di ADR-020 vive davvero, ed è ciò che il caso continua a coprire.
    expect($scheda->viewData('diagnosi')->motivi[0]->dettaglio)->toBeNull();

    // 3. E la riga resta invisibile alla lettura normale: il bypass vive nella
    //    query del semaforo, non è diventato un permesso.
    $this->actingAs($tenant);
    expect(Garanzia::count())->toBe(0);
});

it('names the source only to whoever holds garanzie.ricambio.view', function () {
    Garanzia::factory()->forRicambio($this->utilizzo)->imminente()->create();

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSee('Garanzia ricambio tra');
    Livewire::actingAs($this->admin)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Garanzia ricambio in scadenza il');

    // Il degrado si vede ora sul Tenant di un Ente `nascosta` (ADR-029): è chi
    // il titolo a vederle non ce l'ha, e a cui il pallino va spiegato lo stesso.
    $tenant = ($this->utente)('Tenant');
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);
    $tenant = $tenant->fresh();

    Livewire::actingAs($tenant)->test(ElencoStrumenti::class)
        ->assertSee('Garanzia tra')->assertDontSee('Garanzia ricambio tra');
    Livewire::actingAs($tenant)->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Garanzia in scadenza il')->assertDontSee('Garanzia ricambio in scadenza il');
});

// --- I confini della soglia, sulla nuova fonte ---

it('lights the semaforo for a scaduta or imminente spare-part warranty, and not for an attiva one', function (string $stato, StatoSemaforo $atteso) {
    Garanzia::factory()->forRicambio($this->utilizzo)->{$stato}()->create();

    $this->actingAs($this->admin);

    expect($this->strumento->statoSemaforoCalcolato())->toBe($atteso);
})->with([
    'scaduta' => ['scaduta', StatoSemaforo::Arancione],
    'imminente' => ['imminente', StatoSemaforo::Arancione],
    'attiva' => ['attiva', StatoSemaforo::Verde],
]);

it('uses the same soglia as every other source, with the same inclusive boundary', function () {
    // Ultimo giorno dentro la soglia → arancione; il giorno dopo → verde.
    // I due casi stanno insieme perché è il confine a essere la regola, non i
    // singoli valori: un `<` al posto di `<=` sposta esattamente questo.
    $limite = today()->addDays(Semaforo::giorniImminente());

    $garanzia = Garanzia::factory()->forRicambio($this->utilizzo)
        ->scadenzaDichiarata($limite->toDateString())->create();

    $this->actingAs($this->admin);
    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);

    $garanzia->fissaScadenzaDichiarata($limite->copy()->addDay())->save();

    expect($this->strumento->fresh()->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde);
});

// --- Righe cestinate: la consegna n.1 del docblock di RicambioUtilizzo ---

it('ignores a spare part that has been unmounted (soft-deleted utilizzo)', function () {
    Garanzia::factory()->forRicambio($this->utilizzo)->scaduta()->create();

    $this->actingAs($this->admin);
    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);

    // Un pezzo smontato per errore non deve continuare ad accendere l'arancione.
    $this->utilizzo->delete();

    expect($this->strumento->fresh()->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde)
        ->and(Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
            ->viewData('semafori')[$this->strumento->id])->toBe(StatoSemaforo::Verde);
});

/**
 * ADR-020 dice «pezzi **montati**», e dal 9 Ago 2026 `ricambio_utilizzo.data`
 * distingue davvero: NULL = registrato ma non ancora installato, perché la data
 * di montaggio segue la chiusura dell'intervento. Un pezzo che non è sulla
 * macchina non ne descrive lo stato.
 *
 * Non è un caso di laboratorio: è **esattamente** ciò che il form produce
 * registrando un ricambio su un intervento pianificato, ed è stato scoperto
 * provandolo in browser — un intervento datato 2027 accendeva l'arancione oggi.
 */
it('ignores a spare part registered but not yet mounted, and counts it once it is', function () {
    $this->utilizzo->update(['data' => null]); // intervento ancora aperto
    Garanzia::factory()->forRicambio($this->utilizzo)->scaduta()->create();

    $this->actingAs($this->admin);

    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde)
        ->and($this->strumento->scadenzeGaranzieRicambi())->toBeEmpty()
        ->and(Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
            ->viewData('semafori')[$this->strumento->id])->toBe(StatoSemaforo::Verde);

    // Chiuso l'intervento il pezzo è montato, e da quel momento pesa.
    $this->utilizzo->update(['data' => today()->toDateString()]);

    expect($this->strumento->fresh()->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione)
        ->and(Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
            ->viewData('semafori')[$this->strumento->id])->toBe(StatoSemaforo::Arancione);
});

it('ignores a soft-deleted garanzia', function () {
    $garanzia = Garanzia::factory()->forRicambio($this->utilizzo)->scaduta()->create();

    $this->actingAs($this->admin);
    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);

    $garanzia->delete();

    expect($this->strumento->fresh()->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde);
});

// --- Tenancy e livello 2: il bypass toglie UNO scope, non tutti ---

it('never lets a spare-part warranty of another Ente light a semaforo', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create(['nome' => 'Autoclave B']);
    $utilizzoB = RicambioUtilizzo::factory()
        ->forStrumento($strumentoB)
        ->forRicambio(Ricambio::factory()->forTenant($enteB)->create())
        ->create();
    Garanzia::factory()->forRicambio($utilizzoB)->scaduta()->create();

    $this->actingAs($this->admin);

    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde)
        ->and($this->strumento->scadenzeGaranzieRicambi())->toBeEmpty();
});

it('keeps the spare-part source inside the Responsabile sotto-albero', function () {
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 2']);
    $fuori = Strumento::factory()->forNode($altroDept)->create(['nome' => 'Fuori sotto-albero']);
    $utilizzoFuori = RicambioUtilizzo::factory()
        ->forStrumento($fuori)
        ->forRicambio(Ricambio::factory()->forTenant($this->ente)->create())
        ->create();
    Garanzia::factory()->forRicambio($utilizzoFuori)->scaduta()->create();
    Garanzia::factory()->forRicambio($this->utilizzo)->scaduta()->create();

    $resp = ($this->utente)('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);

    $semafori = Livewire::actingAs($resp)->test(ElencoStrumenti::class)->viewData('semafori');

    // Lo strumento fuori dal sotto-albero non compare nemmeno in elenco: quel
    // che conta è che il suo pezzo non abbia acceso il semaforo di nessun altro.
    expect($semafori)->toHaveKey($this->strumento->id)
        ->and($semafori)->not->toHaveKey($fuori->id)
        ->and($semafori[$this->strumento->id])->toBe(StatoSemaforo::Arancione);
});

// --- Elenco: bulk, filtro, ordinamenti ---

it('keeps the elenco bulk aligned with the per-model calculation on spare-part warranties', function () {
    Garanzia::factory()->forRicambio($this->utilizzo)->scaduta()->create();

    // Uno strumento con SOLO garanzia macchina e uno del tutto pulito, per
    // verificare che la terza fonte non sporchi le altre due.
    $conMacchina = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Con macchina']);
    Garanzia::factory()->forStrumento($conMacchina)->imminente()->create();
    $pulito = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Pulito']);

    $semafori = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)->viewData('semafori');

    $this->actingAs($this->admin);
    foreach (Strumento::all() as $s) {
        expect($semafori[$s->id])->toBe($s->statoSemaforoEffettivo(), "Disallineamento su «{$s->nome}»");
    }

    expect($semafori[$this->strumento->id])->toBe(StatoSemaforo::Arancione)
        ->and($semafori[$conMacchina->id])->toBe(StatoSemaforo::Arancione)
        ->and($semafori[$pulito->id])->toBe(StatoSemaforo::Verde);
});

it('keeps the SQL filter aligned with the pallino, for both stati', function () {
    Garanzia::factory()->forRicambio($this->utilizzo)->scaduta()->create();
    Strumento::factory()->forNode($this->dept)->create(['nome' => 'Pulito']);

    $this->actingAs($this->admin);

    foreach ([StatoSemaforo::Verde, StatoSemaforo::Arancione] as $stato) {
        $filtrati = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
            ->set('stato', $stato->value)
            ->viewData('strumenti')->pluck('id')->all();

        $attesi = Strumento::all()
            ->filter(fn (Strumento $s) => $s->statoSemaforoEffettivo() === $stato)
            ->pluck('id')->all();

        expect($filtrati)->toEqualCanonicalizing($attesi, "Filtro «{$stato->value}» disallineato dal pallino");
    }
});

it('sorts by stato and by prossima scadenza with the spare-part warranty as the minimum', function () {
    // L'Autoclave ha SOLO la garanzia del pezzo, scaduta: deve risultare
    // arancione nell'ordinamento per stato e prima di tutti in quello per
    // scadenza. Senza la terza fonte finirebbe fra i verdi e in fondo.
    Garanzia::factory()->forRicambio($this->utilizzo)->scaduta()->create();

    $conIntervento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Con intervento']);
    Intervento::factory()->forStrumento($conIntervento)
        ->create(['data_scadenza' => today()->addDays(10)->toDateString()]);

    $senzaNulla = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Senza nulla']);

    $perStato = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('sortBy', 'stato')->set('sortDir', 'desc')
        ->viewData('strumenti')->pluck('id')->all();

    expect(array_slice($perStato, 0, 2))->toEqualCanonicalizing([$this->strumento->id, $conIntervento->id])
        ->and(end($perStato))->toBe($senzaNulla->id);

    $perScadenza = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('sortBy', 'prossima_scadenza')->set('sortDir', 'asc')
        ->viewData('strumenti')->pluck('id')->all();

    // Chi non ha scadenze resta in fondo in ENTRAMBE le direzioni: è assenza di
    // dato, non un valore (ed è ciò che la sentinella deve preservare).
    expect($perScadenza)->toBe([$this->strumento->id, $conIntervento->id, $senzaNulla->id]);

    $perScadenzaDesc = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('sortBy', 'prossima_scadenza')->set('sortDir', 'desc')
        ->viewData('strumenti')->pluck('id')->all();

    expect($perScadenzaDesc)->toBe([$conIntervento->id, $this->strumento->id, $senzaNulla->id]);
});

it('reads the spare-part warranties in a constant number of queries (no N+1)', function () {
    $queryGaranzie = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->admin)->test(ElencoStrumenti::class);
        $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "garanzie"'))->count();
        DB::disableQueryLog();

        return $n;
    };

    Garanzia::factory()->forRicambio($this->utilizzo)->scaduta()->create();
    $conUno = $queryGaranzie();

    foreach (range(1, 5) as $i) {
        $s = Strumento::factory()->forNode($this->dept)->create();
        $u = RicambioUtilizzo::factory()->forStrumento($s)
            ->forRicambio(Ricambio::factory()->forTenant($this->ente)->create())
            ->create();
        Garanzia::factory()->forRicambio($u)->scaduta()->create();
        Garanzia::factory()->forStrumento($s)->imminente()->create();
    }

    expect($queryGaranzie())->toBe($conUno);
});

// --- Panoramica ---

it('explains the arancione in Panoramica, neutrally and without a link, to whoever lacks the permission', function () {
    Garanzia::factory()->forRicambio($this->utilizzo)->scaduta()->create();

    $this->actingAs($this->admin);
    $diagnosi = $this->strumento->diagnosiSemaforo();

    expect($diagnosi->stato)->toBe(StatoSemaforo::Arancione)
        ->and($diagnosi->motivi)->toHaveCount(1)
        ->and($diagnosi->motivi[0]->tipo)->toBe(TipoMotivoSemaforo::GaranziaRicambio)
        ->and($diagnosi->motivi[0]->scaduto)->toBeTrue()
        // Il dettaglio è null per costruzione: `scadenzeGaranzieRicambi()` non
        // seleziona nemmeno le colonne da cui si risalirebbe al pezzo.
        ->and($diagnosi->motivi[0]->dettaglio)->toBeNull();

    // Il motivo c'è per entrambi — il pallino è dovuto a tutti — ma il Tenant
    // di un Ente `nascosta` non riceve né il nome della fonte né la freccia.
    $tenant = ($this->utente)('Tenant');
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);
    Livewire::actingAs($tenant->fresh())->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->assertSee('Motivi (1)')
        ->assertSee('Garanzia scaduta il')
        ->assertDontSee('Garanzia ricambio scaduta il');
});
