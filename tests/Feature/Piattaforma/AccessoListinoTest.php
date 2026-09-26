<?php

use App\Livewire\Piattaforma\Errori;
use App\Livewire\Piattaforma\Listino;
use App\Models\Piano;
use App\Support\Listino\CatalogoPiani;
use App\Support\Piani;
use App\Support\Rbac;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * 🔴 Chi entra nel listino dei piani, e chi no (🔗 ADR-035).
 *
 * Il quarto file di questa famiglia, e torna al verdetto di
 * `AccessoEditorRuoliTest` dopo la deviazione di `AccessoErroriTest`: la
 * domanda è sempre la stessa — *su quale permesso si gata una pagina di
 * piattaforma?* — e il criterio è sempre quello, *un permesso del set bloccato*.
 * `billing.manage_global` lo è, ed è dei soli Developer e Superadmin.
 *
 * 🔴 **Il permesso non è nuovo, e la scelta di non crearne uno è la decisione
 * che questo file custodisce.** ADR-035 la scrive per esteso: un
 * `listino.manage` sarebbe stato un ottavo permesso bloccato e un riseeding di
 * `config/rbac.php` per dire ciò che `billing.manage_global` già dice — «questa
 * persona decide quanto costa il software». È l'errore già commesso e corretto
 * una volta, con `system.errors.view`. Chi un giorno vorrà separare «governare
 * il listino» da «governare la fatturazione» trova qui il perché, invece di
 * scoprirlo aggiungendo una riga a un file che va poi riseminato con un comando
 * che cancella le personalizzazioni di runtime.
 *
 * ⚠️ Il gesto vero della pagina — creare un piano — **fissa un prezzo**, e lo
 * stesso click crea o aggiorna un oggetto di fatturazione su Stripe. Non è una
 * pagina di lettura con qualche bottone: è la leva commerciale del prodotto.
 */
/**
 * Gli elenchi dei dataset, **propri di questo file e per significato**.
 *
 * Non si riusano `RUOLI_CON_PIATTAFORMA` / `RUOLI_SENZA_PIATTAFORMA` per la
 * ragione già scritta in `AccessoEditorRuoliTest`: partizionano su una domanda
 * diversa (`tenants.view_all`), e oggi le due partizioni coincidono — cioè
 * fonderle sarebbe un errore invisibile finché un ruolo non avesse l'una e non
 * l'altra. `AccessoErroriTest` dimostra che quel giorno arriva davvero.
 *
 * Costanti e **non closure**: i closure dei dataset si risolvono a collection
 * time, prima che Laravel sia avviato, e il dataset nascerebbe vuoto — Pest
 * scarterebbe i test in silenzio (`DatasetMissing`).
 */
const RUOLI_CON_LISTINO = ['Developer', 'Superadmin'];
const RUOLI_SENZA_LISTINO = ['Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico'];

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

// `utenteConRuolo()`, `snapshotDa()` e `navDiPiattaforma()` vivono in
// `tests/Pest.php`: ridichiararle qui sarebbe un fatal.

// ─── I negativi ──────────────────────────────────────────────────────────────

it('refuses the price list to an Admin, who does hold billing.manage_own but not the global one', function () {
    // 🔴 Il negativo con la **ragione nello stesso corpo**. L'Admin è il ruolo
    // che rende non ovvia la scelta: la fatturazione la tocca davvero — ha
    // `billing.manage_own`, cioè governa l'abbonamento del **proprio** account
    // e apre il portale Stripe — e sembrerebbe il destinatario naturale di una
    // pagina «dei piani». Ma qui non si governa un abbonamento: si decide
    // **quanto costa il software a tutti**, e un cliente che potesse crearsi un
    // piano da 0 € con Enti illimitati avrebbe finito di pagare.
    expect(Rbac::permissionsForRole('Admin'))->toContain('billing.manage_own')
        ->and(Rbac::permissionsForRole('Admin'))->not->toContain(Listino::PERMESSO)
        // E il permesso è **bloccato**: l'editor di /piattaforma/ruoli non può
        // darglielo nemmeno volendo. Aprire il listino a un ruolo cliente resta
        // un commit su `config/rbac.php` più un riseeding, cioè un gesto che si
        // vede in un diff.
        ->and(Rbac::isLocked(Listino::PERMESSO))->toBeTrue();

    $this->actingAs(utenteConRuolo('Admin'))
        ->get(route('piattaforma.piani'))
        ->assertForbidden();
});

it('refuses the route to every role without billing.manage_global', function (string $ruolo) {
    $this->actingAs(utenteConRuolo($ruolo))
        ->get(route('piattaforma.piani'))
        ->assertForbidden();
})->with(RUOLI_SENZA_LISTINO);

it('refuses the page to someone who governs billing but may not read the platform', function () {
    // 🔴 **La pagina legge `accounts`, e quella lettura ha una porta.** Le
    // colonne «Clienti» e il conteggio di chi finirebbe sopra il tetto sono
    // censimenti di **tutti gli account della piattaforma**: passano da
    // `VistaPiattaforma::accounts()`, che chiede `tenants.view_all` prima di
    // consegnare il builder. Scritte con un `Account::query()` a mano — com'erano
    // — quella verifica non girava mai, e la pagina restava gatata sul solo
    // `billing.manage_global`.
    //
    // ⚠️ **Oggi non cambia nessun verdetto**, ed è il motivo per cui il difetto
    // era invisibile: i due permessi stanno sugli stessi due ruoli, e il test in
    // fondo al file congela il fatto. Ma è precisamente la divergenza che la
    // scelta di NON mettere i due permessi in AND mette in conto — e il giorno in
    // cui arrivasse un ruolo con l'uno e non l'altro, quella persona leggerebbe
    // il censimento dei clienti di tutta la piattaforma senza attraversare la
    // sola guardia che esiste per quel dato. `BypassNudiGuardrailTest` non lo
    // vedrebbe: guarda `withoutGlobalScopes()`, e qui non ce n'è nessuno.
    //
    // Lo stato si fabbrica con l'API di spatie, come per la voce di menù: nessun
    // ruolo del catalogo lo realizza, e l'editor dei permessi non può produrlo
    // perché entrambi sono nel set bloccato.
    Role::findByName('Superadmin', 'web')->revokePermissionTo(VistaPiattaforma::PERMESSO);

    expect(Rbac::isLocked(VistaPiattaforma::PERMESSO))->toBeTrue();

    Livewire::actingAs(utenteConRuolo('Superadmin'))
        ->test(Listino::class)
        ->assertForbidden();
});

it('sends a guest to the login', function () {
    $this->get(route('piattaforma.piani'))->assertRedirect(route('login'));
});

it('gates the render itself, not only the route', function (string $ruolo) {
    // `Livewire::test()` **disabilita i middleware**: se il gate vivesse solo
    // sulla rotta, questo passerebbe. A rispondere è il `Gate::authorize()` in
    // testa a `render()` — che qui va scritto a mano, perché come per l'editor
    // dei ruoli non c'è nessuna porta (`VistaPiattaforma`) che lo chieda per
    // conto della pagina: `piani` e `prezzi_piano` sono tabelle globali, senza
    // tenancy, quindi non c'è alcuno scope da togliere e una
    // `VistaPiattaforma::piani()` sarebbe il «bypass finto» che il docblock
    // della porta rifiuta per nome.
    Livewire::actingAs(utenteConRuolo($ruolo))
        ->test(Listino::class)
        ->assertForbidden();
})->with(RUOLI_SENZA_LISTINO);

it('gates every writing action, not only the render', function (string $azione, array $argomenti) {
    // 🔴 **La differenza fra questa pagina e le tre che la precedono.** Il
    // registro di audit e l'elenco degli errori sono in sola lettura, e l'editor
    // dei ruoli ha **un** gesto. Qui ce ne sono otto, e ognuno scrive: crea un
    // piano, ne cambia il prezzo, lo archivia, chiama Stripe. Un
    // `Gate::authorize()` che vivesse solo in `render()` reggerebbe finché
    // nessuna azione guadagna `skipRender()` — e il giorno in cui una lo
    // guadagnasse, scriverebbe **prima** che il 403 possa arrivare.
    //
    // Si prova quindi **ogni azione, una per una**, e il dataset è ciò che rende
    // rumorosa l'aggiunta di una nona: chi la scrive senza il gate non trova qui
    // un rosso — trova un buco, ed è per questo che il test in fondo al file
    // conta i metodi pubblici e li confronta con questo elenco.
    // ⚠️ **Il componente si monta con un utente che PUÒ, e il permesso sparisce
    // subito dopo.** Montarlo direttamente con l'Admin non proverebbe niente: il
    // `mount` renderizza, `render()` risponde 403 e non si arriva mai a chiamare
    // l'azione — il test morirebbe sullo snapshot rotto invece che sulla
    // guardia. Lo scambio a metà volo riproduce lo stato vero da temere: una
    // pagina aperta da chi poteva, e un'azione premuta da chi non può più.
    //
    // ⚠️ **E questo test NON è il sentinella dei gate d'azione: va detto invece
    // di lasciarlo credere.** Misurato: togliendo il `Gate::authorize()` da
    // `crea()` questo resta **verde**, perché `render()` gira comunque dopo
    // l'azione e risponde 403 lui — difesa in profondità che qui maschera
    // esattamente la guardia che si vorrebbe provare. È la stessa cicatrice di
    // `AccessoErroriTest`. Il sentinella vero è il test qui sotto, che guarda
    // **cosa è rimasto scritto**.
    $componente = Livewire::actingAs(utenteConRuolo('Superadmin'))->test(Listino::class);

    auth()->login(utenteConRuolo('Admin'));

    $componente->call($azione, ...$argomenti)->assertForbidden();
})->with([
    'apriCreazione' => ['apriCreazione', []],
    'crea' => ['crea', []],
    'apriModifica' => ['apriModifica', [1]],
    'salva' => ['salva', []],
    'procedi' => ['procedi', []],
    'sincronizza' => ['sincronizza', [1]],
    'archivia' => ['archivia', [1]],
    'riattiva' => ['riattiva', [1]],
    'apriAggancio' => ['apriAggancio', [1]],
    'aggancia' => ['aggancia', []],
    'confrontaConStripe' => ['confrontaConStripe', []],
]);

it('refuses the write itself, not merely the render that follows it', function () {
    // 🔴 **Il sentinella vero dei gate d'azione.** Un 403 che arrivasse *dopo*
    // l'INSERT è un 403 inutile: il piano esiste, il prezzo è cambiato, il
    // Product su Stripe è stato creato — e la risposta dice «vietato». Qui si
    // guarda quindi **il database**, non il codice di stato: è la sola
    // asserzione che diventa rossa se un'azione perde il proprio
    // `Gate::authorize()`.
    //
    // Tre gesti e non uno, scelti perché scrivono in tre posti diversi: una
    // riga nuova, una colonna di una riga esistente, e la leva che chiama
    // Stripe. Un quarto lo aggiungerebbe chi scrive la quarta forma di
    // scrittura.
    $saas = Piani::modello('saas');

    // ⚠️ **Un montaggio per gesto, e non è pignoleria**: dopo un 403 lo snapshot
    // del componente non è più valido, quindi una seconda `call()` sulla stessa
    // istanza morirebbe sullo snapshot invece che sulla guardia — cioè il test
    // fallirebbe per la ragione sbagliata, che è il modo in cui una rete smette
    // di misurare senza smettere di essere rossa.
    //
    // La preparazione gira **mentre si può**: è ciò che riempie lo stato del
    // form, così il gesto respinto sarebbe altrimenti perfettamente eseguibile.
    // Senza, si proverebbe che un'azione impossibile non fa niente.
    $respinta = function (callable $preparazione, string $azione, array $argomenti = []) {
        $componente = Livewire::actingAs(utenteConRuolo('Superadmin'))->test(Listino::class);

        $preparazione($componente);

        auth()->login(utenteConRuolo('Admin'));

        $componente->call($azione, ...$argomenti)->assertForbidden();
    };

    $respinta(fn ($c) => $c->call('apriCreazione')
        ->set('nuovo.codice', 'enterprise')
        ->set('nuovo.etichetta', 'Enterprise')
        ->set('nuovo.prezzo_mensile_cent', '19900'), 'crea');

    $respinta(fn ($c) => $c->call('apriModifica', $saas->id)
        ->set('modifica.prezzo_mensile_cent', '9900'), 'salva');

    $respinta(fn ($c) => $c, 'archivia', [$saas->id]);

    app(CatalogoPiani::class)->dimentica();

    expect(Piano::query()->count())->toBe(2)
        ->and($saas->fresh()->prezzo_mensile_cent)->toBe(4900)
        ->and($saas->fresh()->attivo)->toBeTrue();
});

it('keeps the permission on a real Livewire update', function () {
    // 🔴 **L'unica strada che copre un'azione con `skipRender()`**: un POST vero
    // a `/livewire/update`. Livewire rilegge `memo.path`, rimatcha la rotta e
    // riapplica i middleware **persistenti**, fra cui `Authorize` (il `can:`).
    //
    // ⚠️ Si chiama un'azione che **scrive** e non un `$refresh`: su questa
    // pagina è la differenza fra provare il gate della lettura e provare quello
    // della scrittura, e la seconda è la sola che conti — un 403 che arrivasse
    // dopo l'INSERT sarebbe un 403 inutile.
    //
    // ⚠️ Lo snapshot si prende **per nome del componente**: il primo della
    // pagina è lo switcher di ente, montato dal layout su ogni schermata (vedi
    // `snapshotDa()`).
    $utente = utenteConRuolo('Superadmin');

    $html = $this->actingAs($utente)->get(route('piattaforma.piani'))->assertOk()->getContent();
    $snapshot = snapshotDa($html, 'piattaforma.listino');

    expect($snapshot)->not->toBe('');

    // Il permesso sparisce mentre la pagina è aperta: lo snapshot è firmato e
    // resta valido, quindi è esattamente il caso che il middleware deve fermare.
    $utente->removeRole('Superadmin');
    auth()->logout();
    $this->actingAs($utente->fresh());

    $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'apriCreazione', 'params' => []]],
        ]],
    ])->assertForbidden();
});

it('never shows the way in to someone who cannot go in', function () {
    // Una voce di menù che porta a un 403 è un invito a bussare.
    //
    // ⚠️ Il caso si costruisce **a mano**, e va detto perché: oggi
    // `billing.manage_global` e `tenants.view_all` stanno sugli stessi due
    // ruoli, quindi chi non ha il primo non vede nemmeno la nav. L'unico
    // soggetto interessante — chi guarda la piattaforma ma non governa il
    // listino — nessun ruolo del catalogo lo realizza, e l'editor dei permessi
    // non può produrlo perché `billing.manage_global` è bloccato. Lo si fabbrica
    // con l'API di spatie, che è codice: la sola via che il progetto lascia
    // aperta.
    //
    // ⚠️ **Si asserisce estraendo il blocco della nav**, non sulla pagina
    // intera: un `assertDontSee` sull'HTML sarebbe verde comunque, ma il suo
    // rovescio positivo no — «Piani» compare altrove nella cabina (la colonna
    // dei clienti nomina il piano di ciascuno), quindi un positivo sulla pagina
    // intera resterebbe verde **anche con la voce rimossa**. È la cicatrice già
    // pagata due volte su «Clienti».
    Role::findByName('Superadmin', 'web')->revokePermissionTo(Listino::PERMESSO);

    $blocco = navDiPiattaforma($this->actingAs(utenteConRuolo('Superadmin'))
        ->get(route('piattaforma.index'))
        ->assertOk()
        ->getContent());

    // Le altre voci ci sono ancora: senza queste righe il test passerebbe anche
    // se la nav fosse sparita del tutto.
    expect($blocco)->toContain('Clienti')
        ->and($blocco)->toContain('Registro di audit')
        ->and($blocco)->toContain('Ruoli e permessi')
        ->and($blocco)->not->toContain('Piani')
        ->and($blocco)->not->toContain(route('piattaforma.piani'));
});

// ─── I positivi ──────────────────────────────────────────────────────────────

it('lets in exactly the roles that hold billing.manage_global', function (string $ruolo) {
    $this->actingAs(utenteConRuolo($ruolo))
        ->get(route('piattaforma.piani'))
        ->assertOk()
        // Una stringa del **corpo**, non «Piani», che la sub-nav stampa su tutte
        // le pagine di piattaforma e renderebbe il test verde anche atterrando
        // sulla cabina.
        ->assertSee('Da qui si crea un piano, se ne cambia il prezzo, e lo stesso gesto arriva su Stripe.', false);
})->with(RUOLI_CON_LISTINO);

it('shows all five platform tabs to the Developer', function () {
    // Il rovescio del negativo, e non è simmetria di cortesia: un permesso
    // sbagliato sulla voce nuova la farebbe sparire per tutti senza che un solo
    // test di autorizzazione se ne accorga. Una voce di menù che scompare non
    // rompe niente — semplicemente, un giorno, nessuno trova più il listino.
    //
    // Il Developer e non il Superadmin: è l'unico che le vede tutte e cinque.
    $blocco = navDiPiattaforma($this->actingAs(utenteConRuolo('Developer'))
        ->get(route('piattaforma.index'))
        ->assertOk()
        ->getContent());

    expect($blocco)->toContain('Clienti')
        ->and($blocco)->toContain('Registro di audit')
        ->and($blocco)->toContain('Ruoli e permessi')
        ->and($blocco)->toContain('Piani')
        ->and($blocco)->toContain('Errori')
        ->and($blocco)->toContain(route('piattaforma.piani'));
});

it('puts the price list before the Developer-only tab', function () {
    // ⚠️ **La posizione è una decisione, non un caso.** «Piani» sta dopo «Ruoli
    // e permessi» e prima di «Errori» perché l'ultima voce deve restare quella
    // del **solo Developer**: la fila che il Superadmin vede finisce così dove
    // finisce il suo insieme di permessi, invece di avere un buco in mezzo.
    // Senza questa riga l'ordine sarebbe una proprietà accidentale dell'array,
    // e il primo che aggiunge una voce in fondo lo perderebbe.
    $blocco = navDiPiattaforma($this->actingAs(utenteConRuolo('Developer'))
        ->get(route('piattaforma.index'))
        ->assertOk()
        ->getContent());

    expect(mb_strpos($blocco, 'Ruoli e permessi'))->toBeLessThan(mb_strpos($blocco, 'Piani'))
        ->and(mb_strpos($blocco, 'Piani'))->toBeLessThan(mb_strpos($blocco, 'Errori'));
});

// ─── Gli elenchi e la catena, asseriti per struttura ─────────────────────────

it('keeps the hand-written role lists honest against the matrix', function () {
    // La rete che tiene onesti i due dataset statici, ed è doppia. La prima metà
    // li allinea alla matrice: senza, un ruolo nuovo (la Dashboard Developer è
    // la voce di roadmap successiva) resterebbe scoperto **per omissione**. La
    // seconda congela che oggi la partizione di `billing.manage_global` coincide
    // con quella di `tenants.view_all` — il fatto su cui poggia la scelta di non
    // mettere i due permessi in AND.
    $partizione = fn (string $permesso) => collect(Rbac::roleNames())
        ->partition(fn (string $r) => in_array($permesso, Rbac::permissionsForRole($r), true))
        ->map(fn ($insieme) => $insieme->values()->all());

    [$conListino, $senzaListino] = $partizione(Listino::PERMESSO);
    [$conPiattaforma, $senzaPiattaforma] = $partizione(VistaPiattaforma::PERMESSO);

    expect($conListino)->toEqualCanonicalizing(RUOLI_CON_LISTINO)
        ->and($senzaListino)->toEqualCanonicalizing(RUOLI_SENZA_LISTINO)
        // L'unione copre il catalogo: nessun ruolo cade fuori da entrambi i
        // dataset, che è il modo in cui un ruolo nuovo resterebbe non provato.
        ->and(array_merge(RUOLI_CON_LISTINO, RUOLI_SENZA_LISTINO))
        ->toEqualCanonicalizing(Rbac::roleNames())
        // La coincidenza fra le due partizioni: asserita, non supposta.
        ->and($conListino)->toEqualCanonicalizing($conPiattaforma)
        ->and($senzaListino)->toEqualCanonicalizing($senzaPiattaforma);
});

it('keeps the price list behind auth, lockout, two-factor and billing.manage_global', function () {
    // Assert **strutturale** e non di comportamento: oggi `billing.manage_global`
    // e `tenants.view_all` stanno sugli stessi due ruoli, quindi scambiare il
    // `can:` di rotta non cambierebbe **nessun** verdetto di 403. Ciò che si
    // congela qui è la **ragione**, non l'effetto — e il giorno in cui i due
    // divergessero, la pagina sarebbe già dal lato giusto.
    $middleware = Route::getRoutes()->getByName('piattaforma.piani')->gatherMiddleware();

    expect($middleware)->toContain('can:'.Listino::PERMESSO)
        ->and($middleware)->toContain('auth')
        // DENTRO il gruppo protetto: il Superadmin è un utente tenant-bound con
        // un account proprio, e se quell'account fosse in lockout deve vedere
        // /bloccato come chiunque (ADR-018).
        ->and($middleware)->toContain('account.lockout')
        ->and($middleware)->toContain('two-factor.enforce')
        // ⚠️ E **non** in AND col permesso della cabina: non aggiungerebbe
        // protezione (chi passa il primo ha già il secondo) e darebbe un secondo
        // modo di rompere la pagina — un 403 sul listino per una ragione che non
        // c'entra col suo confine. ADR-035 lo scrive per nome.
        ->and($middleware)->not->toContain('can:'.VistaPiattaforma::PERMESSO)
        // L'**ordine**, che è l'unica cosa che ADR-013 dice e che `toContain`
        // scarta: la condizione più forte parla per prima.
        ->and(array_search('account.lockout', $middleware, true))
        ->toBeLessThan(array_search('two-factor.enforce', $middleware, true));
});

it('leaves no public action of the component ungated', function () {
    // 🔴 **La rete della rete**, ed è ciò che rende il dataset qui sopra qualcosa
    // di più di un elenco che invecchia. Il difetto da temere non è un'azione
    // gatata male — quella la prende il dataset — ma un'azione **nuova**, scritta
    // fra sei mesi senza `Gate::authorize()` in testa: nessun test esistente
    // diventerebbe rosso, perché nessun test sa che esiste.
    //
    // Si contano quindi i metodi pubblici del componente per **reflection** e si
    // pretende che ognuno sia o nell'elenco provato, o fra quelli che Livewire
    // e la classe base portano con sé. Il giorno in cui ne nasce uno, questo
    // test lo nomina.
    $nostri = collect((new ReflectionClass(Listino::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === Listino::class)
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->values()
        ->all();

    // Ciò che non scrive e non ha bisogno del gate: il render (che il proprio
    // `Gate::authorize()` ce l'ha comunque) e i quattro «chiudi/annulla», che
    // azzerano stato locale del browser e non toccano il database.
    $senzaScrittura = ['render', 'chiudiCreazione', 'chiudiModifica', 'chiudiAggancio', 'annulla'];

    $provati = [
        'apriCreazione', 'crea', 'apriModifica', 'salva', 'procedi',
        'sincronizza', 'archivia', 'riattiva', 'apriAggancio', 'aggancia', 'confrontaConStripe',
    ];

    expect(array_diff($nostri, $provati, $senzaScrittura))->toBe([])
        // E l'elenco dei provati non contiene nomi che non esistono più: un
        // rename lo renderebbe **muto** invece che rosso, cioè toglierebbe la
        // protezione senza dirlo.
        ->and(array_diff($provati, $nostri))->toBe([]);
});
