<?php

use App\Livewire\Piattaforma\Errori;
use App\Support\Rbac;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * 🔴 Chi entra nell'error tracker interno, e chi no (S6).
 *
 * Il terzo file di questa famiglia, e **il primo che rompe la simmetria**.
 * `AccessoRegistroAuditTest` e `AccessoEditorRuoliTest` rispondono alla stessa
 * domanda — *su quale permesso si gata una pagina di piattaforma?* — e arrivano
 * allo stesso criterio (*un permesso del set bloccato*) con verdetti che, di
 * fatto, coincidevano: entrambe le pagine finivano aperte a Developer e
 * Superadmin insieme. Qui no.
 *
 * `system.logs.view` è nel set bloccato **e** è del solo Developer: il
 * Superadmin ne è escluso per eccezione esplicita in `config/rbac.php`, pur
 * avendo ogni altro permesso di piattaforma. Questa è quindi **la prima pagina
 * del progetto che il Superadmin non può vedere**, ed è una decisione di
 * prodotto — da qui si legge ciò che si è rotto in *ogni* Ente, coi messaggi, i
 * percorsi e (dai blocchi successivi) gli input di richiesta.
 *
 * ⚠️ La pagina di questo blocco **non mostra nulla**: è un guscio con la sola
 * intestazione. È deliberato, per la ragione già scritta in `EditorRuoli` — una
 * pagina vuota ma già gatata rende il gate dimostrabile *prima* che ci sia
 * qualcosa da proteggere. Questo file è quindi l'intero valore del blocco.
 */
/**
 * Gli elenchi dei dataset, **propri di questo file e non riusati**.
 *
 * ⚠️ `RUOLI_CON_PIATTAFORMA` / `RUOLI_SENZA_PIATTAFORMA` (in `tests/Pest.php`)
 * partizionano su `tenants.view_all`, e **questa è la prima pagina in cui le due
 * partizioni non coincidono**: il Superadmin sta di là fra i «con» e di qua fra
 * i «senza». Riusarle qui non sarebbe un'imprecisione di stile — invertirebbe
 * metà dei verdetti, mandando il Superadmin fra i positivi di una pagina che
 * deve rifiutargli. È la ragione che `AccessoEditorRuoliTest` dava in via
 * ipotetica («il giorno in cui un ruolo avesse l'una e non l'altra»): quel
 * giorno è oggi.
 *
 * Costanti e **non closure**, come tutte le altre: i closure dei dataset si
 * risolvono a collection time, prima che Laravel sia avviato, quindi
 * `config('rbac.roles')` sarebbe vuoto, il dataset nascerebbe vuoto e Pest
 * scarterebbe i test in silenzio (`DatasetMissing`). A tenerle oneste contro la
 * matrice c'è `keeps the hand-written role lists honest against the matrix`.
 */
const RUOLI_CON_ERRORI = ['Developer'];
const RUOLI_SENZA_ERRORI = ['Superadmin', 'Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico'];

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

// `utenteConRuolo()` e `snapshotDa()` vivono in `tests/Pest.php`: ridichiararle
// qui sarebbe un fatal, e tenerle in un file di test farebbe fallire questo
// file quando lo si esegue da solo.

// ─── I negativi ──────────────────────────────────────────────────────────────

it('refuses the page to the Superadmin, who holds every other platform permission', function () {
    // 🔴 **Il test dell'intera decisione**, e le tre asserzioni stanno nello
    // stesso corpo di proposito. Chi un giorno vorrà «aprire gli errori anche al
    // Superadmin» — richiesta ragionevolissima: è il ruolo che governa la
    // piattaforma, provisiona i clienti, legge il registro di audit e modifica
    // la matrice dei permessi — deve trovare qui il rosso **con la ragione
    // accanto**, invece di un 403 muto da far sparire togliendo un `can:`.
    //
    // Le tre righe dicono, in ordine: che il Superadmin è davvero il ruolo di
    // piattaforma (ha `tenants.view_all`, cioè non gli manca l'accesso per
    // difetto di categoria); che **non** ha `system.logs.view`, e quindi
    // l'esclusione è nella matrice e non in questa pagina; e che quel permesso è
    // nel **set bloccato**, cioè che l'editor di `/piattaforma/ruoli` non può
    // dargliela nemmeno volendo. Aprirla è un commit su `config/rbac.php` più un
    // riseeding — un gesto visibile in un diff, che è esattamente il punto.
    expect(Rbac::permissionsForRole('Superadmin'))->toContain(VistaPiattaforma::PERMESSO)
        ->and(Rbac::permissionsForRole('Superadmin'))->not->toContain(Errori::PERMESSO)
        ->and(Rbac::isLocked(Errori::PERMESSO))->toBeTrue();

    $this->actingAs(utenteConRuolo('Superadmin'))
        ->get(route('piattaforma.errori'))
        ->assertForbidden();
});

it('refuses the route to every role without system.logs.view', function (string $ruolo) {
    $this->actingAs(utenteConRuolo($ruolo))
        ->get(route('piattaforma.errori'))
        ->assertForbidden();
})->with(RUOLI_SENZA_ERRORI);

it('sends a guest to the login', function () {
    $this->get(route('piattaforma.errori'))->assertRedirect(route('login'));
});

it('gates the render itself, not only the route', function (string $ruolo) {
    // `Livewire::test()` **disabilita i middleware**: se il gate vivesse solo
    // sulla rotta, questo passerebbe. A rispondere è il `Gate::authorize()` in
    // testa a `render()` — che qui va scritto a mano, perché come per l'editor
    // dei ruoli non c'è nessuna porta (`VistaPiattaforma`) che lo chieda per
    // conto della pagina: `errori` e `occorrenze_errore` sono tabelle globali,
    // senza tenancy, quindi non c'è alcuno scope da togliere e una
    // `VistaPiattaforma::errori()` sarebbe il «bypass finto».
    Livewire::actingAs(utenteConRuolo($ruolo))
        ->test(Errori::class)
        ->assertForbidden();
})->with(RUOLI_SENZA_ERRORI);

it('keeps the permission on a real Livewire update', function () {
    // 🔴 **L'unica strada che copre un'azione con `skipRender()`**: un POST vero
    // a `/livewire/update`. Livewire rilegge `memo.path`, rimatcha la rotta e
    // riapplica i middleware **persistenti**, fra cui `Authorize` (il `can:`).
    // Le tre azioni del blocco 6 (risolvi/ignora/riapri) girano prima di
    // `render()`, e con `skipRender()` `render()` non gira affatto — quindi il
    // gate di rotta è la sola guardia che resta, e va provato adesso che la
    // pagina è ancora un guscio.
    //
    // 🔴 **Si passa a un Superadmin VERO**, non si toglie il ruolo al Developer:
    // un utente senza alcun ruolo viene respinto da *qualunque* `can:`, quindi
    // quella forma non nomina nessuno. Il Superadmin ha ogni altro permesso di
    // piattaforma e **non** questo — è il soggetto giusto, ed è il primo file
    // del progetto in cui esiste, perché è il primo in cui le due partizioni
    // divergono.
    //
    // ⚠️ **Ma oggi questo test NON è il sentinella del gate di rotta, e va
    // detto invece di lasciarlo credere.** Misurato: né scambiare il `can:` né
    // **toglierlo del tutto** lo rendono rosso, perché `Gate::authorize()` in
    // `render()` risponde comunque 403 — difesa in profondità che qui maschera
    // la guardia che si vorrebbe provare. Il sentinella del gate di rotta è
    // l'assert **strutturale** in fondo al file, che è rosso sotto entrambe.
    //
    // Questo test diventa la sola guardia nel blocco 6, quando le azioni
    // gireranno **prima** di `render()` e con `skipRender()` `render()` non
    // girerà affatto. È scritto adesso, e puntato sul componente giusto, perché
    // allora sia già al suo posto.
    //
    // ⚠️ Lo snapshot si prende **per nome**: il primo della pagina è lo switcher
    // di ente, montato dal layout su ogni schermata. La prima stesura prendeva
    // quello — cioè rinfrescava un componente che non c'entra, e sarebbe
    // rimasta verde qualunque cosa fosse successa a questa pagina.
    //
    // ⚠️ Lo snapshot si prende **per nome del componente**: il primo della
    // pagina è lo switcher di ente, montato dal layout — vedi `snapshotDa()`.
    $html = $this->actingAs(utenteConRuolo('Developer'))
        ->get(route('piattaforma.errori'))->assertOk()->getContent();
    $snapshot = snapshotDa($html, 'piattaforma.errori');

    expect($snapshot)->not->toBe('');

    // Lo snapshot è firmato e resta valido: è esattamente il caso che il
    // middleware deve fermare quando a presentarlo è un altro utente.
    auth()->logout();
    $this->actingAs(utenteConRuolo('Superadmin'));

    $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ])->assertForbidden();
});

it('never shows the way in to someone who cannot go in', function () {
    // 🔴 La quarta voce **non** compare al Superadmin, che è l'unico utente in
    // grado di trovarsi in questo stato senza che nessuno lo fabbrichi: entra
    // nella piattaforma ogni giorno con `tenants.view_all` e non ha
    // `system.logs.view`. Per la terza voce lo stesso test dovette costruire il
    // caso a mano con l'API di spatie, perché nessun ruolo del catalogo lo
    // realizzava; qui il catalogo lo realizza da sé.
    //
    // ⚠️ **Si asserisce estraendo il blocco della nav, non sulla pagina intera.**
    // Un `assertDontSee` sull'HTML sarebbe verde comunque, ma il suo rovescio
    // positivo no: in questo stesso lavoro un `assertSee('Clienti')` sulla
    // pagina intera è stato verde *anche con la voce rimossa*, due volte, perché
    // quella parola compare altrove nella cabina. Le due asserzioni devono
    // guardare lo stesso pezzo di HTML, o quella positiva non prova ciò che
    // quella negativa dà per scontato.
    //
    // ⚠️ E si asserisce sul **testo visibile**, non su un `data-*`: ciò che deve
    // sparire è la voce che l'utente legge e clicca, non un marcatore che il
    // giorno in cui qualcuno lo toglie porterebbe via l'asserzione insieme al
    // difetto.
    $blocco = navDiPiattaforma($this->actingAs(utenteConRuolo('Superadmin'))
        ->get(route('piattaforma.index'))
        ->assertOk()
        ->getContent());

    // Le altre tre ci sono ancora: senza queste righe il test passerebbe anche
    // se la nav fosse sparita del tutto.
    expect($blocco)->toContain('Clienti')
        ->and($blocco)->toContain('Registro di audit')
        ->and($blocco)->toContain('Ruoli e permessi')
        ->and($blocco)->not->toContain('Errori')
        ->and($blocco)->not->toContain(route('piattaforma.errori'));
});

// ─── I positivi ──────────────────────────────────────────────────────────────

it('lets in exactly the roles that hold system.logs.view', function (string $ruolo) {
    $this->actingAs(utenteConRuolo($ruolo))
        ->get(route('piattaforma.errori'))
        ->assertOk()
        // Una stringa del **corpo**, non «Errori», che la sub-nav stampa su
        // tutte e quattro le pagine e renderebbe il test verde anche atterrando
        // sulla cabina.
        ->assertSee('Cosa si è rotto, quante volte, e con quale contesto.', false);
})->with(RUOLI_CON_ERRORI);

it('shows all four platform tabs to the Developer', function () {
    // 🔴 Il rovescio del negativo, e non è simmetria di cortesia: un permesso
    // sbagliato sulla **prima** voce la farebbe sparire per tutti senza che un
    // solo test di autorizzazione se ne accorga. Una voce di menù che scompare
    // non rompe niente — semplicemente, un giorno, nessuno trova più i clienti.
    //
    // Il Developer e non il Superadmin, per la prima volta in questa famiglia di
    // test: è l'unico che le vede tutte e quattro.
    $blocco = navDiPiattaforma($this->actingAs(utenteConRuolo('Developer'))
        ->get(route('piattaforma.index'))
        ->assertOk()
        ->getContent());

    expect($blocco)->toContain('Clienti')
        ->and($blocco)->toContain('Registro di audit')
        ->and($blocco)->toContain('Ruoli e permessi')
        ->and($blocco)->toContain('Errori')
        ->and($blocco)->toContain(route('piattaforma.errori'));
});

// ─── Gli elenchi e la catena, asseriti per struttura ─────────────────────────

it('keeps the hand-written role lists honest against the matrix', function () {
    // ⚠️ La rete che tiene onesti i due dataset statici, ed è **doppia**.
    //
    // La prima metà li allinea alla matrice: senza, un ruolo nuovo nascerebbe
    // senza `system.logs.view` e nessun dataset lo proverebbe — scoperto per
    // omissione, esattamente il buco che questa forma di test esiste per
    // chiudere.
    //
    // La seconda metà congela il fatto che regge l'intero file: che le due
    // partizioni **divergono**. `AccessoEditorRuoliTest` asserisce l'opposto
    // sulla propria (`roles.manage` coincide con `tenants.view_all`), e le due
    // asserzioni insieme dicono dove passa il confine. Il giorno in cui qualcuno
    // aprisse questa pagina al Superadmin, questa riga diventerebbe rossa prima
    // ancora dei 403 — cioè si accorgerebbe della decisione, non dell'effetto.
    $partizione = fn (string $permesso) => collect(Rbac::roleNames())
        ->partition(fn (string $r) => in_array($permesso, Rbac::permissionsForRole($r), true))
        ->map(fn ($insieme) => $insieme->values()->all());

    [$conErrori, $senzaErrori] = $partizione(Errori::PERMESSO);
    [$conPiattaforma] = $partizione(VistaPiattaforma::PERMESSO);

    expect($conErrori)->toEqualCanonicalizing(RUOLI_CON_ERRORI)
        // Il lettore è **uno solo**, e detto per nome: non «pochi», non «i ruoli
        // di piattaforma». Se un secondo ruolo guadagnasse il permesso, la
        // decisione che questo file custodisce sarebbe già cambiata.
        ->and($conErrori)->toEqualCanonicalizing(['Developer'])
        ->and($senzaErrori)->toEqualCanonicalizing(RUOLI_SENZA_ERRORI)
        // L'unione copre il catalogo: nessun ruolo cade fuori da entrambi i
        // dataset, che è il modo in cui un ruolo nuovo resterebbe non provato.
        ->and(array_merge(RUOLI_CON_ERRORI, RUOLI_SENZA_ERRORI))
        ->toEqualCanonicalizing(Rbac::roleNames())
        // La divergenza, asserita e non supposta.
        ->and($conErrori)->not->toEqualCanonicalizing($conPiattaforma)
        ->and(RUOLI_SENZA_ERRORI)->toContain('Superadmin')
        ->and(RUOLI_CON_PIATTAFORMA)->toContain('Superadmin');
});

it('keeps the tracker behind auth, lockout, two-factor and system.logs.view', function () {
    // Assert **strutturale** e non di comportamento, e qui la differenza pesa
    // più che nelle pagine gemelle: là scambiare il `can:` con quello della
    // cabina non cambiava nessun verdetto, perché le partizioni coincidevano —
    // si congelava la sola *ragione*. Qui la scambierebbe eccome (il Superadmin
    // entrerebbe), quindi questa riga e il negativo dedicato dicono la stessa
    // cosa da due lati: il negativo prova l'**effetto**, questa nomina il
    // **permesso** che lo produce. Chi mutasse il `can:` per «riparare» il 403
    // del Superadmin troverebbe rossi entrambi.
    $middleware = Route::getRoutes()->getByName('piattaforma.errori')->gatherMiddleware();

    expect($middleware)->toContain('can:'.Errori::PERMESSO)
        ->and($middleware)->toContain('auth')
        // DENTRO il gruppo protetto: il Developer è un utente tenant-bound con
        // un account proprio, e se quell'account fosse in lockout deve vedere
        // /bloccato come chiunque (ADR-018).
        ->and($middleware)->toContain('account.lockout')
        ->and($middleware)->toContain('two-factor.enforce')
        // ⚠️ E **non** sul permesso della cabina, in nessuna delle due forme —
        // né al posto di questo né in AND con esso. Al posto: aprirebbe la
        // pagina al Superadmin, cioè annullerebbe la decisione. In AND:
        // *sembrerebbe* più stretto e invece non aggiunge nulla (chi ha
        // `system.logs.view` è il Developer, che ha tutto), mentre dà un secondo
        // modo di rompere la pagina — un 403 sugli errori per una ragione che
        // non c'entra col loro confine.
        ->and($middleware)->not->toContain('can:'.VistaPiattaforma::PERMESSO)
        // L'**ordine**, che è l'unica cosa che ADR-013 dice e che `toContain`
        // scarta: la condizione più forte parla per prima.
        ->and(array_search('account.lockout', $middleware, true))
        ->toBeLessThan(array_search('two-factor.enforce', $middleware, true));
});

/**
 * Il blocco `<nav>` della sub-nav di piattaforma, estratto dall'HTML di pagina.
 *
 * ⚠️ Vive in fondo a **questo** file e non in `tests/Pest.php` di proposito: la
 * usano due test di questa sola suite, e la disciplina che ha portato là
 * `snapshotDa()` e `rigaDelPermesso()` è la reciproca — ci si sale quando una
 * funzione serve a **due suite**, non per simmetria. Se un domani
 * `AccessoEditorRuoliTest` volesse la stessa estrazione (oggi ne ha una copia
 * inline), è quello il momento di spostarla.
 */
function navDiPiattaforma(string $html): string
{
    preg_match('/<nav[^>]*aria-label="Sezioni della piattaforma".*?<\/nav>/s', $html, $blocco);

    // Non `?? ''`: un blocco assente e un blocco vuoto vanno distinti, o un
    // `not->toContain()` sarebbe verde proprio quando la nav è sparita.
    expect($blocco)->not->toBeEmpty();

    return $blocco[0];
}
