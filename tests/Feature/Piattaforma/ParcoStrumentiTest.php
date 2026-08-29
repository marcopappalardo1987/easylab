<?php

use App\Enums\StatoSemaforo;
use App\Livewire\Piattaforma\ParcoGlobale;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Account;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piani;
use App\Support\Piattaforma\Perimetro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * 🔴 Il **Parco clienti — scheda Strumenti** (🔗 ADR-037), area rossa.
 *
 * La scheda mostra le macchine di clienti che non sono il proprio: è la prima
 * superficie del progetto in cui l'isolamento non è più una proprietà del
 * framework — il global scope che filtra e basta — ma una proprietà del
 * **codice**, cioè «corretto se il filtro è scritto bene». Va difesa con dei
 * test, e i negativi contano più dei positivi.
 *
 * Quattro famiglie, e nessuna copre le altre:
 *
 *   1. **Il permesso**: senza `tenants.view_all` la pagina è un 403.
 *   2. **Il perimetro**: arriva dal browser, quindi ogni forgiatura — id
 *      inventato, id di EasyLab, piano inesistente, modo inesistente, selezione
 *      vuota — deve **restringere o non cambiare nulla**, mai allargare.
 *   3. **Di CHI sono i dati**: cliente e sede su ogni riga, ed è il requisito
 *      posto per primo dal committente. Su una vista cross-cliente l'errore che
 *      costa non è sbagliare macchina, è sbagliare cliente.
 *   4. **Il semaforo cross-cliente**: la trappola specifica di questa scheda.
 *      La forma SQL della regola è scopata per tenant e classificherebbe come
 *      **verdi** le macchine altrui — un elenco plausibile e sbagliato, che
 *      nessun conteggio smaschererebbe. I test lo attaccano sulle tre fonti.
 *
 * ⚠️ Più due invarianti **strutturali**, che nessun test comportamentale può
 * cogliere: l'ordinamento porta sempre il tie-break sull'id (si prova
 * sull'**SQL**, non sui dati, perché senza il tie-break la suite resta verde a
 * seconda del piano scelto dal motore) e da questa scheda non si scrive.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Due clienti su due piani, ciascuno con la sua sede e le sue macchine.
    // «Gruppo Rossi» viene prima di «Lab Bianchi» in ordine alfabetico: serve a
    // rendere prevedibile l'ordinamento predefinito, che è per cliente.
    $this->rossi = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi', 'piano' => 'free']);
    $this->sedeRossi = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede Rossi']);
    $this->autoclaveRossi = Strumento::factory()->forNode($this->sedeRossi)->create([
        'nome' => 'Autoclave Rossi', 'modello' => 'AR-100', 'matricola' => 'SN-ROSSI-1',
    ]);

    $this->bianchi = Account::factory()->saas()->create(['ragione_sociale' => 'Lab Bianchi']);
    $this->sedeBianchi = UnitaOrganizzativa::factory()->ente()->perAccount($this->bianchi)->create(['nome' => 'Sede Bianchi']);
    $this->autoclaveBianchi = Strumento::factory()->forNode($this->sedeBianchi)->create([
        'nome' => 'Autoclave Bianchi', 'modello' => 'AB-200', 'matricola' => 'SN-BIANCHI-1',
    ]);

    // EasyLab stessa: un Ente e una macchina che non sono di nessun cliente.
    $this->easylab = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $this->sedeEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($this->easylab)->create(['nome' => 'Sede EasyLab']);
    $this->autoclaveEasylab = Strumento::factory()->forNode($this->sedeEasylab)->create(['nome' => 'Autoclave EasyLab']);

    // Un membro per cliente: senza, la colonna «Azioni» non avrebbe candidati e
    // metà dei test sull'impersonazione proverebbero il ramo sbagliato.
    $this->adminRossi = User::factory()->create(['tenant_id' => $this->sedeRossi->id, 'name' => 'Anna Rossi']);
    $this->adminRossi->assignRole('Admin');
    $this->rossi->aggiungiMembro($this->adminRossi);

    $this->adminBianchi = User::factory()->create(['tenant_id' => $this->sedeBianchi->id, 'name' => 'Bruno Bianchi']);
    $this->adminBianchi->assignRole('Admin');
    $this->bianchi->aggiungiMembro($this->adminBianchi);

    $this->superadmin = User::factory()->create([
        'tenant_id' => $this->sedeEasylab->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->superadmin->assignRole('Superadmin');
    $this->easylab->aggiungiMembro($this->superadmin);

    // Un cliente con una sede e **nessuna macchina**: serve a distinguere
    // «perimetro vuoto» da «parco vuoto» senza cancellare righe altrui.
    $this->verdi = Account::factory()->create(['ragione_sociale' => 'Studio Verdi', 'piano' => 'free']);
    $this->sedeVerdi = UnitaOrganizzativa::factory()->ente()->perAccount($this->verdi)->create(['nome' => 'Sede Verdi']);
});

/**
 * Il Superadmin al comando — e si chiama **dopo** aver creato le fixture.
 *
 * ⚠️ `BelongsToTenant::creating()` **forza** `tenant_id` al tenant di chi
 * scrive: una macchina o un intervento creati mentre si è autenticati come
 * Superadmin nascono nell'Ente di EasyLab, non in quello che la factory ha
 * chiesto — e la fixture finisce fuori dal perimetro, rendendo verde un test
 * che non ha provato niente. È già successo scrivendo questo file.
 */
function alComandoDelParco(User $superadmin): void
{
    test()->actingAs($superadmin->fresh());
}

/**
 * Il frammento di HTML di **una riga**, ritagliato sulla sua `wire:key`.
 *
 * 🔴 Cercare un link sulla pagina intera non prova a quale riga appartenga: su
 * questa scheda il rischio non è sbagliare macchina, è entrare in casa del
 * cliente sbagliato — e un `assertSee` su tutta la pagina resta verde anche se
 * ogni bottone «Impersona» punta al primo cliente dell'elenco.
 */
function rigaDelParco(string $html, int $strumentoId): string
{
    // La virgoletta chiude la chiave: senza, `parco-str-3` combacerebbe anche
    // con `parco-str-30`.
    $inizio = strpos($html, 'parco-str-'.$strumentoId.'"');

    expect($inizio)->not->toBeFalse();

    $fine = strpos($html, '</tr>', $inizio);

    return substr($html, $inizio, $fine - $inizio);
}

// ─── 1. Il permesso: 403, non un elenco vuoto ────────────────────────────────

it('closes the door to every role without the platform permission', function (string $ruolo) {
    // Un elenco vuoto si leggerebbe come «i clienti non hanno macchine»: la
    // forma peggiore di negare, perché è silenziosa e plausibile.
    $utente = User::factory()->create(['tenant_id' => $this->sedeRossi->id, 'two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente)->get(route('piattaforma.parco'))->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('opens for the roles that see beyond their own Ente', function (string $ruolo) {
    $this->actingAs(utenteConRuolo($ruolo))->get(route('piattaforma.parco'))->assertOk();
})->with(RUOLI_CON_PIATTAFORMA);

it('sends a guest to the login instead of showing the parco', function () {
    $this->get(route('piattaforma.parco'))->assertRedirect(route('login'));
});

// ─── 2. Le righe di tutti i clienti, e solo dei clienti ──────────────────────

it('lists the machines of every client, not just the ones of the acting user', function () {
    // Il Superadmin è membro di EasyLab e il suo Ente attivo è la sede EasyLab:
    // senza la porta vedrebbe una macchina sola, la propria.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->assertSee('Autoclave Rossi')
        ->assertSee('Autoclave Bianchi');
});

it('never shows the platform own machines among the clients ones', function () {
    // EasyLab non è un cliente: `VistaPiattaforma::accounts()` la esclude per
    // costruzione, e la scheda non deve rimetterla dentro «per completezza».
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->assertDontSee('Autoclave EasyLab');
});

it('drops the machines of a client that has been soft deleted', function () {
    $this->bianchi->delete();

    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->assertSee('Autoclave Rossi')
        ->assertDontSee('Autoclave Bianchi');
});

// ─── 3. Di CHI sono i dati: cliente e sede su ogni riga ──────────────────────

it('names the client and the site on every single row', function () {
    // 🔴 Il requisito posto per primo dal committente, ed è anche ciò che
    // impedisce di agire sul cliente sbagliato. Non basta che i nomi compaiano
    // da qualche parte: nessuna riga può esserne priva.
    alComandoDelParco($this->superadmin);

    $componente = Livewire::test(ParcoGlobale::class);

    $componente->assertSee('Gruppo Rossi')->assertSee('Sede Rossi')
        ->assertSee('Lab Bianchi')->assertSee('Sede Bianchi');

    $righe = $componente->viewData('strumenti')->getCollection();

    expect($righe)->not->toBeEmpty();

    foreach ($righe as $riga) {
        expect($riga->cliente_nome)->not->toBeNull()
            ->and($riga->sede_nome)->not->toBeNull();
    }
});

it('reads the client name from the join and not from a tenant scoped relation', function () {
    // ⚠️ `Strumento::tenant` punta a `UnitaOrganizzativa`, che porta
    // `TenantScope`: un eager load lo risolverebbe a NULL per ogni cliente che
    // non è il proprio — cioè per quasi tutta la pagina — e la colonna direbbe
    // «—» senza lamentarsi. Qui si prova che il nome arriva comunque.
    alComandoDelParco($this->superadmin);

    $riga = Livewire::test(ParcoGlobale::class)
        ->viewData('strumenti')
        ->getCollection()
        ->firstWhere('id', $this->autoclaveBianchi->id);

    expect($riga)->not->toBeNull()
        ->and($riga->cliente_nome)->toBe('Lab Bianchi')
        ->and($riga->sede_nome)->toBe('Sede Bianchi')
        ->and($riga->cliente_id)->toBe($this->bianchi->id);
});

it('keeps the sites of one client apart, and counts the rows once each', function () {
    // 🔴 ADR-032: un Account può avere N Enti, ed è il solo caso in cui la
    // colonna «Sede» — il requisito posto per primo dal committente —
    // distingue qualcosa. Con un Ente per cliente «sede» e «cliente» dicono la
    // stessa cosa, e ogni difetto che le confonde resta invisibile.
    $milano = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede Milano']);
    $torino = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede Torino']);
    $aMilano = Strumento::factory()->forNode($milano)->create(['nome' => 'Autoclave Milano']);
    $aTorino = Strumento::factory()->forNode($torino)->create(['nome' => 'Autoclave Torino']);

    alComandoDelParco($this->superadmin);

    $pagina = Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->rossi->id])
        ->viewData('strumenti');

    // ⚠️ `total()` e non `assertSee`: una riga duplicata dalle due join è
    // indistinguibile da una riga corretta a occhio, e il conteggio in fondo
    // alla pagina è l'unica cosa che la smaschera. Le due `ON` sono su chiave
    // primaria — tre macchine restano tre.
    expect($pagina->total())->toBe(3);

    $righe = $pagina->getCollection()->keyBy('id');

    expect($righe[$aMilano->id]->sede_nome)->toBe('Sede Milano')
        ->and($righe[$aTorino->id]->sede_nome)->toBe('Sede Torino')
        ->and($righe[$this->autoclaveRossi->id]->sede_nome)->toBe('Sede Rossi')
        ->and($righe[$aMilano->id]->cliente_nome)->toBe('Gruppo Rossi')
        ->and($righe[$aTorino->id]->cliente_nome)->toBe('Gruppo Rossi');
});

// ─── 4. Il perimetro: restringe o non cambia, mai allarga ────────────────────

it('narrows to the chosen clients', function () {
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->rossi->id])
        ->assertSee('Autoclave Rossi')
        ->assertDontSee('Autoclave Bianchi');
});

it('shows nothing at all when the selection is empty, and says so', function () {
    // ⛔ «Vuoto» e «tutti» sono un carattere di distanza e nessuno dei due dà
    // errore: un `if ($scelti)` che tratta la selezione vuota come «nessun
    // filtro» mostrerebbe le righe di tutti a chi ne aveva chieste zero. È il
    // difetto già pagato dall'export che ignorava i filtri.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [])
        ->assertDontSee('Autoclave Rossi')
        ->assertDontSee('Autoclave Bianchi')
        ->assertSee('Nessun cliente selezionato');
});

it('never widens the perimeter with a forged account id', function () {
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->rossi->id + 9_999])
        ->assertDontSee('Autoclave Rossi')
        ->assertDontSee('Autoclave Bianchi');
});

it('refuses to hand over the platform rows even when its own id is picked', function () {
    // L'id di EasyLab è legittimo come intero e illegittimo come cliente:
    // l'intersezione con `VistaPiattaforma::accounts()` lo fa sparire.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->easylab->id])
        ->assertDontSee('Autoclave EasyLab')
        ->assertDontSee('Autoclave Rossi');
});

it('narrows by commercial plan', function () {
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PER_PIANO)
        ->set('piano', 'saas')
        ->assertSee('Autoclave Bianchi')
        ->assertDontSee('Autoclave Rossi');
});

it('falls to nothing, never to everything, on an input it did not understand', function (array $stato) {
    // ⚠️ La risposta a un input incomprensibile su una vista cross-cliente è
    // «niente», non «tutto». Ogni riga di questo dataset è una querystring che
    // si scrive a mano in due secondi.
    alComandoDelParco($this->superadmin);

    $componente = Livewire::test(ParcoGlobale::class);

    foreach ($stato as $proprieta => $valore) {
        $componente->set($proprieta, $valore);
    }

    $componente->assertDontSee('Autoclave Rossi')->assertDontSee('Autoclave Bianchi');
})->with([
    'un modo che non esiste' => [['modo' => 'tuttissimi']],
    'un piano fuori catalogo' => [['modo' => Perimetro::PER_PIANO, 'piano' => 'platino']],
    'il modo per piano senza piano' => [['modo' => Perimetro::PER_PIANO, 'piano' => '']],
]);

// ─── 5. Il semaforo cross-cliente: la trappola di questa scheda ──────────────

it('lights the machine of another client orange, instead of calling it green', function () {
    // 🔴 Il difetto che questa scheda rischia più di ogni altro: la forma SQL
    // del semaforo (`Strumento::conStato()`) compone sottoquery **scopate**, e
    // su un elenco cross-cliente direbbe «verde» per le macchine altrui. Il
    // conteggio tornerebbe lo stesso — plausibile e sbagliato.
    Intervento::factory()->forStrumento($this->autoclaveBianchi)->scaduto()->create();

    alComandoDelParco($this->superadmin);

    $righe = Livewire::test(ParcoGlobale::class)->viewData('righe');

    expect($righe->semaforo($this->autoclaveBianchi))->toBe(StatoSemaforo::Arancione)
        ->and($righe->semaforo($this->autoclaveRossi))->toBe(StatoSemaforo::Verde);
});

it('lights it orange for an expiring machine warranty too', function () {
    // Seconda fonte (🔗 ADR-004): una garanzia macchina già finita pesa come
    // uno scaduto-non-fatto.
    Garanzia::factory()->forStrumento($this->autoclaveBianchi)->scaduta()->create();

    alComandoDelParco($this->superadmin);

    $righe = Livewire::test(ParcoGlobale::class)->viewData('righe');

    expect($righe->semaforo($this->autoclaveBianchi))->toBe(StatoSemaforo::Arancione);
});

it('lights it orange for the warranty of a part mounted on someone else machine', function () {
    // Terza fonte (🔗 ADR-020): il doppio salto garanzie → ricambio_utilizzo →
    // strumenti. È la più facile da perdere, perché richiede di togliere il
    // privacy scope **e** di restare dentro il perimetro.
    $ricambio = Ricambio::factory()->forTenant($this->sedeBianchi)->create(['nome' => 'Guarnizione Bianchi']);
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->autoclaveBianchi)
        ->forRicambio($ricambio)
        ->create();
    Garanzia::factory()->forRicambio($utilizzo)->scaduta()->create();

    alComandoDelParco($this->superadmin);

    $righe = Livewire::test(ParcoGlobale::class)->viewData('righe');

    expect($righe->semaforo($this->autoclaveBianchi))->toBe(StatoSemaforo::Arancione);
});

it('lets the forced state win over the calculated one', function () {
    // 🔗 ADR-005 punto 5: il rosso esiste solo come forzatura manuale, e vince.
    $this->autoclaveRossi->forzaSemaforo(StatoSemaforo::Rosso, $this->superadmin, 'Fuori uso');

    alComandoDelParco($this->superadmin);

    $righe = Livewire::test(ParcoGlobale::class)->viewData('righe');

    expect($righe->semaforo($this->autoclaveRossi->fresh()))->toBe(StatoSemaforo::Rosso);
});

it('names the nearest deadline in the column, for a client that is not its own', function () {
    Intervento::factory()->forStrumento($this->autoclaveBianchi)->scaduto()->create();

    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->assertSee('scaduta');
});

it('spells the deadline exactly like the per-Ente list does, on the same machine', function () {
    // 🔴 La regola dell'etichetta esiste in DUE posti: le closure in cima a
    // `resources/views/livewire/strumenti/elenco-strumenti.blade.php` e
    // `RigheParcoStrumenti::etichetta()`. Oggi coincidono e niente le lega:
    // il giorno in cui una sola delle due diventa «tra 3 gg ⚠», i due schermi
    // si contraddicono sullo stesso strumento e nessuno se ne accorge.
    //
    // Questo test è quel filo. La strada per non averne bisogno è una sola —
    // una regola sola, chiamata da entrambi — e sta scritta nel docblock di
    // `RigheParcoStrumenti::scadenza()`.
    $intervento = Intervento::factory()
        ->forStrumento($this->autoclaveRossi)
        ->create(['data_scadenza' => today()->addDays(3)->toDateString()]);

    alComandoDelParco($this->superadmin);

    $testo = Livewire::test(ParcoGlobale::class)
        ->viewData('righe')
        ->scadenza($this->autoclaveRossi, true)['testo'];

    // Il formato è fissato qui, così una modifica al parco è visibile e non
    // solo «diversa dall'altra schermata».
    expect($testo)->toBe($intervento->tipo->label().' tra 3 gg');

    // …e la schermata per-Ente, sullo stesso strumento, dice la stessa cosa.
    Livewire::actingAs($this->adminRossi->fresh())
        ->test(ElencoStrumenti::class)
        ->assertSee('Autoclave Rossi')
        ->assertSee($testo);
});

it('degrades the label of a part warranty for whoever may not see the parts', function () {
    // 🔗 ADR-020: il DETTAGLIO degrada, l'aggregato no. Anche questa è una
    // regola in due copie, e il test la tiene ferma su entrambi i lati del
    // permesso.
    $ricambio = Ricambio::factory()->forTenant($this->sedeRossi)->create(['nome' => 'Guarnizione Rossi']);
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->autoclaveRossi)
        ->forRicambio($ricambio)
        ->create();
    Garanzia::factory()->forRicambio($utilizzo)->scadenzaDichiarata(today()->addDays(5)->toDateString())->create();

    alComandoDelParco($this->superadmin);

    $righe = Livewire::test(ParcoGlobale::class)->viewData('righe');

    expect($righe->scadenza($this->autoclaveRossi, true)['testo'])->toBe('Garanzia ricambio tra 5 gg')
        ->and($righe->scadenza($this->autoclaveRossi, false)['testo'])->toBe('Garanzia tra 5 gg')
        // Il nome del pezzo non entra nemmeno nel result set.
        ->and($righe->scadenza($this->autoclaveRossi, false)['testo'])->not->toContain('Guarnizione');
});

// ─── 6. Filtri e paginazione ─────────────────────────────────────────────────

it('searches the machine columns and not the ones the join brought in', function () {
    // ⚠️ Con `sede.nome` e `accounts.ragione_sociale` nella stessa query, un
    // `nome` NUDO è un'ambiguità: Postgres la rifiuta, SQLite la risolve a caso
    // — e la ricerca comincerebbe a pescare per nome di sede senza dirlo.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('search', 'Sede Rossi')
        ->assertDontSee('Autoclave Rossi')
        ->assertSee('Nessun risultato per i filtri applicati');

    Livewire::test(ParcoGlobale::class)
        ->set('search', 'SN-BIANCHI')
        ->assertSee('Autoclave Bianchi')
        ->assertDontSee('Autoclave Rossi');
});

it('tells an empty perimeter apart from an empty search and from an empty parco', function () {
    // Tre fatti diversi, tre messaggi: mandare a togliere un filtro che non c'è,
    // o a cercare macchine che ci sono, è lo stesso difetto già pagato
    // sull'elenco per-Ente e sul registro degli errori. «Studio Verdi» ha una
    // sede e nessuna macchina: il perimetro è pieno, il parco è vuoto.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->verdi->id])
        ->assertSee('Nessuna macchina per i clienti nel perimetro')
        ->assertDontSee('Nessun cliente selezionato')
        ->assertDontSee('Nessun risultato per i filtri applicati');
});

it('sends to the filter that is actually on screen when the perimeter is empty', function (array $stato, string $messaggio, string $mai) {
    // 🔴 Il perimetro si svuota in tre modi, e i controlli a schermo sono
    // diversi in ognuno. Col modo «per piano» e nessun piano scelto
    // `daRichiesta()` cade su `nessuno()`, che è `scelti([])`: il perimetro è
    // vuoto, ma il multi-select dei clienti NON è renderizzato — e «scegline
    // almeno uno dal filtro qui sopra» manda a cercare un controllo che non
    // c'è, cioè a cercare un difetto.
    alComandoDelParco($this->superadmin);

    $componente = Livewire::test(ParcoGlobale::class);

    foreach ($stato as $proprieta => $valore) {
        $componente->set($proprieta, $valore);
    }

    $componente->assertSee($messaggio)->assertDontSee($mai);
})->with([
    'per piano, senza piano scelto' => [
        ['modo' => Perimetro::PER_PIANO, 'piano' => ''],
        'Nessun piano selezionato',
        'Nessun cliente selezionato',
    ],
    'per piano, con un piano fuori catalogo' => [
        ['modo' => Perimetro::PER_PIANO, 'piano' => 'platino'],
        'Nessun piano selezionato',
        'Nessun cliente selezionato',
    ],
    'un modo che non esiste' => [
        ['modo' => 'tuttissimi'],
        'Perimetro non valido',
        'Nessun cliente selezionato',
    ],
    'clienti scelti, nessuno scelto' => [
        ['modo' => Perimetro::SCELTI, 'accountIds' => []],
        'Nessun cliente selezionato',
        'Nessun piano selezionato',
    ],
]);

it('does not call a client without machines what is not a client at all', function () {
    // L'id di EasyLab è un intero legittimo e non è un cliente: l'intersezione
    // lo fa sparire, e la tabella resta vuota. Dire «Nessuna macchina per i
    // clienti nel perimetro» sarebbe la terza risposta sbagliata alla stessa
    // domanda — quel cliente non è senza macchine, non è nel perimetro.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->easylab->id])
        ->assertSee('Nessun cliente selezionato')
        ->assertDontSee('Nessuna macchina per i clienti nel perimetro');
});

it('counts the clients it is really showing, not the ids the browser sent', function () {
    // ⚠️ `count($accountIds)` è l'array GREZZO: un id ripetuto conta due volte
    // e l'id di EasyLab conta uno, e l'etichetta annuncia clienti che in
    // tabella non ci sono.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->rossi->id, $this->rossi->id, $this->easylab->id])
        ->assertSee('Clienti scelti (1)')
        ->assertDontSee('Clienti scelti (3)');
});

it('says in its own title which clients it is showing', function () {
    // 🔴 Un H1 statico «Strumenti di tutti i clienti» sopra una tabella
    // filtrata su un cliente solo dice il falso, e lo dice sulla scheda il cui
    // primo requisito è sapere di CHI sono le righe.
    //
    // ⚠️ Niente dataset: i casi dipendono dalle fixture di `beforeEach`, e un
    // dataset viene risolto PRIMA che quelle esistano — il test non verrebbe
    // nemmeno raccolto.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->assertSee('Strumenti di tutti i clienti');

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->rossi->id])
        ->assertSee('Strumenti di 1 cliente scelto')
        ->assertDontSee('Strumenti di tutti i clienti');

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->rossi->id, $this->bianchi->id])
        ->assertSee('Strumenti di 2 clienti scelti')
        ->assertDontSee('Strumenti di tutti i clienti');

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PER_PIANO)
        ->set('piano', 'saas')
        ->assertSee('Strumenti dei clienti sul piano '.Piani::etichetta('saas'))
        ->assertDontSee('Strumenti di tutti i clienti');
});

it('shows a matricola valorised to zero, instead of reading it as missing', function () {
    // ⚠️ `?:` è un test di FALSITÀ, non di nullità: una matricola «0» — che su
    // un parco vero esiste — comparirebbe come «—», cioè come matricola non
    // registrata. Su una vista di sorveglianza la matricola è uno dei due
    // identificatori con cui si distingue una macchina da un'altra.
    $zero = Strumento::factory()->forNode($this->sedeRossi)->create([
        'nome' => 'Autoclave Zero', 'modello' => '0', 'matricola' => '0',
    ]);

    alComandoDelParco($this->superadmin);

    $riga = rigaDelParco(Livewire::test(ParcoGlobale::class)->html(), $zero->id);

    // Le celle piatte della riga: modello e matricola valorizzati, e nessun
    // trattino da «dato assente».
    expect(substr_count($riga, '>0</td>'))->toBe(2);
    expect($riga)->not->toContain('>—</td>');
});

it('refuses a forged page size instead of asking the database for the whole parco', function () {
    alComandoDelParco($this->superadmin);

    $componente = Livewire::test(ParcoGlobale::class)
        ->set('perPage', 999_999);

    expect($componente->viewData('strumenti')->perPage())->toBe(20);
});

it('falls back to the default order when the column is not sortable', function () {
    alComandoDelParco($this->superadmin);

    $componente = Livewire::test(ParcoGlobale::class)
        ->set('sortBy', 'forced_state; drop table strumenti');

    // ⚠️ `ordinaPer` e non `sortBy`: Livewire passa alla vista sia le property
    // pubbliche sia i dati di `render()`, e in caso di omonimia vince la
    // property — cioè il valore GREZZO. Il nome distinto è ciò che rende questa
    // asserzione capace di distinguerli.
    expect($componente->viewData('ordinaPer'))->toBe('cliente');
});

it('keeps the sort action from accepting a column it does not know', function () {
    alComandoDelParco($this->superadmin);

    $componente = Livewire::test(ParcoGlobale::class)
        ->call('sort', 'stato');

    expect($componente->get('sortBy'))->toBe('cliente');
});

// ─── 7. L'ordinamento è TOTALE: la prova sta nell'SQL ───────────────────────

it('always ends the ordering with the id tie break', function (string $colonna) {
    // 🔴 Si prova sull'**SQL** e non sui dati, ed è una lezione già pagata: a
    // parità di chiave l'ordine fra due pagine è una proprietà del motore —
    // SQLite scansiona in modo stabile, Postgres può riordinare i pari fra la
    // query di pagina 1 e quella di pagina 2, e una riga esce da entrambe.
    // Togliendo il tie-break la suite resterebbe verde su SQLite, quindi un
    // test sui dati non sarebbe una rete: sarebbe un aneddoto.
    //
    // Ordinando per «cliente» i pari sono tutte le macchine di uno stesso
    // cliente, cioè quasi tutta la pagina.
    $this->actingAs($this->superadmin);

    $componente = new ParcoGlobale;
    $classe = new ReflectionClass($componente);

    $macchine = $classe->getMethod('macchine');
    $macchine->setAccessible(true);
    $ordina = $classe->getMethod('applicaOrdinamento');
    $ordina->setAccessible(true);

    $query = $macchine->invoke($componente, Perimetro::tutti());
    $ordina->invoke($componente, $query, $colonna, 'desc');

    expect(str_ends_with($query->toSql(), '"strumenti"."id" asc'))->toBeTrue();
})->with(['cliente', 'sede', 'nome', 'modello', 'matricola']);

it('ends with the id tie break in the very query the page runs, ordered as asked', function (string $colonna, string $verso, string $sql) {
    // 🔴 Il test qui sopra prova un metodo PRIVATO raggiunto per reflection, e
    // un metodo privato può restare corretto mentre `render()` smette di
    // chiamarlo: un `orderBy` scritto inline lascerebbe quel guardrail verde e
    // rimetterebbe in produzione il difetto del 25 Ago — una riga raccolta da
    // due pagine su Postgres. Qui si guarda l'SQL **eseguito**.
    //
    // ⚠️ Il verso si asserisce sulla TESTA e non solo sulla coda: un
    // `orderBy($colonna, 'asc')` che ignora `$sortDir` lascerebbe il tie-break
    // al suo posto, e la freccia dell'intestazione indicherebbe un ordine che
    // la query non ha usato.
    alComandoDelParco($this->superadmin);

    $componente = Livewire::test(ParcoGlobale::class)
        ->set('sortBy', $colonna)
        ->set('sortDir', $verso);

    // Log acceso DOPO gli `set()`: ognuno di quelli è un render con lo stato a
    // metà strada, e le sue query direbbero il verso precedente.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $componente->call('$refresh');
    $eseguite = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $q) => str_contains($q, 'from "strumenti"') && str_contains($q, 'order by'))
        ->values();
    DB::disableQueryLog();

    expect($eseguite)->not->toBeEmpty();

    foreach ($eseguite as $query) {
        // La clausola si ritaglia: la query PAGINATA finisce con `limit … offset
        // …`, quindi un `str_ends_with` sull'intera stringa non potrebbe mai
        // essere vero — e sarebbe un test che non prova niente al primo modo,
        // rosso per sempre al secondo.
        preg_match('/ order by (.*?)(?= limit | offset |$)/', $query, $clausola);

        expect($clausola)->not->toBeEmpty();
        expect(str_ends_with($clausola[1], '"strumenti"."id" asc'))->toBeTrue();
        expect(str_starts_with($clausola[1], $sql.' '.$verso))->toBeTrue();
    }
})->with([
    'per cliente, decrescente' => ['cliente', 'desc', '"cliente"."ragione_sociale"'],
    'per sede, decrescente' => ['sede', 'desc', '"sede"."nome"'],
    'per nome, crescente' => ['nome', 'asc', '"strumenti"."nome"'],
    'per modello, decrescente' => ['modello', 'desc', '"strumenti"."modello"'],
    'per matricola, crescente' => ['matricola', 'asc', '"strumenti"."matricola"'],
]);

it('orders by the site column, and not by the client one, when Sede is picked', function () {
    // 🔴 Due sedi dello STESSO cliente: è la sola forma che distingue le due
    // colonne. Con un Ente per cliente, `sede` mappato per svista su
    // `cliente.ragione_sociale` darebbe lo stesso ordine e niente diventerebbe
    // rosso — e l'intestazione «Sede ↑» ordinerebbe per Cliente.
    $torino = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede Torino']);
    $aosta = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede Aosta']);
    Strumento::factory()->forNode($torino)->create(['nome' => 'Autoclave Torino']);
    Strumento::factory()->forNode($aosta)->create(['nome' => 'Autoclave Aosta']);

    alComandoDelParco($this->superadmin);

    $componente = Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->rossi->id]);

    $sedi = fn () => $componente->viewData('strumenti')->getCollection()->pluck('sede_nome')->all();

    $componente->call('sort', 'sede');
    expect($sedi())->toBe(['Sede Aosta', 'Sede Rossi', 'Sede Torino']);

    // Secondo clic sulla stessa colonna: si inverte. Prova che il verso arriva
    // fino alla query e non solo fino alla freccia.
    $componente->call('sort', 'sede');
    expect($sedi())->toBe(['Sede Torino', 'Sede Rossi', 'Sede Aosta']);
});

it('orders by the client column across clients, in both directions', function () {
    alComandoDelParco($this->superadmin);

    $componente = Livewire::test(ParcoGlobale::class);

    $clienti = fn () => $componente->viewData('strumenti')->getCollection()->pluck('cliente_nome')->all();

    // L'ordinamento predefinito è per cliente, crescente.
    expect($clienti())->toBe(['Gruppo Rossi', 'Lab Bianchi']);

    $componente->call('sort', 'cliente');
    expect($clienti())->toBe(['Lab Bianchi', 'Gruppo Rossi']);
});

// ─── 8. Il costo: query costanti al crescere delle righe ─────────────────────

it('costs the same number of queries whether the parco has three rows or sixty', function () {
    // ⚠️ È una vista su TUTTI i clienti: l'elenco per-Ente ha già un debito noto
    // (sottoquery correlate per riga), e qui si moltiplicherebbe per il numero
    // di Enti. Le tre fonti del semaforo partono dagli id già paginati, e i
    // nomi di cliente e sede arrivano da due join invece che da una relazione
    // caricata riga per riga.
    $this->actingAs($this->superadmin);

    // Un giro a vuoto per scaldare la cache dei permessi di spatie, che
    // altrimenti conterebbe le proprie query nella prima misura e non nella
    // seconda.
    Livewire::test(ParcoGlobale::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ParcoGlobale::class);
    $poche = count(DB::getQueryLog());
    DB::disableQueryLog();

    // ⚠️ Le sessanta macchine nascono **senza nessuno al comando**:
    // `BelongsToTenant::creating()` forza `tenant_id` al tenant di chi scrive,
    // e create da qui nascerebbero tutte nell'Ente di EasyLab — cioè fuori dal
    // perimetro, e la pagina resterebbe di due righe. Il test sarebbe verde e
    // non avrebbe misurato niente.
    auth()->logout();
    Strumento::factory()->count(60)->forNode($this->sedeBianchi)->create();
    alComandoDelParco($this->superadmin);

    // ⚠️ Un secondo giro a vuoto **dopo** il nuovo login: `actingAs` monta
    // un'istanza nuova dell'utente, e spatie ricarica ruoli e permessi al primo
    // controllo. Sono due query che appartengono alla sessione, non alla
    // pagina, e senza questa riga il test le imputerebbe alle sessanta righe.
    Livewire::test(ParcoGlobale::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ParcoGlobale::class);
    $molte = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($molte)->toBe($poche);
});

// ─── 9. Il tasto «impersona»: la sola strada per agire ───────────────────────

it('offers to impersonate the single member of the client on the row', function () {
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->assertSee('Impersona')
        ->assertSee(route('impersonate', $this->adminRossi), false);
});

it('binds the impersonate button to the client of ITS OWN row', function () {
    // 🔴 È il difetto peggiore che questa pagina possa avere, ed è muto: la
    // riga dice «Gruppo Rossi / Sede Rossi», si preme «Impersona» e si entra in
    // casa di Lab Bianchi. La colonna «Cliente» — chiesta per prima proprio per
    // impedirlo — CONFERMEREBBE il cliente sbagliato, perché è corretta lei e
    // non il bottone.
    //
    // ⚠️ Si guarda la RIGA e non la pagina: con entrambi i link presenti da
    // qualche parte, un `assertSee` sulla pagina intera resta verde anche se
    // ogni bottone punta al primo cliente dell'elenco.
    alComandoDelParco($this->superadmin);

    $html = Livewire::test(ParcoGlobale::class)->html();

    $rigaRossi = rigaDelParco($html, $this->autoclaveRossi->id);
    $rigaBianchi = rigaDelParco($html, $this->autoclaveBianchi->id);

    // La virgoletta di chiusura fa parte dell'ago: `…/take/1` è un prefisso di
    // `…/take/12`, e senza di essa l'asserzione negativa potrebbe essere
    // soddisfatta da un link sbagliato.
    $verso = fn (User $membro) => route('impersonate', $membro).'"';

    expect($rigaRossi)->toContain($verso($this->adminRossi));
    expect($rigaRossi)->not->toContain($verso($this->adminBianchi));
    expect($rigaBianchi)->toContain($verso($this->adminBianchi));
    expect($rigaBianchi)->not->toContain($verso($this->adminRossi));
});

it('opens the member picker for the client of ITS OWN row', function () {
    // Stessa legatura, dall'altro ramo: quando i membri sono più d'uno il
    // bottone chiama `apriScelta($id)`, e quell'id deve essere il cliente della
    // riga. Un id preso dalla prima riga aprirebbe la modale sul cliente
    // sbagliato — con i nomi giusti dentro, che è ciò che la rende credibile.
    $secondo = User::factory()->create(['tenant_id' => $this->sedeBianchi->id, 'name' => 'Carla Bianchi']);
    $secondo->assignRole('Tenant');
    $this->bianchi->aggiungiMembro($secondo);

    alComandoDelParco($this->superadmin);

    $riga = rigaDelParco(Livewire::test(ParcoGlobale::class)->html(), $this->autoclaveBianchi->id);

    expect($riga)->toContain('apriScelta('.$this->bianchi->id.')');
    expect($riga)->not->toContain('apriScelta('.$this->rossi->id.')');
});

it('asks which member, instead of picking the first one an order by happened to return', function () {
    $secondo = User::factory()->create(['tenant_id' => $this->sedeRossi->id, 'name' => 'Carla Rossi']);
    $secondo->assignRole('Tenant');
    $this->rossi->aggiungiMembro($secondo);

    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->call('apriScelta', $this->rossi->id)
        ->assertSee('Impersona un membro di Gruppo Rossi')
        ->assertSee('Anna Rossi')
        ->assertSee('Carla Rossi');
});

it('says out loud that nobody can be impersonated, instead of leaving a silent blank', function () {
    // ⚠️ Un'assenza muta fa chiedere se sia un difetto: è già successo su questa
    // piattaforma, ed è la ragione per cui la striscia della cabina esiste.
    // Il Developer non è mai impersonabile (🔗 ADR-018).
    $solo = Account::factory()->create(['ragione_sociale' => 'Studio Neri', 'piano' => 'free']);
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($solo)->create(['nome' => 'Sede Neri']);
    Strumento::factory()->forNode($sede)->create(['nome' => 'Autoclave Neri']);

    $developer = User::factory()->create(['tenant_id' => $sede->id, 'two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');
    $solo->aggiungiMembro($developer);

    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$solo->id])
        ->assertSee('Autoclave Neri')
        ->assertSee('Nessun membro impersonabile');
});

it('keeps the open member picker alive when the perimeter changes underneath it', function () {
    // 🔴 Decisione, non svista: la modale **non** è vincolata al perimetro.
    // `clienteScelto()` rilegge dalla porta proprio perché filtrare con la
    // modale aperta lascerebbe altrimenti a schermo un guscio — titolo troncato
    // e lista vuota — ed è uno stato che si raggiunge da soli: si apre la
    // modale, si ripensa, si cambia il filtro dietro.
    //
    // Il perimetro è un filtro di lettura che l'utente sceglie per sé, non un
    // confine di autorizzazione: chi è qui ha `tenants.view_all` e
    // `utenti.impersonate`, e un clic su «Tutti i clienti» gli rimette davanti
    // gli stessi nomi. Il confine vero è il permesso, ed è provato sopra.
    $secondo = User::factory()->create(['tenant_id' => $this->sedeBianchi->id, 'name' => 'Carla Bianchi']);
    $secondo->assignRole('Tenant');
    $this->bianchi->aggiungiMembro($secondo);

    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->call('apriScelta', $this->bianchi->id)
        ->set('modo', Perimetro::SCELTI)
        ->set('accountIds', [$this->rossi->id])
        ->assertDontSee('Autoclave Bianchi')
        ->assertSee('Impersona un membro di Lab Bianchi')
        ->assertSee('Bruno Bianchi');
});

it('does not offer a second impersonation to someone who is already impersonating', function () {
    // Il caso è raggiungibile: un Developer che impersona un Superadmin arriva
    // qui, e un pulsante «Impersona» lo porterebbe a un 403 secco del
    // pacchetto, fuori da qualsiasi UI. Meglio nessun pulsante che uno che mente.
    $developer = User::factory()->create(['tenant_id' => $this->sedeEasylab->id, 'two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');

    $this->actingAs($developer)->get(route('impersonate', $this->superadmin));

    expect(app('impersonate')->isImpersonating())->toBeTrue();

    Livewire::test(ParcoGlobale::class)
        ->assertSee('Autoclave Rossi')
        ->assertDontSee('Impersona');
});

it('refuses the member picker to whoever may see the parco but not impersonate', function () {
    // 🔴 `tenants.view_all` e `utenti.impersonate` sono permessi **diversi**:
    // vedere il parco non è poter entrare in casa di un cliente. La porta gata
    // sul primo, quindi senza una verifica dentro l'azione la sola guardia
    // vivrebbe nel `@can` del Blade — il posto più facile da aggirare — e la
    // modale raggiungerebbe nome ed email dei membri di un altro tenant.
    //
    // ⚠️ Livewire **traduce** `AuthorizationException` in una risposta 403
    // invece di lasciarla salire: un `toThrow()` qui darebbe un test che non
    // può fallire, perché l'eccezione attesa non arriva mai al chiamante.
    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');

    $this->actingAs($osservatore->fresh());

    Livewire::test(ParcoGlobale::class)
        ->call('apriScelta', $this->rossi->id)
        ->assertForbidden();

    Livewire::test(ParcoGlobale::class)
        ->set('sceltaImpersonazione', $this->rossi->id)
        ->assertForbidden();
});

// ─── 10. Da qui si GUARDA: nessuna scrittura, in nessuna forma ──────────────

it('never writes, and the proof is structural', function () {
    // 🔴 ADR-037 concede la LETTURA cross-cliente e lascia la scrittura
    // all'impersonazione, che è per cliente e lascia una riga di audit con
    // dentro chi agiva e per conto di chi. Un test comportamentale non può
    // provare l'assenza di un'azione che non esiste: si legge il sorgente.
    $sorgenti = [
        app_path('Livewire/Piattaforma/ParcoGlobale.php'),
        app_path('Support/Piattaforma/RigheParcoStrumenti.php'),
        resource_path('views/livewire/piattaforma/parco-globale.blade.php'),
    ];

    // ⛔ L'elenco è lungo di proposito: una scrittura non arriva mai col verbo
    // che ci si aspetta. `updateOrCreate`, `firstOrCreate`, `upsert`,
    // `saveQuietly`, `restore` e `Model::destroy()` scrivono quanto `->save()`,
    // e un elenco corto è un guardrail che dice «non si scrive» controllandone
    // otto forme su venti.
    $verbi = [
        '->update(', '->updateOrCreate(', '->updateQuietly(', '->upsert(',
        '->delete(', '->forceDelete(', '->restore(', '::destroy(',
        '->save(', '->saveQuietly(', '->push(',
        '->create(', '->forceCreate(', '->firstOrCreate(', '->createOrFirst(', '->firstOrNew(',
        '->insert(', '->insertGetId(', '->insertOrIgnore(',
        '->increment(', '->decrement(', '->touch(',
        'DB::statement(', 'DB::unprepared(', 'DB::insert(', 'DB::update(', 'DB::delete(',
    ];

    foreach ($sorgenti as $sorgente) {
        $codice = file_get_contents($sorgente);

        // ⚠️ Il Blade si COMPILA prima di leggerlo, e non è un vezzo: lo
        // spoglio dei commenti qui sotto è condizionato alla presenza di
        // `<?php`, che in una vista Blade non c'è mai — i commenti dentro un
        // blocco `@php` resterebbero nel testo, e il giorno in cui una
        // spiegazione nominasse per esteso un verbo di scrittura il test
        // diventerebbe rosso senza che nessuno abbia scritto niente. È un
        // falso rosso e non un falso verde, ma è esattamente l'asimmetria che
        // punisce chi documenta, cioè ciò che questo test dichiara di evitare.
        // Compilando, `{{-- --}}` sparisce e `@php … @endphp` diventa PHP vero:
        // i suoi commenti tornano commenti, e li toglie il tokenizer.
        if (str_ends_with($sorgente, '.blade.php')) {
            $codice = Blade::compileString($codice);
        }

        // I commenti PHP si tolgono: questi docblock spiegano per esteso perché
        // una scrittura è vietata, e un guardrail che legge il testo invece del
        // codice punisce chi documenta.
        $codice = collect(token_get_all($codice))
            ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($t) => is_array($t) ? $t[1] : $t)
            ->implode('');

        foreach ($verbi as $verbo) {
            // ⚠️ Un ago per asserzione: `toContain()` è VARIADICO, e passare la
            // spiegazione come secondo argomento la trasformerebbe in un
            // secondo ago che non compare mai — cioè in un'asserzione negativa
            // sempre soddisfatta. La spiegazione sta nel nome del test.
            expect($codice)->not->toContain($verbo);
        }
    }
});

it('sorts from the arrow the page is actually showing, not from a forged property', function () {
    // 🔴 `render()` normalizza: una colonna fuori whitelist ricade sul default,
    // quindi la freccia in intestazione dice «cliente ↑». `sort()` leggeva
    // invece la property GREZZA, e il primo clic su «cliente» la reimpostava ad
    // `asc` — cioè non faceva nulla, sulla colonna che la pagina stava già
    // mostrando ordinata. Segnalato dal correttore dei ricambi, che lo stesso
    // difetto ce l'aveva sul proprio `ordina()`.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('sortBy', 'colonna-inventata')
        ->call('sort', 'cliente')
        ->assertSet('sortBy', 'cliente')
        ->assertSet('sortDir', 'desc');
});
