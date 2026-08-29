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
use App\Support\Piattaforma\RigheParcoStrumenti;
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
 * Segna questi clienti come **preferiti** di chi guarda (🔗 ADR-037).
 *
 * ⚠️ Si scrive sul pivot e non passa da `Preferiti::alterna()`, di proposito:
 * quella porta rifiuta ciò che non è un cliente visibile, e metà dei test qui
 * sotto ha bisogno esattamente di un preferito **illegittimo** — verso EasyLab,
 * verso un account cestinato — per provare che l'intersezione tiene comunque.
 * Passando dalla porta non si potrebbe nemmeno costruire il caso.
 *
 * `sync` e non `attach`: il preferito è un insieme, e un test che ne segna due
 * volte lo stesso cliente deve descrivere lo stesso stato.
 */
function preferisci(User $chiGuarda, Account ...$clienti): void
{
    $chiGuarda->clientiPreferiti()->sync(collect($clienti)->pluck('id')->all());
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

/**
 * La rotta che impersona **e atterra sulla macchina** (🔗 ADR-037).
 *
 * ⚠️ Nome diverso da `versoLaMacchina()` di `ImpersonaVersoStrumentoTest`, e non
 * per gusto: le funzioni dichiarate nei file di test sono **globali** e la suite
 * gira in un processo solo — due file che ne definiscono una omonima si fanno
 * esplodere a vicenda al caricamento, prima ancora di provare qualcosa.
 */
function versoLaMacchinaDelParco(User $membro, Strumento $macchina): string
{
    return route('piattaforma.parco.impersona', ['utente' => $membro->id, 'strumento' => $macchina->id]);
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

    preferisci($this->superadmin, $this->rossi);
    $pagina = Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
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

it('narrows to the favourite clients', function () {
    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin, $this->rossi);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertSee('Autoclave Rossi')
        ->assertDontSee('Autoclave Bianchi');
});

it('shows nothing at all when there is no favourite, and says where to add one', function () {
    // ⛔ «Vuoto» e «tutti» sono un carattere di distanza e nessuno dei due dà
    // errore: un `if ($preferiti)` che tratta l'insieme vuoto come «nessun
    // filtro» mostrerebbe le righe di tutti a chi ne aveva chieste zero. È il
    // difetto già pagato dall'export che ignorava i filtri.
    //
    // ⚠️ E il messaggio manda dove la ★ c'è davvero — l'elenco Clienti — non a
    // un filtro di questa pagina, che nel modo preferiti non esiste.
    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertDontSee('Autoclave Rossi')
        ->assertDontSee('Autoclave Bianchi')
        ->assertSee('Non hai ancora clienti preferiti');
});

it('never widens the perimeter with account ids forged in the query string', function () {
    // 🔴 Dopo la ★ gli id del perimetro **non arrivano più dal browser**: la
    // property che li portava è stata tolta, non lasciata inerte. Un
    // `?modo=scelti&accountIds[]=…` salvato mesi fa — o scritto a mano — arriva
    // comunque, e questo test congela le due metà della risposta: il modo si
    // normalizza a `preferiti`, e l'array è un parametro che nessuna property
    // `#[Url]` raccoglie più.
    //
    // ⚠️ L'id di «Gruppo Rossi» è **legittimo**, ed è il punto: se il residuo
    // fosse ancora vivo, questa riga mostrerebbe le sue macchine.
    //
    // ⛔ E si passa dalla **query string** e non da `set()`: `set('accountIds')`
    // su una property che non esiste solleva, cioè proverebbe la sparizione
    // invece del comportamento. Il difetto vero arriva da un URL.
    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin);

    Livewire::withQueryParams([
        'modo' => Perimetro::SCELTI,
        'accountIds' => [$this->rossi->id, $this->rossi->id + 9_999],
    ])
        ->test(ParcoGlobale::class)
        ->assertSet('modo', Perimetro::PREFERITI)
        ->assertDontSee('Autoclave Rossi')
        ->assertDontSee('Autoclave Bianchi');
});

it('does not carry a hand-picked id list in its public state at all', function () {
    // ⚠️ Il compagno strutturale del test qui sopra, e non è zelo: quello resta
    // verde anche se la property tornasse, purché la normalizzazione del modo
    // regga. Ma una lista di id pubblica e `#[Url]` è l'unico filo per cui il
    // browser potrebbe tornare a dettare un `whereIn` su questa vista, e la sua
    // innocuità dipenderebbe per intero dal fatto che quella normalizzazione non
    // abbia mai un buco. Tolto il filo, la garanzia è strutturale — ed è la
    // stessa scelta delle altre due schede, che la property l'hanno tolta.
    $pubbliche = collect((new ReflectionClass(ParcoGlobale::class))->getProperties(ReflectionProperty::IS_PUBLIC))
        ->map(fn (ReflectionProperty $p) => $p->name)
        ->filter(fn (string $nome) => str_contains(mb_strtolower($nome), 'account')
            || str_contains(mb_strtolower($nome), 'client'))
        ->values();

    expect($pubbliche->all())->toBe(
        [],
        "Il Parco strumenti espone di nuovo una lista di clienti come property pubblica.\n\n".
        "Gli id del perimetro vengono dal DATABASE (`Preferiti::perimetro()`), non dal browser: una\n".
        "property pubblica omonima è la selezione arbitraria che la ★ esiste per sottrarre, sotto un\n".
        "nome nuovo — e `#[Url]` la renderebbe pure condivisibile per link.\n\n".
        'Trovate: '.$pubbliche->implode(', ')
    );
});

it('does not let a saved url select a mode the dropdown cannot draw', function (string $modo) {
    // 🔴 Questa è la strada del **`mount()`**, non quella dell'update: un
    // `?modo=scelti&accountIds[]=…` messo fra i preferiti del browser mesi fa,
    // o un `?modo=` scritto a mano, arriva dalla query string e non passa da
    // `updatedModo()`. Senza la normalizzazione lì, la tendina mostrerebbe la
    // sua PRIMA voce — «Tutti i clienti» — sopra una tabella vuota, e da lì non
    // si uscirebbe: riselezionare la voce già mostrata non emette nessun evento.
    //
    // ⚠️ `assertSet` guarda la property, cioè ciò che la `<select>` riceverà:
    // è l'asserzione che distingue «il perimetro è vuoto» (vero anche prima)
    // da «il controllo dice il vero» (che è il difetto chiuso qui).
    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin);

    Livewire::withQueryParams(['modo' => $modo, 'accountIds' => [$this->rossi->id]])
        ->test(ParcoGlobale::class)
        ->assertSet('modo', Perimetro::PREFERITI)
        ->assertDontSee('Autoclave Rossi')
        ->assertSee('Non hai ancora clienti preferiti');
})->with([
    'il modo scelti, che la ★ ha sostituito' => [Perimetro::SCELTI],
    'una stringa forgiata a mano' => ['tuttissimi'],
]);

it('forgets a plan that is not on the catalogue instead of showing it as chosen', function () {
    // 🔴 L'altra metà della guardia sul modo, e la ragione è identica: `piano`
    // finisce in una `<select wire:model.live>`, e una tendina legata a un
    // valore senza `<option>` corrispondente non resta vuota. Qui la prima voce
    // è «— scegli un piano —», quindi il controllo non mente sul nome; ma la
    // property e la query string continuerebbero a portare `platino`, cioè un
    // filtro **annunciato nell'URL** che nessun controllo mostra e nessuna riga
    // riflette. Le altre due schede lo riconducevano già: qui no, ed erano tre
    // schermi con tre comportamenti.
    //
    // ⚠️ `null` e non `''`: la property è nullable, e i due valori vanno
    // entrambi a `nessuno()` — ricondurli a uno solo è ciò che permette alla
    // tendina di evidenziare la voce vuota.
    alComandoDelParco($this->superadmin);

    Livewire::withQueryParams(['modo' => Perimetro::PER_PIANO, 'piano' => 'platino'])
        ->test(ParcoGlobale::class)
        ->assertSet('modo', Perimetro::PER_PIANO)
        ->assertSet('piano', null)
        ->assertSee('Nessun piano selezionato');

    // E anche dall'update, che è la strada che `mount()` non copre.
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PER_PIANO)
        ->set('piano', 'platino')
        ->assertSet('piano', null)
        ->assertSee('Nessun piano selezionato');
});

it('keeps a plan that the operator really chose', function () {
    // Il rovescio obbligatorio: una normalizzazione che azzera tutto passerebbe
    // il test qui sopra e romperebbe il filtro.
    alComandoDelParco($this->superadmin);

    Livewire::withQueryParams(['modo' => Perimetro::PER_PIANO, 'piano' => 'saas'])
        ->test(ParcoGlobale::class)
        ->assertSet('piano', 'saas')
        ->assertDontSee('Nessun piano selezionato');
});

it('refuses to hand over the platform rows even when its own id is a favourite', function () {
    // L'id di EasyLab è legittimo come intero e illegittimo come cliente:
    // l'intersezione con `VistaPiattaforma::accounts()` lo fa sparire. La ★
    // dell'elenco Clienti non lo offrirebbe, ma il pivot è una tabella e una
    // riga ce la si può trovare — un account che diventa `di_piattaforma` dopo
    // la segnatura arriva esattamente qui.
    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin, $this->easylab);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertDontSee('Autoclave EasyLab')
        ->assertDontSee('Autoclave Rossi');
});

it('drops a favourite that has been trashed in the meantime', function () {
    // ⚠️ Il preferito sopravvive al cestinamento del cliente — il pivot non ha
    // `deleted_at` — e senza l'intersezione le macchine di un Ente cestinato
    // tornerebbero a schermo per la sola persona che l'aveva segnato: un parco
    // che mostra righe diverse a due Superadmin, e nessuno dei due saprebbe
    // perché.
    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin, $this->rossi, $this->bianchi);

    $this->rossi->delete();

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertDontSee('Autoclave Rossi')
        ->assertSee('Autoclave Bianchi')
        ->assertSee('Strumenti di 1 cliente preferito');
});

it('shows the favourites of the acting user, never those of another one', function () {
    // 🔴 Il preferito è una preferenza **della persona**, non del cliente: due
    // Superadmin seguono clienti diversi. Un `clientiPreferiti()` letto da un
    // utente qualsiasi — o una lettura senza `where user_id` — darebbe a
    // ciascuno il parco dell'altro, e la pagina resterebbe plausibile.
    $secondoSuperadmin = User::factory()->create([
        'tenant_id' => $this->sedeEasylab->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $secondoSuperadmin->assignRole('Superadmin');
    $this->easylab->aggiungiMembro($secondoSuperadmin);

    preferisci($this->superadmin, $this->rossi);
    preferisci($secondoSuperadmin, $this->bianchi);

    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertSee('Autoclave Rossi')
        ->assertDontSee('Autoclave Bianchi');

    alComandoDelParco($secondoSuperadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertSee('Autoclave Bianchi')
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

    preferisci($this->superadmin, $this->verdi);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertSee('Nessuna macchina per i clienti nel perimetro')
        ->assertDontSee('Nessun cliente selezionato')
        ->assertDontSee('Nessun risultato per i filtri applicati');
});

it('sends to the filter that is actually on screen when the perimeter is empty', function (array $stato, string $messaggio, string $mai) {
    // 🔴 Il perimetro si svuota in modi diversi, e i controlli a schermo sono
    // diversi in ognuno. Col modo «per piano» e nessun piano scelto
    // `daRichiesta()` cade su `nessuno()`, che è `scelti([])`: il perimetro è
    // vuoto, ma la tendina dei piani è l'unico controllo renderizzato — e
    // mandare altrove manda a cercare un difetto. Nel modo preferiti il rimando
    // esce del tutto dal Parco: la ★ sta nell'elenco Clienti.
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
        'Non hai ancora clienti preferiti',
    ],
    'per piano, con un piano fuori catalogo' => [
        ['modo' => Perimetro::PER_PIANO, 'piano' => 'platino'],
        'Nessun piano selezionato',
        'Non hai ancora clienti preferiti',
    ],
    // ⚠️ I due modi che la tendina non sa disegnare cadono **sullo stesso**
    // messaggio, ed è la conseguenza voluta della normalizzazione: il controllo
    // dice «★ I miei preferiti» e il messaggio manda alla ★. Prima dicevano
    // «Perimetro non valido» sotto una tendina che diceva «Tutti i clienti».
    'un modo che non esiste' => [
        ['modo' => 'tuttissimi'],
        'Non hai ancora clienti preferiti',
        'Nessun piano selezionato',
    ],
    'il modo scelti, che non ha più un controllo' => [
        ['modo' => Perimetro::SCELTI],
        'Non hai ancora clienti preferiti',
        'Nessun piano selezionato',
    ],
]);

it('does not call a client without machines what is not a client at all', function () {
    // L'id di EasyLab è un intero legittimo e non è un cliente: l'intersezione
    // lo fa sparire, e la tabella resta vuota. Dire «Nessuna macchina per i
    // clienti nel perimetro» sarebbe la terza risposta sbagliata alla stessa
    // domanda — quel cliente non è senza macchine, non è nel perimetro.
    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin, $this->easylab);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertSee('Non hai ancora clienti preferiti')
        ->assertDontSee('Nessuna macchina per i clienti nel perimetro');
});

it('counts the clients it is really showing, not the rows of the pivot', function () {
    // ⚠️ `count($perimetro->accountIds)` è la lista GREZZA dei preferiti:
    // l'id di EasyLab ci sta dentro e non porta nessuna riga, e l'etichetta
    // annuncerebbe clienti che in tabella non ci sono. Il conteggio giusto è
    // quello dell'insieme già **intersecato**.
    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin, $this->rossi, $this->easylab);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertSee('I miei preferiti (1)')
        ->assertDontSee('I miei preferiti (2)');
});

it('names the favourites on screen and points to where they are chosen', function () {
    // La lista è in SOLA lettura: la ★ vive nell'elenco Clienti, e una pagina
    // che dice «i miei preferiti» senza dire dove si cambiano lascia l'insieme
    // senza una via d'uscita — è il difetto opposto a quello del multi-select,
    // che li faceva ricomporre ogni volta.
    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin, $this->rossi);

    $html = Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->html();

    expect($html)->toContain('Gruppo Rossi');

    // ⚠️ `toContain(route('piattaforma.index'))` da solo NON prova niente: quella
    // rotta è già nella barra di navigazione della piattaforma, in cima a ogni
    // pagina. L'ago è la coppia href + testo, cioè **questo** rimando.
    //
    // ⚠️ E l'href porta il **frammento** `#elenco-clienti`: senza, il link
    // atterrava in cima alla cabina — quattro KPI e tre grafici sopra la
    // tabella con le ★, a mille pixel di scorrimento — cioè mandava nel posto
    // giusto facendolo sembrare quello sbagliato.
    expect($html)->toMatch('/href="'.preg_quote(route('piattaforma.index').'#elenco-clienti', '/').'"[^>]*>\s*Gestisci i preferiti/');
    // ⛔ Nessun controllo che scriva i preferiti da qui: rimetterebbe in piedi,
    // sotto un nome nuovo, la `<select multiple>` che la ★ sostituisce.
    expect($html)->not->toContain('wire:model.live="accountIds"');
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

    preferisci($this->superadmin, $this->rossi);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertSee('Strumenti di 1 cliente preferito')
        ->assertDontSee('Strumenti di tutti i clienti');

    preferisci($this->superadmin, $this->rossi, $this->bianchi);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertSee('Strumenti di 2 clienti preferiti')
        ->assertDontSee('Strumenti di tutti i clienti');

    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PER_PIANO)
        ->set('piano', 'saas')
        ->assertSee('Strumenti dei clienti sul piano '.Piani::etichetta('saas'))
        ->assertDontSee('Strumenti di tutti i clienti');

    // Zero preferiti: il titolo resta al plurale generico e **non** annuncia un
    // numero, che sarebbe «Strumenti di 0 clienti preferiti».
    preferisci($this->superadmin);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->assertSee('Strumenti dei clienti preferiti')
        ->assertDontSee('Strumenti di tutti i clienti');

    // 🔴 «Per piano» finché il piano manca: `nessuno()` è `scelti([])`, quindi
    // il perimetro dice SCELTI mentre il controllo a schermo dice «Per piano».
    // Il titolo segue il controllo, o annuncerebbe i preferiti a chi sta
    // guardando un'altra tendina.
    preferisci($this->superadmin, $this->rossi);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PER_PIANO)
        ->set('piano', '')
        ->assertSee('Strumenti: nessun piano selezionato')
        ->assertDontSee('cliente preferito');
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

// ─── 6-bis. I filtri di STATO: due assi, e tutti e due in SQL ────────────────

it('filters by the semaforo of a client that is not its own, instead of finding nothing', function () {
    // 🔴 Il difetto che questo filtro rischia più di ogni altro, ed è muto nei
    // DUE versi. Le tre fonti dell'arancione sono sottoquery: costruite con i
    // global scope addosso — cioè come le costruisce `Strumento::conStato()` —
    // su un elenco cross-cliente non trovano nulla, perché chi guarda sta
    // nell'Ente di EasyLab. «Solo arancioni» tornerebbe vuoto.
    Intervento::factory()->forStrumento($this->autoclaveBianchi)->scaduto()->create();

    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('stato', StatoSemaforo::Arancione->value)
        ->assertSee('Autoclave Bianchi')
        ->assertDontSee('Autoclave Rossi');
});

it('never calls green the orange machine of another client', function () {
    // ⛔ L'altra metà, e la peggiore delle due: il verde è il COMPLEMENTO delle
    // tre fonti, quindi con sottoquery scopate ogni macchina altrui lo
    // soddisfa. L'elenco sarebbe plausibile — nomi giusti, conteggi giusti — e
    // direbbe «in regola» di una macchina con uno scaduto in pancia, sulla
    // scheda da cui si decide se intervenire.
    Intervento::factory()->forStrumento($this->autoclaveBianchi)->scaduto()->create();

    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('stato', StatoSemaforo::Verde->value)
        ->assertSee('Autoclave Rossi')
        ->assertDontSee('Autoclave Bianchi');
});

it('feeds the filter from all three orange sources, across clients', function () {
    // Le tre fonti (🔗 ADR-005 · ADR-004 · ADR-020), ognuna su una macchina di
    // un cliente che non è il proprio. La terza è la più facile da perdere:
    // richiede il doppio salto garanzie → ricambio_utilizzo → strumenti **e**
    // il bypass del privacy scope, dentro il perimetro.
    $perIntervento = Strumento::factory()->forNode($this->sedeBianchi)->create(['nome' => 'Centrifuga Intervento']);
    $perGaranzia = Strumento::factory()->forNode($this->sedeBianchi)->create(['nome' => 'Centrifuga Garanzia']);
    $perRicambio = Strumento::factory()->forNode($this->sedeBianchi)->create(['nome' => 'Centrifuga Ricambio']);

    Intervento::factory()->forStrumento($perIntervento)->scaduto()->create();
    Garanzia::factory()->forStrumento($perGaranzia)->scaduta()->create();

    $ricambio = Ricambio::factory()->forTenant($this->sedeBianchi)->create(['nome' => 'Guarnizione Bianchi']);
    $utilizzo = RicambioUtilizzo::factory()->forStrumento($perRicambio)->forRicambio($ricambio)->create();
    Garanzia::factory()->forRicambio($utilizzo)->scaduta()->create();

    alComandoDelParco($this->superadmin);

    $arancioni = Livewire::test(ParcoGlobale::class)
        ->set('perPage', 100)
        ->set('stato', StatoSemaforo::Arancione->value)
        ->viewData('strumenti')->getCollection()->pluck('nome')->all();

    expect($arancioni)->toEqualCanonicalizing([
        'Centrifuga Intervento', 'Centrifuga Garanzia', 'Centrifuga Ricambio',
    ]);
});

it('lets the forced state decide the filter, and keeps red a forced only affair', function () {
    // 🔗 ADR-005 punto 5, in due direzioni: un forzato verde NASCONDE un
    // arancione vero, e il rosso non lo produce nessuna fonte — quindi il suo
    // ramo non deve nemmeno interrogarle.
    Intervento::factory()->forStrumento($this->autoclaveBianchi)->scaduto()->create();
    $this->autoclaveBianchi->forzaSemaforo(StatoSemaforo::Verde, $this->superadmin, 'Verificata a mano');
    $this->autoclaveRossi->forzaSemaforo(StatoSemaforo::Rosso, $this->superadmin, 'Fuori uso');

    // 🔴 Due macchine NON forzate, e senza di loro questo test non poteva
    // fallire sulla seconda metà del proprio nome. Con le sole due autoclavi
    // ogni riga dentro il perimetro aveva `forced_state` valorizzato, quindi il
    // ramo `whereNull('forced_state') …` non poteva selezionare niente in
    // nessuno dei tre casi: togliendo il `return` anticipato del ramo Rosso —
    // cioè facendo diventare «■ Non idoneo» un elenco di macchine IN REGOLA —
    // le tre asserzioni restavano identiche. La verde è quella che lo coglie: è
    // il complemento delle tre fonti, cioè ciò che il ramo mutato pesca.
    $libera = Strumento::factory()->forNode($this->sedeBianchi)->create(['nome' => 'Bilancia Libera']);
    $scaduta = Strumento::factory()->forNode($this->sedeBianchi)->create(['nome' => 'Bilancia Scaduta']);
    Intervento::factory()->forStrumento($scaduta)->scaduto()->create();

    expect($libera->forced_state)->toBeNull()->and($scaduta->forced_state)->toBeNull();

    alComandoDelParco($this->superadmin);

    $conStato = fn (StatoSemaforo $stato) => Livewire::test(ParcoGlobale::class)
        ->set('perPage', 100)
        ->set('stato', $stato->value)
        ->viewData('strumenti')->getCollection()->pluck('nome')->all();

    expect($conStato(StatoSemaforo::Verde))->toBe(['Autoclave Bianchi', 'Bilancia Libera'])
        ->and($conStato(StatoSemaforo::Rosso))->toBe(['Autoclave Rossi'])
        ->and($conStato(StatoSemaforo::Arancione))->toBe(['Bilancia Scaduta']);
});

it('partitions exactly like the per-Ente filter does, on an Ente where both are legitimate', function () {
    // 🔴 **Il filo fra le due copie della composizione.** Le regole — «aperto
    // ed entro la soglia», «garanzia entro la soglia», il doppio salto — restano
    // UNA sola e vivono sui model, che `StrumentiPerStato` chiama partendo da un
    // builder non scopato. A esistere in due copie è il modo di **comporle**:
    // «almeno una» per l'arancione, «il complemento di tutte» per il verde, «il
    // forzato vince» sopra a entrambe, che stanno anche in
    // `Strumento::scopeConStato()`.
    //
    // Qui si gira su un Ente solo, dove entrambe le forme sono legittime, e si
    // confrontano gli INSIEMI DI ID. Cambiarne una sola diventa rosso.
    $scaduto = Strumento::factory()->forNode($this->sedeRossi)->create(['nome' => 'Bagno Scaduto']);
    $imminente = Strumento::factory()->forNode($this->sedeRossi)->create(['nome' => 'Bagno Imminente']);
    $garanzia = Strumento::factory()->forNode($this->sedeRossi)->create(['nome' => 'Bagno Garanzia']);
    $pezzo = Strumento::factory()->forNode($this->sedeRossi)->create(['nome' => 'Bagno Pezzo']);
    $forzatoVerde = Strumento::factory()->forNode($this->sedeRossi)->create(['nome' => 'Bagno Forzato Verde']);
    $forzatoRosso = Strumento::factory()->forNode($this->sedeRossi)->create(['nome' => 'Bagno Forzato Rosso']);

    Intervento::factory()->forStrumento($scaduto)->scaduto()->create();
    Intervento::factory()->forStrumento($imminente)->create(['data_scadenza' => today()->addDays(3)->toDateString()]);
    Garanzia::factory()->forStrumento($garanzia)->scaduta()->create();

    $ricambio = Ricambio::factory()->forTenant($this->sedeRossi)->create(['nome' => 'Guarnizione Rossi']);
    $utilizzo = RicambioUtilizzo::factory()->forStrumento($pezzo)->forRicambio($ricambio)->create();
    Garanzia::factory()->forRicambio($utilizzo)->scaduta()->create();

    Intervento::factory()->forStrumento($forzatoVerde)->scaduto()->create();
    $forzatoVerde->forzaSemaforo(StatoSemaforo::Verde, $this->superadmin, 'Verificata a mano');
    $forzatoRosso->forzaSemaforo(StatoSemaforo::Rosso, $this->superadmin, 'Fuori uso');

    alComandoDelParco($this->superadmin);

    $dalParco = [];

    foreach (StatoSemaforo::cases() as $stato) {
        preferisci($this->superadmin, $this->rossi);
        $dalParco[$stato->value] = Livewire::test(ParcoGlobale::class)
            ->set('modo', Perimetro::PREFERITI)
            ->set('perPage', 100)
            ->set('stato', $stato->value)
            ->viewData('strumenti')->getCollection()->pluck('id')->sort()->values()->all();
    }

    // La partizione è esaustiva: i tre insiemi ricompongono il parco del
    // cliente — sette macchine, le sei di qui più l'autoclave del beforeEach.
    // Senza questa riga il confronto qui sotto sarebbe soddisfatto anche da due
    // filtri sbagliati **allo stesso modo**.
    expect(array_merge(...array_values($dalParco)))->toHaveCount(7);

    $this->actingAs($this->adminRossi->fresh());

    foreach (StatoSemaforo::cases() as $stato) {
        expect(Strumento::query()->conStato($stato)->pluck('id')->sort()->values()->all())
            ->toBe($dalParco[$stato->value]);
    }
});

it('reads the obsolescence threshold of EACH site, and not one for the whole platform', function () {
    // 🔴 La soglia è per Ente (🔗 ADR-014), e su una vista cross-cliente questo
    // smette di essere un dettaglio: una soglia sola applicata a tutte le righe
    // contraddirebbe il badge ⏳ della riga accanto per ogni cliente che ha
    // scelto un valore diverso dal proprio vicino. Le due macchine qui sotto
    // hanno la STESSA età: a separarle è solo la soglia della loro sede.
    $this->sedeRossi->update(['soglia_obsolescenza_anni' => 5]);
    $this->sedeBianchi->update(['soglia_obsolescenza_anni' => 15]);

    Strumento::factory()->forNode($this->sedeRossi)->create([
        'nome' => 'Cappa Rossi', 'data_installazione' => today()->subYears(10)->toDateString(),
    ]);
    Strumento::factory()->forNode($this->sedeBianchi)->create([
        'nome' => 'Cappa Bianchi', 'data_installazione' => today()->subYears(10)->toDateString(),
    ]);

    // ⚠️ Il confine è INCLUSIVO — installata esattamente N anni fa oggi è già
    // obsoleta — e si esprime come `< limite+1`, mai come `<=`: su SQLite le
    // colonne `date` sono stringhe e il confronto è lessicografico, quindi un
    // `<=` passerebbe qui e cadrebbe in CI su Postgres.
    Strumento::factory()->forNode($this->sedeRossi)->create([
        'nome' => 'Cappa Confine', 'data_installazione' => today()->subYears(5)->toDateString(),
    ]);
    Strumento::factory()->forNode($this->sedeRossi)->create([
        'nome' => 'Cappa Giovane', 'data_installazione' => today()->subYears(5)->addDay()->toDateString(),
    ]);

    alComandoDelParco($this->superadmin);

    $nomi = Livewire::test(ParcoGlobale::class)
        ->set('perPage', 100)
        ->set('soloObsoleti', true)
        ->viewData('strumenti')->getCollection()->pluck('nome')->all();

    // ⚠️ Appartenenza e non uguaglianza: `StrumentoFactory` genera una
    // `data_installazione` a caso fra dodici anni fa e oggi, quindi le macchine
    // del `beforeEach` entrano o no in questo elenco a seconda del seme. Un
    // `toBe([...])` sarebbe verde due volte su tre.
    expect($nomi)->toContain('Cappa Rossi');
    expect($nomi)->toContain('Cappa Confine');
    expect($nomi)->not->toContain('Cappa Bianchi');
    expect($nomi)->not->toContain('Cappa Giovane');
});

it('calls obsolete exactly the machines the per-Ente filter calls obsolete', function () {
    // Il secondo filo differenziale, gemello di quello sul semaforo: il confine
    // `< limite+1` e il fallback della soglia esistono anche in
    // `Strumento::scopeObsoleti()`, e le due copie devono partizionare uguale.
    $this->sedeRossi->update(['soglia_obsolescenza_anni' => 7]);

    foreach ([0, 6, 7, 8, 20] as $anni) {
        Strumento::factory()->forNode($this->sedeRossi)->create([
            'nome' => 'Stufa da '.$anni.' anni',
            'data_installazione' => today()->subYears($anni)->toDateString(),
        ]);
    }

    // Senza data di installazione non è mai obsoleta: manca la base del calcolo.
    Strumento::factory()->forNode($this->sedeRossi)->create([
        'nome' => 'Stufa senza data', 'data_installazione' => null,
    ]);

    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin, $this->rossi);
    $dalParco = Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->set('perPage', 100)
        ->set('soloObsoleti', true)
        ->viewData('strumenti')->getCollection()->pluck('id')->sort()->values()->all();

    expect($dalParco)->not->toBeEmpty();

    $this->actingAs($this->adminRossi->fresh());

    expect(Strumento::query()->obsoleti()->pluck('id')->sort()->values()->all())->toBe($dalParco);
});

it('keeps the two axes separate, instead of folding age into the semaforo', function () {
    // ⚠️ Semaforo e obsolescenza sono due assi (🔗 ADR-005, ADR-014): una
    // macchina vecchia e in regola è **verde e obsoleta**, e i due filtri si
    // compongono. Fonderli in una tendina sola renderebbe quella coppia
    // inesprimibile, oltre a contraddire il badge ⏳ che nel Design System §4
    // convive col pallino invece di sostituirlo.
    $this->sedeBianchi->update(['soglia_obsolescenza_anni' => 5]);

    $vecchiaEVerde = Strumento::factory()->forNode($this->sedeBianchi)->create([
        'nome' => 'Muffola Vecchia', 'data_installazione' => today()->subYears(9)->toDateString(),
    ]);
    $vecchiaEArancione = Strumento::factory()->forNode($this->sedeBianchi)->create([
        'nome' => 'Muffola Scaduta', 'data_installazione' => today()->subYears(9)->toDateString(),
    ]);
    Intervento::factory()->forStrumento($vecchiaEArancione)->scaduto()->create();

    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin, $this->bianchi);
    $nomi = Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->set('perPage', 100)
        ->set('soloObsoleti', true)
        ->set('stato', StatoSemaforo::Verde->value)
        ->viewData('strumenti')->getCollection()->pluck('nome')->all();

    expect($nomi)->toContain('Muffola Vecchia');
    expect($nomi)->not->toContain('Muffola Scaduta');
});

it('filters before paginating, or the page would be short and the total a lie', function () {
    // 🔴 La ragione per cui questo filtro vive in SQL e non in PHP sulle righe
    // già in pagina, dove il semaforo si **mostra**: filtrare dopo `paginate()`
    // darebbe una pagina di una riga su venti, un totale che conta anche le
    // righe scartate e pagine vuote nella barra in fondo. Un filtro che mente
    // sui propri conteggi è peggio di un filtro assente, ed è per questo che
    // fino a oggi questa scheda il filtro non ce l'aveva.
    Strumento::factory()->count(40)->forNode($this->sedeBianchi)->create();
    Intervento::factory()->forStrumento($this->autoclaveBianchi)->scaduto()->create();

    alComandoDelParco($this->superadmin);

    $pagina = Livewire::test(ParcoGlobale::class)
        ->set('stato', StatoSemaforo::Arancione->value)
        ->viewData('strumenti');

    expect($pagina->total())->toBe(1)
        ->and($pagina->getCollection())->toHaveCount(1)
        ->and($pagina->lastPage())->toBe(1);
});

it('does not filter, and does not announce a filter, on a semaforo value it does not know', function () {
    // ⚠️ Filtro **applicato** e filtro **annunciato** passano dallo stesso
    // `statoScelto()`. `?stato=giallo` non toglie righe, e non deve nemmeno far
    // dire «Nessun risultato per i filtri applicati» su un elenco che filtrato
    // non è: manderebbe a togliere un filtro che il componente ha già scartato.
    // È un difetto che l'elenco per-Ente ha avuto per davvero, con due liste
    // scritte a mano che sono divergute.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('stato', 'giallo')
        ->assertSee('Autoclave Rossi')
        ->assertSee('Autoclave Bianchi');

    preferisci($this->superadmin, $this->verdi);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->set('stato', 'giallo')
        ->assertSee('Nessuna macchina per i clienti nel perimetro')
        ->assertDontSee('Nessun risultato per i filtri applicati');
});

it('blames the filters when a state that IS known matches nothing', function () {
    // Il rovescio del test qui sopra, e insieme sono la coppia che rende
    // «annunciato» e «applicato» la stessa cosa: nessuna macchina è forzata a
    // rosso, quindi l'elenco è vuoto **per via del filtro** e lo dice.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->set('stato', StatoSemaforo::Rosso->value)
        ->assertSee('Nessun risultato per i filtri applicati')
        ->assertDontSee('Nessuna macchina per i clienti nel perimetro');

    preferisci($this->superadmin, $this->verdi);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
        ->set('soloObsoleti', true)
        ->assertSee('Nessun risultato per i filtri applicati')
        ->assertDontSee('Nessuna macchina per i clienti nel perimetro');
});

it('goes back to the first page when either state filter changes', function () {
    // Cambiare filtro cambia l'insieme: restare a pagina 2 atterrerebbe fuori
    // dall'elenco.
    Strumento::factory()->count(40)->forNode($this->sedeBianchi)->create([
        'data_installazione' => today()->toDateString(),
    ]);

    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->call('setPage', 2)
        ->set('stato', StatoSemaforo::Verde->value)
        ->assertSet('paginators.page', 1);

    Livewire::test(ParcoGlobale::class)
        ->call('setPage', 2)
        ->set('soloObsoleti', true)
        ->assertSet('paginators.page', 1);
});

it('costs a single extra query when the obsolescence filter is on, not one per row', function () {
    // ⚠️ Le soglie si leggono **una volta**, raggruppate per valore: un ramo
    // `OR` per sede — la forma di `scopeObsoleti()`, corretta là dove gli Enti
    // sono uno o pochi — qui crescerebbe col numero di clienti, cioè con
    // l'unica dimensione che questa scheda esiste per far crescere.
    //
    // ⚠️ Le due macchine si fissano vecchie apposta: se il filtro svuotasse la
    // pagina, la misura «con» risparmierebbe le tre fonti del semaforo e la
    // rilettura dei clienti — cioè conterebbe **meno** query di quella «senza»,
    // e il test misurerebbe una pagina vuota invece di un filtro.
    $this->autoclaveRossi->update(['data_installazione' => today()->subYears(20)->toDateString()]);
    $this->autoclaveBianchi->update(['data_installazione' => today()->subYears(20)->toDateString()]);

    $this->actingAs($this->superadmin);

    // Un giro a vuoto per scaldare la cache dei permessi di spatie.
    Livewire::test(ParcoGlobale::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::withQueryParams([])->test(ParcoGlobale::class);
    $senza = count(DB::getQueryLog());
    DB::disableQueryLog();

    DB::flushQueryLog();
    DB::enableQueryLog();
    // `withQueryParams()` e non `set()`: `set()` monta col default e poi
    // RIRENDERIZZA, cioè misurerebbe due pagine invece di una.
    Livewire::withQueryParams(['soloObsoleti' => true])->test(ParcoGlobale::class);
    $con = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($con)->toBe($senza + 1);
});

// ─── 6-ter. Il tasto «impersona» atterra SULLA MACCHINA ─────────────────────

it('lands on the machine of the row even when the member had to be picked', function () {
    // La modale si apre DA una riga, quindi conosce la macchina: i due rami del
    // tasto — membro unico e membro da scegliere — devono portare allo stesso
    // posto, o metà delle righe continuerebbe a rimbalzare in dashboard.
    $secondo = User::factory()->create(['tenant_id' => $this->sedeBianchi->id, 'name' => 'Carla Bianchi']);
    $secondo->assignRole('Tenant');
    $this->bianchi->aggiungiMembro($secondo);

    alComandoDelParco($this->superadmin);

    $html = Livewire::test(ParcoGlobale::class)
        ->call('apriSceltaSuMacchina', $this->bianchi->id, $this->autoclaveBianchi->id)
        ->html();

    expect($html)->toContain(versoLaMacchinaDelParco($this->adminBianchi, $this->autoclaveBianchi).'"');
    expect($html)->toContain(versoLaMacchinaDelParco($secondo, $this->autoclaveBianchi).'"');
});

it('forgets the machine of the previous row when the picker is reopened without one', function () {
    // 🔴 `apriScelta()` resta chiamabile senza nominare una macchina — da un
    // test, e dalla property che il browser può spingere — e passa da
    // `chiudiOgniModale()`, che azzera `macchinaScelta`. Senza quell'azzeramento
    // la modale del cliente B offrirebbe di atterrare sulla macchina del
    // cliente A: il controller lo intercetterebbe, ma sarebbe una destinazione
    // sbagliata offerta da noi.
    alComandoDelParco($this->superadmin);

    $html = Livewire::test(ParcoGlobale::class)
        ->call('apriSceltaSuMacchina', $this->bianchi->id, $this->autoclaveBianchi->id)
        ->call('apriScelta', $this->rossi->id)
        ->html();

    // ⚠️ Si guarda la **modale** e non la pagina: ogni riga della tabella porta
    // ormai un `/strumento/<id>` legittimo, quindi una negazione sull'HTML
    // intero sarebbe rossa per sempre — e una sull'id sbagliato sarebbe
    // soddisfatta dalla riga di quella stessa macchina.
    $modale = substr($html, strpos($html, 'Impersona un membro di'));

    expect($modale)->toContain(route('impersonate', $this->adminRossi).'"');
    expect($modale)->not->toContain('/strumento/');
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

    preferisci($this->superadmin, $this->rossi);
    $componente = Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI);

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

it('does not read the client list in the modes that do not draw one', function () {
    // 🔴 Finché c'era la `<select multiple>`, `render()` leggeva e idratava
    // **ogni** account della piattaforma a ogni giro — anche nel modo «tutti»,
    // dove nessun controllo la mostrava, e a ogni battuta nella casella di
    // ricerca, che gira in `wire:model.live.debounce`. Sparito il multi-select,
    // quella lettura è puro spreco: resta solo dove qualcosa la disegna.
    //
    // ⚠️ Si guarda la **forma SQL** e non il numero di query: un conteggio
    // resterebbe verde se una lettura sparisse e un'altra nascesse. L'ago è
    // `order by "accounts"."ragione_sociale"`, che è l'ordinamento dell'elenco
    // dei clienti e di nient'altro in questa pagina.
    $elencoClienti = fn () => collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $q) => str_contains($q, 'order by "accounts"."ragione_sociale"'))
        ->count();

    alComandoDelParco($this->superadmin);

    preferisci($this->superadmin, $this->rossi);

    // Un giro a vuoto: spatie carica ruoli e permessi al primo controllo, e
    // sono query della sessione, non della pagina.
    Livewire::test(ParcoGlobale::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ParcoGlobale::class)->set('modo', Perimetro::TUTTI);
    $conTutti = $elencoClienti();
    DB::disableQueryLog();

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ParcoGlobale::class)->set('modo', Perimetro::PREFERITI);
    $conPreferiti = $elencoClienti();
    DB::disableQueryLog();

    expect($conTutti)->toBe(0);

    // ⚠️ E il confronto ha bisogno del secondo termine: senza, un `preferiti()`
    // che non legge mai nulla soddisferebbe la riga qui sopra — cioè il test
    // sarebbe verde su una pagina che i preferiti non li mostra.
    expect($conPreferiti)->toBeGreaterThan(0);
});

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

    // ⚠️ La rotta è quella che ATTERRA SULLA MACCHINA, non `impersonate` nuda:
    // il tasto esiste per intervenire in fretta, e la destinazione fissa del
    // pacchetto costringeva a ritrovare a mano la macchina appena vista in
    // elenco (Marco, 29 Ago 2026).
    Livewire::test(ParcoGlobale::class)
        ->assertSee('Impersona')
        ->assertSee(versoLaMacchinaDelParco($this->adminRossi, $this->autoclaveRossi), false);
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

    // La virgoletta di chiusura fa parte dell'ago: `…/strumento/1` è un prefisso
    // di `…/strumento/12`, e senza di essa l'asserzione negativa potrebbe essere
    // soddisfatta da un link sbagliato.
    $verso = fn (User $membro, Strumento $macchina) => versoLaMacchinaDelParco($membro, $macchina).'"';

    expect($rigaRossi)->toContain($verso($this->adminRossi, $this->autoclaveRossi));
    expect($rigaRossi)->not->toContain($verso($this->adminBianchi, $this->autoclaveRossi));
    expect($rigaBianchi)->toContain($verso($this->adminBianchi, $this->autoclaveBianchi));
    expect($rigaBianchi)->not->toContain($verso($this->adminRossi, $this->autoclaveBianchi));

    // 🔴 E la seconda metà della legatura, che prima non esisteva: il link porta
    // anche la MACCHINA della riga. Un id di strumento preso dalla prima riga
    // farebbe entrare nel cliente giusto e atterrare sulla macchina sbagliata —
    // o, se quella macchina è di un'altra sede, in dashboard con un messaggio
    // che sembra un difetto.
    expect($rigaRossi)->not->toContain('/strumento/'.$this->autoclaveBianchi->id.'"');
    expect($rigaBianchi)->not->toContain('/strumento/'.$this->autoclaveRossi->id.'"');
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

    expect($riga)->toContain('apriSceltaSuMacchina('.$this->bianchi->id.', '.$this->autoclaveBianchi->id.')');
    expect($riga)->not->toContain('apriSceltaSuMacchina('.$this->rossi->id.', ');
    expect($riga)->not->toContain(', '.$this->autoclaveRossi->id.')');
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

    preferisci($this->superadmin, $solo);
    Livewire::test(ParcoGlobale::class)
        ->set('modo', Perimetro::PREFERITI)
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

    preferisci($this->superadmin, $this->rossi);
    Livewire::test(ParcoGlobale::class)
        ->call('apriScelta', $this->bianchi->id)
        ->set('modo', Perimetro::PREFERITI)
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
        app_path('Support/Piattaforma/StrumentiPerStato.php'),
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

// ─── 11. Le tre correzioni del 29 Ago 2026 ──────────────────────────────────

it('forgets the machine of the previous row when the picker is reopened from the browser', function () {
    // 🔴 La modale ha DUE strade di apertura, e finora solo una azzerava la
    // macchina ricordata. `apriScelta()` passa da `chiudiOgniModale()`; la
    // property spinta dal browser — `$wire.set('sceltaImpersonazione', …)`, che
    // il trait documenta come reale e difende col Gate — no: entra da
    // `updatingSceltaImpersonazione()`, che autorizza e rilegge l'account, e
    // basta.
    //
    // Il risultato non è una falla di autorizzazione (chi atterra sulla
    // macchina di un altro cliente finisce in dashboard con un messaggio), ma è
    // una destinazione sbagliata offerta da NOI: la modale dice «Impersona un
    // membro di Gruppo Rossi» e il link porta la macchina di Lab Bianchi.
    alComandoDelParco($this->superadmin);

    $componente = Livewire::test(ParcoGlobale::class)
        ->call('apriSceltaSuMacchina', $this->bianchi->id, $this->autoclaveBianchi->id)
        ->set('sceltaImpersonazione', $this->rossi->id)
        ->assertSet('macchinaScelta', null);

    $html = $componente->html();
    $inizio = strpos($html, 'Impersona un membro di');

    expect($inizio)->not->toBeFalse();

    // ⚠️ Si guarda la **modale** e non la pagina: ogni riga della tabella porta
    // un `/strumento/<id>` legittimo, quindi una negazione sull'HTML intero
    // sarebbe rossa per sempre.
    $modale = substr($html, $inizio);

    expect($modale)->toContain(route('impersonate', $this->adminRossi).'"');
    expect($modale)->not->toContain('/strumento/');
});

it('forgets the machine of the previous row when the picker is closed and reopened', function () {
    // La terza strada verso lo stesso stato: si chiude la modale col suo tasto
    // — `chiudiScelta()`, che azzerava il solo cliente — e si riapre dalla
    // property. Senza l'azzeramento la macchina della riga di prima sopravvive
    // a entrambi i gesti.
    alComandoDelParco($this->superadmin);

    Livewire::test(ParcoGlobale::class)
        ->call('apriSceltaSuMacchina', $this->bianchi->id, $this->autoclaveBianchi->id)
        ->call('chiudiScelta')
        ->assertSet('macchinaScelta', null);
});

it('shows the obsolescence badge on the row, with the threshold of ITS OWN site', function () {
    // 🔴 La scheda filtrava per obsolescenza senza MOSTRARLA: «⏳» compariva
    // una volta sola in tutta la vista, nell'etichetta del checkbox. Spuntando
    // «Solo obsoleti» si otteneva un elenco di righe visivamente identiche a
    // prima, e nessuna colonna diceva perché quelle e non altre.
    //
    // ⛔ E il rimedio ovvio era sbagliato: `<x-ui.obsoleto>` chiama
    // `Strumento::isObsoleto()` → `sogliaObsolescenza()` → `$this->tenant`, che
    // passa dal `TenantScope` e cross-cliente risolve a NULL, ricadendo sul
    // default 10 — cioè direbbe il falso proprio sulle sedi con soglia diversa,
    // che è il caso che il filtro gestisce con cura. Le due macchine qui sotto
    // hanno la STESSA età: a separarle è solo la soglia della loro sede.
    $this->sedeRossi->update(['soglia_obsolescenza_anni' => 5]);
    $this->sedeBianchi->update(['soglia_obsolescenza_anni' => 15]);

    $cappaRossi = Strumento::factory()->forNode($this->sedeRossi)->create([
        'nome' => 'Cappa Rossi', 'data_installazione' => today()->subYears(10)->toDateString(),
    ]);
    $cappaBianchi = Strumento::factory()->forNode($this->sedeBianchi)->create([
        'nome' => 'Cappa Bianchi', 'data_installazione' => today()->subYears(10)->toDateString(),
    ]);

    alComandoDelParco($this->superadmin);

    $html = Livewire::test(ParcoGlobale::class)->set('perPage', 100)->html();

    expect(rigaDelParco($html, $cappaRossi->id))->toContain('Obsoleto');
    expect(rigaDelParco($html, $cappaBianchi->id))->not->toContain('Obsoleto');

    // Il tooltip dice la soglia VERA della sede, non il fallback a 10: è la
    // frase che rende la selezione leggibile invece che arbitraria.
    expect(rigaDelParco($html, $cappaRossi->id))->toContain('oltre la soglia di 5 anni');
});

it('badges on the row exactly the machines the obsolescence filter selects', function () {
    // 🔴 Il filo fra ciò che la riga DICE e ciò che il filtro SELEZIONA. Sono
    // due letture diverse della stessa regola (🔗 ADR-014) — una in PHP sulla
    // riga, una in SQL prima di paginare — e il difetto che nasce quando
    // divergono è muto: un badge ⏳ su una riga che il filtro non prende, o il
    // contrario. Con tre soglie diverse in gioco, e il confine INCLUSIVO.
    $this->sedeRossi->update(['soglia_obsolescenza_anni' => 5]);
    $this->sedeBianchi->update(['soglia_obsolescenza_anni' => 15]);
    $terzi = Account::factory()->create(['ragione_sociale' => 'Studio Terzi', 'piano' => 'free']);
    // La terza soglia è quella di default, che la colonna dichiara NOT NULL:
    // il fallback a 10 di `sogliaObsolescenza()` è difensivo, non raggiungibile
    // da qui, e non va provato con una fixture che il DB rifiuta.
    $sedeTerzi = UnitaOrganizzativa::factory()->ente()->perAccount($terzi)->create([
        'nome' => 'Sede Terzi', 'soglia_obsolescenza_anni' => 10,
    ]);

    foreach ([[$this->sedeRossi, 5], [$this->sedeBianchi, 15], [$sedeTerzi, 10]] as [$sede, $soglia]) {
        foreach ([0, $soglia - 1, $soglia, $soglia + 1] as $eta) {
            Strumento::factory()->forNode($sede)->create([
                'nome' => 'Muffola '.$sede->nome.' '.$eta,
                'data_installazione' => today()->subYears($eta)->toDateString(),
            ]);
        }

        // Il confine è INCLUSIVO e si esprime `< limite+1`: installata
        // esattamente N anni fa oggi è già obsoleta, il giorno dopo no.
        Strumento::factory()->forNode($sede)->create([
            'nome' => 'Muffola '.$sede->nome.' confine',
            'data_installazione' => today()->subYears($soglia)->addDay()->toDateString(),
        ]);

        // Senza data non è mai obsoleta: manca la base del calcolo.
        Strumento::factory()->forNode($sede)->create([
            'nome' => 'Muffola '.$sede->nome.' senza data', 'data_installazione' => null,
        ]);
    }

    alComandoDelParco($this->superadmin);

    $tutte = Livewire::test(ParcoGlobale::class)->set('perPage', 100);
    $html = $tutte->html();

    $conBadge = $tutte->viewData('strumenti')->getCollection()
        ->filter(fn (Strumento $s) => str_contains(rigaDelParco($html, $s->id), 'Obsoleto'))
        ->pluck('id')->sort()->values()->all();

    $dalFiltro = Livewire::test(ParcoGlobale::class)
        ->set('perPage', 100)
        ->set('soloObsoleti', true)
        ->viewData('strumenti')->getCollection()->pluck('id')->sort()->values()->all();

    expect($conBadge)->not->toBeEmpty();
    expect($conBadge)->toBe($dalFiltro);
});

it('never lets the view reassign a property the component owns', function () {
    // 🔴 Nel `@forelse` il blocco `@php` assegnava `$stato = $righe->semaforo(…)`,
    // sovrascrivendo la property pubblica `$stato` — il filtro semaforo, che
    // Livewire passa alla vista. Dopo il primo giro di loop la variabile era uno
    // `StatoSemaforo` invece della stringa arrivata dalla query string, e restava
    // tale per tutto il resto del template.
    //
    // Oggi nulla, sotto la tabella, rilegge quelle property: il difetto è
    // INVISIBILE a qualunque test comportamentale, ed è la ragione per cui la
    // prova è strutturale. Basta una striscia dei filtri attivi in fondo alla
    // pagina — `@if ($stato) …` — perché annunci lo stato dell'ULTIMA riga
    // invece del filtro scelto, e solo a tabella piena.
    // ⚠️ I commenti si tolgono, come nel guardrail delle scritture: questi
    // blocchi spiegano per esteso perché una property non va assegnata, e un
    // guardrail che legge il testo invece del codice punisce chi documenta.
    // Compilando, `{{-- --}}` sparisce e `@php … @endphp` diventa PHP vero: i
    // suoi commenti tornano commenti, e li toglie il tokenizer.
    $vista = collect(token_get_all(Blade::compileString(file_get_contents(
        resource_path('views/livewire/piattaforma/parco-globale.blade.php')
    ))))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    $proprieta = collect((new ReflectionClass(ParcoGlobale::class))->getProperties(ReflectionProperty::IS_PUBLIC))
        ->reject(fn (ReflectionProperty $p) => $p->isStatic())
        ->map(fn (ReflectionProperty $p) => $p->getName());

    expect($proprieta)->toContain('stato');

    foreach ($proprieta as $nome) {
        // `=` seguito da qualunque cosa che non sia `=` o `>`: si escludono
        // `==`, `===` e `=>`, che non sono assegnamenti.
        expect(preg_match('/\$'.$nome.'\s*=[^=>]/', $vista))->toBe(0);
    }
});

it('keeps the obsolescence filter from growing a binding per site in the perimeter', function () {
    // ⚠️ `sediPerSoglia()` raggruppa per valore di soglia — K rami invece di N —
    // ma ogni ramo portava la lista ESPLICITA degli id delle sedi, cioè un
    // binding per sede del perimetro: l'asse che il docblock dichiara di aver
    // messo al riparo, «l'unica dimensione che questa scheda esiste per far
    // crescere». Il confine principale sulle stesse sedi è una sottoquery poche
    // righe sopra, e questo può esserlo altrettanto.
    //
    // Si misura sull'**SQL** e non sui dati: il risultato è identico nelle due
    // forme, quindi nessun test comportamentale può cogliere la differenza.
    $sql = function (): array {
        $componente = new ParcoGlobale;
        $componente->soloObsoleti = true;

        $macchine = (new ReflectionClass($componente))->getMethod('macchine');
        $macchine->setAccessible(true);

        $query = $macchine->invoke($componente, Perimetro::tutti());

        return [$query->toSql(), count($query->getBindings())];
    };

    // ⚠️ Una soglia SOLA per tutte le sedi, prima e dopo: a crescere dev'essere
    // N — il numero di sedi — e non K, il numero di soglie distinte, che è
    // l'asse su cui un ramo in più è previsto e dichiarato.
    foreach ([$this->sedeRossi, $this->sedeBianchi, $this->sedeVerdi] as $sede) {
        $sede->update(['soglia_obsolescenza_anni' => 7]);
    }

    $this->actingAs($this->superadmin);

    [, $poche] = $sql();

    for ($i = 0; $i < 20; $i++) {
        UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create([
            'nome' => 'Sede Rossi '.$i, 'soglia_obsolescenza_anni' => 7,
        ]);
    }

    [, $molte] = $sql();

    expect($molte)->toBe($poche);
});

it('refuses to guess a threshold for a row that did not come through the page query', function () {
    // ⛔ Il fallback accomodante — un `?? 10` su una colonna assente — sarebbe
    // il difetto stesso che il badge esiste per evitare: la soglia sbagliata su
    // tutte le righe, in silenzio e in modo plausibile. Chi disegna una riga
    // senza la join deve saperlo subito, non scoprirlo da un tooltip che mente.
    $righe = RigheParcoStrumenti::perPagina(collect(), Perimetro::tutti());

    expect(fn () => $righe->soglia(new Strumento))->toThrow(LogicException::class);
});
