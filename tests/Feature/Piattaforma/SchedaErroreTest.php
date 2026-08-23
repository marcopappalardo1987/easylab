<?php

use App\Livewire\Piattaforma\Errori;
use App\Livewire\Piattaforma\SchedaErrore;
use App\Models\Errore;
use App\Models\OccorrenzaErrore;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * 🔴 La scheda di una issue dell'error tracker interno (S6, blocco 5).
 *
 * È il dettaglio di `ElencoErroriTest`, e **una rotta a sé**: elenco e
 * occorrenze sono entrambi paginati e `WithPagination` ha un `page` solo, quindi
 * nello stesso componente le due paginazioni collidono. La conseguenza per i
 * test è che questa pagina ha un **proprio** gate da provare — un URL digitato a
 * mano non passa dall'elenco.
 *
 * ⚠️ **Qui si prova solo ciò che la scheda MOSTRA.** I tre gesti
 * (risolvi/ignora/riapri) vivono su questo stesso componente ma hanno la loro
 * suite, `AzioniErroriTest`: là il rifiuto si asserisce **sul dato** — perché le
 * azioni non fanno `skipRender()` e un `assertForbidden()` sarebbe verde anche a
 * scrittura avvenuta — e là stanno la riga di audit e il confine di privacy sul
 * messaggio. Tenerli separati è la stessa ragione per cui `AccessoErroriTest`
 * non è dentro `ElencoErroriTest`: i due file si rompono per motivi diversi.
 *
 * Il dettaglio della singola occorrenza resta senza azione: si apre con un
 * `<details>` nativo, quindi i test qui non chiamano nessun metodo.
 *
 * ⚠️ Il tracker è **acceso durante la suite**: si cerca per id, mai contando
 * `Errore::count()`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->developer = utenteConRuolo('Developer');
});

/** Un'occorrenza conservata, coi campi obbligatori già a posto. */
function occorrenzaDi(Errore $errore, array $attributi = []): OccorrenzaErrore
{
    return OccorrenzaErrore::create(array_merge([
        'errore_id' => $errore->id,
        'messaggio' => 'Qualcosa non ha funzionato',
        'stack_trace' => "RuntimeException: Qualcosa non ha funzionato\norigine app/Support/Prova.php:42\n#0 app/Http/Controllers/Prova.php:11 App\\Prova::fai()",
        'percorso' => 'strumenti/{strumento}',
        'metodo' => 'GET',
        'codice_http' => 500,
        'contesto' => 'http',
        'avvenuta_at' => now(),
    ], $attributi));
}

/**
 * Il `<details>` di un'occorrenza, estratto per la sua `wire:key`.
 *
 * ⚠️ Si estrae il **blocco**: un `toContain` sulla pagina intera direbbe solo
 * che la stringa compare *da qualche parte*, e su una scheda che ripete
 * messaggio, percorso e utente su ogni occorrenza è precisamente il modo in cui
 * un'asserzione smette di poter fallire.
 */
function bloccoOccorrenza(string $html, int $id): string
{
    preg_match('/<details wire:key="occorrenza-'.$id.'".*?<\/details>/s', $html, $blocco);

    expect($blocco)->not->toBeEmpty();

    return $blocco[0];
}

/** L'HTML della scheda montata come Developer. */
function schedaErrore(Errore $errore, array $query = []): string
{
    return Livewire::withQueryParams($query)
        ->actingAs(test()->developer)
        ->test(SchedaErrore::class, ['errore' => $errore])
        ->html();
}

// ─── Il gate, che qui è doppio e va provato due volte ────────────────────────

it('gates the detail render too, not only its route', function (string $ruolo) {
    // 🔴 `Livewire::test()` **disabilita i middleware**: se il gate vivesse solo
    // sulla rotta, questo passerebbe. A rispondere è il `Gate::authorize()` in
    // testa a `render()`, che sulla scheda va scritto **di nuovo** — non si
    // eredita nulla dall'elenco, e il componente si monta anche da solo.
    //
    // ⚠️ Questo test e quello di rotta qui sotto sono una **coppia**, ed è
    // deliberato: togliendo il `Gate::authorize()` dal componente il test di
    // rotta resta verde (risponde il `can:`) e questo diventa rosso. È l'unico
    // modo di sapere quale delle due guardie sta effettivamente rispondendo, e
    // senza il secondo si crederebbe protetto un montaggio che non lo è.
    //
    // Il Superadmin per primo: ha ogni altro permesso di piattaforma e non
    // questo, quindi è il soggetto che rende la prova non banale — un utente
    // senza ruoli sarebbe respinto da qualunque cosa e non nominerebbe nessuno.
    $errore = issueErrore();

    Livewire::actingAs(utenteConRuolo($ruolo))
        ->test(SchedaErrore::class, ['errore' => $errore])
        ->assertForbidden();
})->with(['Superadmin', 'Admin']);

it('refuses the route to the Superadmin, who holds every other platform permission', function () {
    $errore = issueErrore();

    $this->actingAs(utenteConRuolo('Superadmin'))
        ->get(route('piattaforma.errori.mostra', $errore))
        ->assertForbidden();
});

it('sends a guest to the login', function () {
    $this->get(route('piattaforma.errori.mostra', issueErrore()))->assertRedirect(route('login'));
});

it('keeps the detail behind auth, lockout, two-factor and system.logs.view', function () {
    // Assert **strutturale**, come per l'elenco: il negativo prova l'effetto,
    // questa nomina il permesso che lo produce. La costante è quella di
    // `Errori`, e non una seconda copia della stringa: elenco e scheda mostrano
    // lo stesso dato, e gatarle diversamente lascerebbe aperta la scheda a chi
    // l'elenco rifiuta — per la strada che nessuno prova, l'URL diretto.
    $middleware = Route::getRoutes()->getByName('piattaforma.errori.mostra')->gatherMiddleware();

    expect($middleware)->toContain('can:'.Errori::PERMESSO)
        ->and($middleware)->toContain('auth')
        ->and($middleware)->toContain('account.lockout')
        ->and($middleware)->toContain('two-factor.enforce')
        ->and(array_search('account.lockout', $middleware, true))
        ->toBeLessThan(array_search('two-factor.enforce', $middleware, true));
});

it('lets the Developer in, and marks the fourth tab as the current one', function () {
    $errore = issueErrore(['classe' => 'App\\Guasti\\Sfortuna', 'file' => 'app/Support/Prova.php', 'riga' => 42]);

    $this->actingAs($this->developer)
        ->get(route('piattaforma.errori.mostra', $errore))
        ->assertOk()
        ->assertSee('Sfortuna')
        ->assertSee('app/Support/Prova.php:42')
        ->assertSee('Sfortuna');

    // ⚠️ **Dentro il blocco della sub-nav, e contando.** La sidebar porta un
    // proprio `aria-current` sempre acceso su `piattaforma.*`, quindi cercarlo
    // nella pagina intera lo trovava comunque: verificato, togliendo il jolly
    // sulle sotto-rotte la suite restava tutta verde mentre nella sub-nav gli
    // elementi attivi diventavano **zero**.
    $nav = navDiPiattaforma($this->actingAs($this->developer)
        ->get(route('piattaforma.errori.mostra', $errore))->getContent());

    expect(substr_count($nav, 'aria-current="page"'))->toBe(1)
        ->and($nav)->toContain(route('piattaforma.errori'));
});

// ─── Una issue può sparire mentre qualcuno ha il link aperto ─────────────────

it('shows a 404 for an issue that no longer exists', function () {
    // La potatura del blocco 7 cancella le issue chiuse dopo 90 giorni e le
    // aperte dopo 180: il link resta nella cronologia di chi lo ha aperto, e
    // deve dare 404 invece di una scheda vuota su una riga che non c'è più. È il
    // route-model binding a rispondere, ed è la ragione per cui la scheda prende
    // un model e non un id.
    $errore = issueErrore();
    $indirizzo = route('piattaforma.errori.mostra', $errore);

    $errore->delete();

    $this->actingAs($this->developer)->get($indirizzo)->assertNotFound();
});

// ─── Le due cifre, di nuovo e qui più che altrove ────────────────────────────

it('says the two figures in the same sentence', function () {
    // 🔴 Qui pesa più che nell'elenco: è **questa** la pagina in cui si scorrono
    // le occorrenze conservate, e vederne dieci sotto un contatore che dice
    // 10.412, senza la seconda cifra a spiegarlo, si legge come una perdita di
    // dati — cioè manda a cercare un guasto del tracker che non c'è.
    //
    // ⚠️ Sulla **frase composta**: la pagina stampa `20` anche nel sottotitolo
    // delle occorrenze («al più 20 per errore») e `10.412` non compare altrove,
    // quindi due asserzioni separate sarebbero verdi anche a cifra tolta.
    $errore = issueErrore(['occorrenze' => 10412, 'contesti' => 20]);

    expect(schedaErrore($errore))->toContain('occorrenze: 10.412 · prove raccolte dall\'ultima riapertura: 20');
});

// ─── Il messaggio è un campione ──────────────────────────────────────────────

it('labels the message as a sample, not as «the» message of the issue', function () {
    // 🔴 L'impronta è `classe + due frame`: il messaggio ne è **fuori**, perché
    // è interpolato («Utente 42 non trovato») e dentro darebbe una issue per
    // occorrenza. La conseguenza è che due occorrenze della stessa issue possono
    // avere messaggi **diversi**, e chiamare quello in cima «il messaggio
    // dell'errore» manda a cercare una costante che non esiste.
    //
    // ⚠️ E si dice **quale** campione. Verificato sul codice del blocco 3:
    // `errori.messaggio` si scrive una volta sola, dentro l'`Errore::create()`
    // di `CatturaErrori::registra()`, e `incrementa()` non lo tocca mai — è
    // quindi quello della **prima** volta, non «l'ultimo visto». Etichettarlo
    // come l'ultimo sarebbe una seconda affermazione falsa al posto della prima.
    $errore = issueErrore(['messaggio' => 'Utente 42 non trovato']);
    occorrenzaDi($errore, ['messaggio' => 'Utente 87 non trovato']);

    $html = schedaErrore($errore);

    // ⚠️ **Sulla frase intera, non su «prima volta»**: quelle due parole
    // compaiono anche nella riga dei timestamp poco sopra, quindi l'asserzione
    // breve era soddisfatta comunque — verificato scrivendo in pagina l'esatta
    // bugia che questo test esiste per impedire («quello dell'ultima volta
    // vista») e trovando la suite tutta verde.
    expect($html)->toContain('campione, non «il» messaggio')
        ->toContain('Questo è quello della <strong>prima volta</strong>')
        ->toContain('Utente 42 non trovato')
        // E l'occorrenza porta **il proprio**, che è il modo in cui si vede che
        // i due divergono davvero.
        ->and(bloccoOccorrenza($html, OccorrenzaErrore::sole()->id))
        ->toContain('Utente 87 non trovato');
});

// ─── Le occorrenze ───────────────────────────────────────────────────────────

it('lists the occurrences from the most recent, with their context', function () {
    $errore = issueErrore();
    $vecchia = occorrenzaDi($errore, ['avvenuta_at' => now()->subHour(), 'messaggio' => 'La prima volta']);
    $recente = occorrenzaDi($errore, [
        'avvenuta_at' => now(),
        'messaggio' => 'L\'ultima volta',
        'ip' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (prova)',
        'input' => ['ente' => 'Gruppo Rossi', 'annidato' => ['chiave' => 'valore']],
    ]);

    $html = schedaErrore($errore);

    $dove = fn (OccorrenzaErrore $o): int => (int) strpos($html, 'wire:key="occorrenza-'.$o->id.'"');

    expect($dove($recente))->toBeLessThan($dove($vecchia));

    // Il contesto della singola occorrenza, dentro il **suo** blocco.
    expect(bloccoOccorrenza($html, $recente->id))
        ->toContain('203.0.113.7')
        ->toContain('Mozilla/5.0 (prova)')
        ->toContain('GET')
        ->toContain('strumenti/{strumento}')
        ->toContain('HTTP 500')
        // Un valore annidato si rende come JSON: `{{ }}` nudo su un array
        // andrebbe in «Array to string conversion», cioè romperebbe la pagina
        // proprio sul dato che serve a capire l'errore.
        //
        // ⚠️ Si asserisce sulla forma **escapata**, e non è una concessione al
        // formato: l'input di richiesta è la stringa più ostile che questa
        // pagina renda, e vederla uscire con le virgolette già trasformate in
        // entità è metà della prova. Un `toContain` sul JSON nudo sarebbe
        // rosso oggi e — peggio — verde il giorno in cui qualcuno mettesse un
        // `{!! !!}` per «leggerlo meglio».
        ->toContain(htmlspecialchars('{"chiave":"valore"}', ENT_QUOTES));
});

it('shows the stack trace of an occurrence', function () {
    $errore = issueErrore();
    $occorrenza = occorrenzaDi($errore);

    // Lo stack trace è salvato **senza argomenti di funzione** (blocco 4): qui
    // si prova soltanto che arriva a schermo, perché è la sola cosa che questa
    // pagina può sbagliare — la ripulitura ha i propri test in `ContestoErroriTest`.
    expect(bloccoOccorrenza(schedaErrore($errore), $occorrenza->id))
        ->toContain('origine app/Support/Prova.php:42')
        ->toContain('#0 app/Http/Controllers/Prova.php:11');
});

it('names who was there, and does not fall over an impersonator who no longer exists', function () {
    // 🔴 `impersonato_da` è una colonna **nuda**, senza vincolo — la scelta è
    // del percorso caldo dentro il gestore delle eccezioni — e `users` **non ha
    // soft delete**: verificato nel blocco 1, nessun `deleted_at` e nessun
    // trait. Quell'id può quindi puntare a un utente cancellato davvero, e la
    // pagina deve dirlo invece di cadere o di tacere.
    $errore = issueErrore();
    $vittima = User::factory()->create(['name' => 'Anna Conti']);
    $impersonatore = User::factory()->create(['name' => 'Luca Bianchi']);

    $risolve = occorrenzaDi($errore, [
        'avvenuta_at' => now(),
        'user_id' => $vittima->id,
        'impersonato_da' => $impersonatore->id,
    ]);
    $orfana = occorrenzaDi($errore, [
        'avvenuta_at' => now()->subMinute(),
        'user_id' => $vittima->id,
        // Un id che non esiste: è ciò che resta dopo la cancellazione
        // dell'utente che stava impersonando.
        'impersonato_da' => 999999,
    ]);

    $html = schedaErrore($errore);

    expect(bloccoOccorrenza($html, $risolve->id))
        ->toContain('Anna Conti')
        ->toContain('Luca Bianchi')
        ->not->toContain('utente non più presente');

    expect(bloccoOccorrenza($html, $orfana->id))
        ->toContain('Anna Conti')
        // Il numero, che è meno di un nome ma molto più di niente: dice che
        // dietro quella sessione c'era qualcun altro.
        ->toContain('#999999')
        ->toContain('utente non più presente');
});

it('paginates the occurrences on their own page number', function () {
    // Le occorrenze hanno una paginazione **propria**, ed è la ragione per cui
    // questa è una seconda rotta: dentro l'elenco condividerebbero il `page` con
    // le issue e «pagina 2» sposterebbe entrambe.
    $errore = issueErrore(['contesti' => 12]);

    for ($i = 0; $i < 12; $i++) {
        occorrenzaDi($errore, ['avvenuta_at' => now()->subMinutes($i)]);
    }

    $prima = schedaErrore($errore);
    $seconda = schedaErrore($errore, ['page' => 2]);

    expect(substr_count($prima, 'wire:key="occorrenza-'))->toBe(10)
        ->and($prima)->toContain('1–10 di 12 prove conservate in tutto')
        ->and(substr_count($seconda, 'wire:key="occorrenza-'))->toBe(2)
        ->and($seconda)->toContain('11–12 di 12 prove conservate in tutto');
});

it('tells a missing context from a missing occurrence', function () {
    // ⚠️ **Non «nessuna occorrenza»**: le occorrenze ci sono, le conta il
    // contatore. A mancare è la **prova**, e i due fatti si confondono solo se
    // li si dice con la stessa parola.
    $errore = issueErrore(['occorrenze' => 7, 'contesti' => 0]);

    expect(schedaErrore($errore))
        ->toContain('Nessun contesto conservato per questo errore.')
        ->toContain('occorrenze: 7 · prove raccolte dall\'ultima riapertura: 0');
});

// ─── Il costo ────────────────────────────────────────────────────────────────

it('resolves every actor of the page in one query', function () {
    // Chi c'era e chi agiva davvero si risolvono **insieme**, in una `whereIn`
    // sola: una query per occorrenza sarebbe un N+1 su una pagina che ne mostra
    // dieci. Ogni occorrenza ha un utente **diverso**, o la prova non potrebbe
    // fallire — la cicatrice è la stessa dell'elenco.
    $errore = issueErrore();

    for ($i = 0; $i < 10; $i++) {
        occorrenzaDi($errore, [
            'avvenuta_at' => now()->subMinutes($i),
            'user_id' => User::factory()->create()->id,
            'impersonato_da' => User::factory()->create()->id,
        ]);
    }

    // Scaldare la cache dei permessi prima di misurare.
    schedaErrore($errore);

    DB::flushQueryLog();
    DB::enableQueryLog();
    schedaErrore($errore);
    $n = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "occorrenze_errore"')
            || str_contains($q['query'], 'from "users"'))
        ->count();
    DB::disableQueryLog();

    // Il conteggio della paginazione, la pagina, e gli attori: tre.
    expect($n)->toBe(3);
});

it('never claims nobody was authenticated when someone was impersonating', function () {
    // 🔴 `users` **non ha soft delete** (verificato nel blocco 1): cancellando un
    // utente, `user_id` va a NULL per la chiave esterna mentre `impersonato_da`
    // — colonna nuda, scelta apposta per non pagare una FK sul percorso caldo —
    // **resta**. La pagina finiva così a dire «nessun utente autenticato»
    // accanto a un «per conto di» valorizzato: un'affermazione falsa, cioè lo
    // stesso difetto che il ramo accanto evita rifiutando la parola «Sistema».
    $impersonatore = User::factory()->create(['name' => 'Developer EasyLab']);
    $vittima = User::factory()->create();

    $errore = issueErrore();
    $occorrenza = occorrenzaDi($errore, [
        'user_id' => $vittima->id,
        'impersonato_da' => $impersonatore->id,
    ]);

    $vittima->delete();

    $blocco = bloccoOccorrenza(schedaErrore($errore), $occorrenza->id);

    expect($blocco)->not->toContain('Nessun utente autenticato')
        ->and($blocco)->toContain('non è più presente')
        // E chi stava dietro si legge ancora, col nome.
        ->and($blocco)->toContain('Developer EasyLab');
});
