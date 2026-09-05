<?php

use App\Enums\VisibilitaGaranzieRicambio;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * Tab «Ricambi»: lettura e correzione dei pezzi montati (🔗 ADR-008/022,
 * sopra 🔗 ADR-020 e 🔗 ADR-029 — S4, 15 Ago 2026).
 *
 * Il tab incrocia tre regole che finora vivevano separate, e i casi qui sotto
 * sono organizzati per quella distinzione:
 *
 * 1. **la data di montaggio** — chi la scrive, e chi non può più riscriverla;
 * 2. **la cancellazione** — pezzo e garanzia spariscono insieme, o resta una
 *    scadenza che accende il semaforo di un pezzo che non c'è più (ADR-020);
 * 3. **la visibilità** — il tab passa dalle ability della Policy e mai dai
 *    permessi nudi, o l'impostazione dell'Ente (ADR-029) viene scavalcata.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Guarnizione O-Ring']);
    // `->pianificato()` e non la scadenza di default: quella è `addMonth()`,
    // che in un mese da 30 giorni cade ESATTAMENTE sulla soglia e tiene acceso
    // l'arancione anche dopo che il pezzo è stato cestinato.
    $this->intervento = Intervento::factory()->forStrumento($this->strumento)->pianificato()->create();

    $this->utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)
        ->forRicambio($this->ricambio)
        ->forIntervento($this->intervento)
        ->create();

    $this->garanzia = Garanzia::factory()->forRicambio($this->utilizzo)
        ->scadenzaDichiarata(today()->addYear()->toDateString())->create();

    $this->utente = function (string $ruolo): User {
        $u = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->admin = ($this->utente)('Admin');
    $this->scheda = fn (User $u) => Livewire::actingAs($u)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumento]);
});

// --- Lettura ---

it('shows the mounted parts, with what serves to make sense of them', function () {
    ($this->scheda)($this->admin)
        ->assertSee('Guarnizione O-Ring')
        ->assertSee($this->intervento->descrizione)
        ->assertSee($this->garanzia->data_scadenza_effettiva->format('d/m/Y'));
});

it('says out loud when a part is not mounted yet, instead of inventing a date', function () {
    $this->utilizzo->update(['data' => null]);

    // Una data inventata al posto di un'assenza è la lezione pagata il 9 Ago:
    // il pezzo registrato su un intervento aperto non è montato, e il semaforo
    // infatti non lo conta.
    ($this->scheda)($this->admin)->assertSee('Montaggio ancora non effettuato');
});

// --- La data di montaggio, e chi la protegge ---

it('marks a hand-corrected date, and stops the closing from overwriting it', function () {
    $this->utilizzo->update(['data' => null]);

    ($this->scheda)($this->admin)
        ->call('openCorreggiRicambio', $this->utilizzo->id)
        ->set('ricambioForm.data', '2026-03-10')
        ->call('salvaRicambio')
        ->assertHasNoErrors();

    expect($this->utilizzo->fresh()->data->toDateString())->toBe('2026-03-10')
        ->and($this->utilizzo->fresh()->data_manuale)->toBeTrue();

    // Chiudere l'intervento NON deve riscriverla: è la ragione per cui la
    // colonna esiste, e senza questo caso resterebbe una promessa.
    $this->actingAs($this->admin);
    $this->intervento->segnaFatto(today());

    expect($this->utilizzo->fresh()->data->toDateString())->toBe('2026-03-10');
});

it('still lets the closing fill an automatic date', function () {
    $this->utilizzo->update(['data' => null]);
    $this->actingAs($this->admin);

    $this->intervento->segnaFatto(today());

    // Il contrappeso del caso precedente: proteggere la correzione a mano non
    // deve rendere la chiusura incapace di fare il proprio lavoro.
    expect($this->utilizzo->fresh()->data->toDateString())->toBe(today()->toDateString())
        ->and($this->utilizzo->fresh()->data_manuale)->toBeFalse();
});

it('lets a part be marked as not mounted again', function () {
    ($this->scheda)($this->admin)
        ->call('openCorreggiRicambio', $this->utilizzo->id)
        ->call('segnaNonMontato')
        ->call('salvaRicambio')
        ->assertHasNoErrors();

    expect($this->utilizzo->fresh()->data)->toBeNull();
});

it('moves the warranty start with the mounting date, but never past its expiry', function () {
    // La regola viveva dentro un metodo protected di Intervento: spostandola su
    // RicambioUtilizzo vale anche per la correzione a mano. Il confine resta:
    // spostare l'inizio oltre la scadenza farebbe fallire il salvataggio per un
    // refuso, e un dato informativo incoerente è meno grave di un gesto
    // impossibile da completare.
    // ⚠️ **Relative a `now()`, non assolute.** Con `'2026-06-30'` scritto a mano
    // il test sarebbe **errato** (non fallito) dal 1° Gen 2027: il `beforeEach`
    // dà alla garanzia una scadenza a `today()->addYear()`, e da quella data una
    // scadenza dichiarata nel 2026 cadrebbe prima di `data_inizio`, che
    // l'invariante di ADR-022 rifiuta. Ciò che il caso misura è un **ordine fra
    // tre date**, non tre date.
    $scadenza = today()->addMonths(6);
    $dentro = $scadenza->copy()->subMonths(3);
    $oltre = $scadenza->copy()->addMonth();

    $this->garanzia->fissaScadenzaDichiarata($scadenza->toDateString())->save();

    ($this->scheda)($this->admin)
        ->call('openCorreggiRicambio', $this->utilizzo->id)
        ->set('ricambioForm.data', $dentro->toDateString())
        ->call('salvaRicambio');

    expect($this->garanzia->fresh()->data_inizio->toDateString())->toBe($dentro->toDateString());

    // Oltre la scadenza: la garanzia resta com'è, il salvataggio riesce.
    ($this->scheda)($this->admin)
        ->call('openCorreggiRicambio', $this->utilizzo->id)
        ->set('ricambioForm.data', $oltre->toDateString())
        ->call('salvaRicambio')
        ->assertHasNoErrors();

    expect($this->garanzia->fresh()->data_inizio->toDateString())->toBe($dentro->toDateString())
        ->and($this->utilizzo->fresh()->data->toDateString())->toBe($oltre->toDateString());
});

// --- La cancellazione, e il semaforo ---

it('bins the part and its warranty together, and the semaforo goes quiet', function () {
    $this->garanzia->fissaScadenzaDichiarata(today()->addDays(5)->toDateString())->save();
    $this->actingAs($this->admin);

    expect($this->strumento->statoSemaforoCalcolato()->value)->toBe('arancione');

    ($this->scheda)($this->admin)
        ->call('openRimuoviRicambio', $this->utilizzo->id)
        ->call('rimuoviRicambio');

    expect($this->utilizzo->fresh()->trashed())->toBeTrue()
        ->and($this->garanzia->fresh()->trashed())->toBeTrue()
        ->and($this->strumento->fresh()->statoSemaforoCalcolato()->value)->toBe('verde');
});

it('bins the parts when the whole intervento is deleted', function () {
    // Il difetto trovato dall'audit del 15 Ago: `eliminaIntervento()` cestinava
    // il solo intervento, e la garanzia del pezzo continuava ad accendere il
    // semaforo per un montaggio che nella scheda non risultava più.
    $this->garanzia->fissaScadenzaDichiarata(today()->addDays(5)->toDateString())->save();
    $this->actingAs($this->admin);

    ($this->scheda)($this->admin)
        ->call('openEliminaIntervento', $this->intervento->id)
        ->call('eliminaIntervento');

    expect($this->intervento->fresh()->trashed())->toBeTrue()
        ->and($this->utilizzo->fresh()->trashed())->toBeTrue()
        ->and($this->garanzia->fresh()->trashed())->toBeTrue()
        ->and($this->strumento->fresh()->statoSemaforoCalcolato()->value)->toBe('verde');
});

// --- La visibilità: le ability della Policy, mai i permessi nudi ---

it('hides the warranty column from whoever has no title to see it', function () {
    $tenant = ($this->utente)('Tenant');
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);

    // Vede il pezzo (ha `ricambio_utilizzo.view`) ma non la sua garanzia: sono
    // due permessi diversi, e l'impostazione dell'Ente governa il secondo.
    ($this->scheda)($tenant->fresh())
        ->assertSee('Guarnizione O-Ring')
        ->assertDontSee($this->garanzia->data_scadenza_effettiva->format('d/m/Y'));
});

it('leaves the Tenant with a read-only tab, whatever the Ente setting says', function () {
    // ⚠️ **Incongruenza della matrice, trovata da questo test e non da una
    // rilettura** (segnalata a Marco il 15 Ago 2026): ADR-029 ha dato al Tenant
    // `garanzie.ricambio.manage`, ma la matrice non gli dà
    // `ricambio_utilizzo.update` né `.delete`. Può quindi gestire la GARANZIA
    // del pezzo e non il PEZZO — e siccome la correzione tocca entrambi, nel
    // tab non ha nessuna azione: il permesso che ADR-029 gli ha concesso resta
    // teorico finché non esiste una schermata da cui esercitarlo.
    //
    // Il caso congela il comportamento ATTUALE, non lo approva. Se la matrice
    // cambierà, questo test va riscritto consapevolmente, non adeguato.
    $tenant = ($this->utente)('Tenant');

    expect($tenant->can('garanzie.ricambio.manage'))->toBeTrue()
        ->and($tenant->can('ricambio_utilizzo.update'))->toBeFalse();

    ($this->scheda)($tenant)
        ->assertSee('Guarnizione O-Ring')
        ->assertDontSee('Correggi')
        ->call('openCorreggiRicambio', $this->utilizzo->id)
        ->assertForbidden();
});

it('refuses the correction when the Ente setting takes the writing away', function () {
    // Il caso che protegge dal permesso NUDO: `salvaRicambio` chiede l'ability
    // della Policy, non `garanzie.ricambio.manage`. Costruito su un ruolo
    // sintetico — un Tenant con i permessi sul pezzo — perché nessun ruolo
    // reale li ha insieme oggi (vedi il caso precedente); serve comunque, o il
    // giorno in cui la matrice cambierà il buco si aprirebbe in silenzio.
    $utente = ($this->utente)('Tenant');
    $utente->givePermissionTo('ricambio_utilizzo.update');
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Lettura);

    ($this->scheda)($utente->fresh())
        ->call('openCorreggiRicambio', $this->utilizzo->id)
        ->call('salvaRicambio')
        ->assertForbidden();

    expect($this->utilizzo->fresh()->data_manuale)->toBeFalse();
});

it('allows it again when the Ente setting gives the writing back', function () {
    // Contrappeso del precedente: senza, il divieto sarebbe soddisfatto anche
    // da una negazione totale — cioè dalla regola che ADR-029 ha superato.
    $utente = ($this->utente)('Tenant');
    $utente->givePermissionTo('ricambio_utilizzo.update');

    expect($this->ente->visibilita_garanzie_ricambio)->toBe(VisibilitaGaranzieRicambio::Modifica);

    ($this->scheda)($utente->fresh())
        ->call('openCorreggiRicambio', $this->utilizzo->id)
        ->set('ricambioForm.quantita', 3)
        ->call('salvaRicambio')
        ->assertHasNoErrors();

    expect($this->utilizzo->fresh()->quantita)->toBe(3);
});

// --- Negativi di tenancy ---

it('gives 404 for a part of another machine', function () {
    $altra = Strumento::factory()->forNode($this->dept)->create();
    $suoUtilizzo = RicambioUtilizzo::factory()
        ->forStrumento($altra)
        ->forRicambio($this->ricambio)
        ->create();

    expect(fn () => ($this->scheda)($this->admin)->call('openCorreggiRicambio', $suoUtilizzo->id))
        ->toThrow(ModelNotFoundException::class);
});

it('gives 404 for a part of another Ente', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create();
    $utilizzoB = RicambioUtilizzo::factory()
        ->forStrumento($strumentoB)
        ->forRicambio(Ricambio::factory()->forTenant($altroEnte)->create())
        ->create();

    expect(fn () => ($this->scheda)($this->admin)->call('openRimuoviRicambio', $utilizzoB->id))
        ->toThrow(ModelNotFoundException::class);
});
