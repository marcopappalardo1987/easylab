<?php

/**
 * 🔬 Il **banco dei componenti** — `/design-system` (F1.5 del restyling).
 *
 * Tre domande, e sono tre domande diverse:
 *
 *  1. **In produzione la rotta non esiste** — 404, non 403. Una pagina di
 *     sviluppo raggiungibile in produzione è una superficie di attacco gratuita,
 *     e un 403 sarebbe peggio del silenzio: dichiarerebbe che quella pagina c'è.
 *  2. **Il banco si rende davvero.** È la ragione per cui la rotta è accesa
 *     anche in ambiente `testing`: senza, un errore di sintassi in un Blade del
 *     banco non lo vedrebbe nessun test, e un banco che va in 500 si scopre nel
 *     momento peggiore — quando lo si apre per verificare altro.
 *  3. **Non tocca un dato.** Il banco esiste perché la verifica visiva non
 *     debba passare dal database di sviluppo, che contiene dati di lavoro reali:
 *     se un giorno una fixture ci arrivasse da una query, quella promessa
 *     sarebbe già rotta e nessuno se ne accorgerebbe guardando la pagina.
 *
 * Più una quarta, che è quella che tiene in vita il banco: **ogni componente
 * dell'applicazione è montato**. Un banco che invecchia è peggio di nessun
 * banco, perché chi lo guarda crede di aver visto tutto.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * ⚠️ **L'ambiente si ripristina SEMPRE, anche se il test fallisce.**
 * `Tests\TestCase::setUp()` non riscrive `APP_ENV`, quindi un `production`
 * lasciato in `$_ENV` non resterebbe confinato a questo file: lo erediterebbe
 * **ogni test successivo dello stesso processo**, e la suite comincerebbe a
 * misurare un'applicazione in produzione senza dirlo. Pest esegue `afterEach`
 * anche dopo un fallimento, che è precisamente il caso da coprire.
 */
afterEach(function () {
    ambiente('testing');
});

/** Scrive `APP_ENV` in tutte e tre le sedi da cui Laravel può leggerlo. */
function ambiente(string $valore): void
{
    putenv("APP_ENV={$valore}");
    $_ENV['APP_ENV'] = $valore;
    $_SERVER['APP_ENV'] = $valore;
}

/** Il sorgente di tutti i file del banco, concatenato. */
function sorgenteDelBanco(): string
{
    return collect(File::allFiles(resource_path('views/banco')))
        ->map(fn ($file) => file_get_contents($file->getRealPath()))
        ->implode("\n");
}

it('mounts the bench and shows every state a component has', function () {
    $risposta = $this->get('/design-system');

    $risposta->assertOk();

    // ⚠️ Le ancore sono scelte per essere **la cosa che si romperebbe**, non
    // per essere facili da trovare: il glifo di ogni stato del semaforo (DS §4),
    // l'etichetta di una cella (DS §7), il velo della modale, i tre stati
    // dell'interruttore. Un `assertSee('Banco')` sarebbe verde anche su una
    // pagina con dentro il solo titolo.
    foreach ([
        '●', '◐', '■',                     // semaforo: la FORMA, non il colore
        '⚑',                                // semaforo forzato
        '⏳',                                // obsoleto
        'bg-overlay',                       // il velo della modale
        'data-etichetta="Matricola"',       // tabella-a-card (DS §7)
        'data-azioni',                      // la cella delle azioni
        'aria-pressed',                     // il selettore di tema
        'role="combobox"',                  // il combobox, coi suoi aria
        'La data di scadenza non può essere nel passato.', // lo stato d'errore dei campi
        'animate-pulse',                    // lo skeleton (DS §5.9)
        'data-banco-toast',                 // il toast (DS §5.9)
    ] as $ancora) {
        expect($risposta->getContent())->toContain($ancora);
    }
});

it('renders without a single database query', function () {
    // 🔴 La promessa del banco è «si apre, si guarda, non tocca un dato».
    // Questa è l'unica forma in cui quella promessa è verificabile: contare le
    // query, non fidarsi di come sono scritte le fixture.
    $query = [];
    DB::listen(function ($evento) use (&$query) {
        $query[] = $evento->sql;
    });

    $this->get('/design-system')->assertOk();

    expect($query)->toBe([],
        "Il banco ha interrogato il database.\n\n".
        "⚠️ Gli oggetti dei componenti che vogliono un modello (`semaforo-forzato`, `obsoleto`,\n".
        "`errori.cifre`) si costruiscono **in memoria**: `new Strumento` più un'assegnazione diretta.\n".
        "Una relazione si fornisce già risolta con `setRelation()` — valorizzare la chiave esterna\n".
        "manderebbe Eloquent a cercarla, ed è il modo più facile di rompere questa promessa senza\n".
        'accorgersene. Query trovate: '.implode(' · ', $query)
    );
});

it('mounts every component the application has, so the bench cannot age', function () {
    // Il nome del tag da montare: `ui/badge.blade.php` → `<x-ui.badge`.
    $componenti = collect(File::allFiles(resource_path('views/components')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
        ->map(fn ($file) => str($file->getRealPath())
            ->after(resource_path('views/components').'/')
            ->before('.blade.php')
            ->replace('/', '.')
            ->toString())
        // ⚠️ **Il guscio non è un pezzo del banco.** `layouts.app` è la pagina
        // dell'area autenticata — sidebar, top bar, drawer, banner di
        // impersonation: montarlo qui vorrebbe dire montare un utente, i suoi
        // permessi e il suo Ente, cioè far dipendere una pagina di sola
        // presentazione da una regola di autorizzazione. Si guarda sulle pagine
        // vere, che è dove vive. `guest-layout` invece è montato davvero: è il
        // layout di questa stessa pagina.
        ->reject(fn (string $nome) => $nome === 'layouts.app')
        ->sort()
        ->values();

    // 🔴 Senza questa riga il test sarebbe verde per insieme vuoto il giorno in
    // cui la cartella dei componenti cambiasse nome.
    expect($componenti)->not->toBeEmpty()
        ->and($componenti)->toContain('ui.semaforo', 'ui.button', 'brand-logo');

    $banco = sorgenteDelBanco();
    $mancanti = $componenti->reject(fn (string $nome) => str_contains($banco, '<x-'.$nome))->values();

    expect($mancanti->all())->toBe([],
        "Componenti che l'applicazione ha e che il banco non monta.\n\n".
        "⚠️ Un banco incompleto è peggio di nessun banco: chi lo guarda crede di aver visto tutta la\n".
        "libreria, e il componente che manca è proprio quello che nessuno ha guardato nei due temi.\n".
        "Rimedio: aggiungere un pezzo in `resources/views/banco/_*.blade.php`, con accanto i token che usa.\n\n".
        'Mancanti: '.$mancanti->implode(', ')
    );
});

it('does not exist in production: the route is never registered, so it is 404 and not 403', function () {
    // ⚠️ **L'ambiente si cambia e l'applicazione si ricrea**, perché le rotte si
    // registrano al boot: cambiare `APP_ENV` su un'applicazione già avviata non
    // toglierebbe una rotta già in tabella, e il test direbbe il falso in
    // verde. `refreshApplication()` rilegge `routes/web.php` da capo.
    //
    // ⚠️ Il database **non** si tocca: la nuova applicazione apre una
    // connessione nuova, e questo test non fa una sola query. La transazione di
    // `RefreshDatabase` resta sulla connessione di prima, che è ancora viva.
    ambiente('production');
    $this->refreshApplication();

    expect(app()->environment())->toBe('production')
        ->and(Route::has('banco'))->toBeFalse();

    $this->get('/design-system')->assertNotFound();
});

it('exists in local, which is the only environment where anyone needs it', function () {
    ambiente('local');
    $this->refreshApplication();

    expect(app()->environment())->toBe('local')
        ->and(Route::has('banco'))->toBeTrue();
});

it('is an allow-list and not a deny-list, so a new environment is out by default', function () {
    // 🔴 La differenza fra `environment(['local', 'testing'])` e
    // `! environment('production')` non si vede finché non nasce un terzo
    // ambiente. `staging` **esiste** in questo progetto (`.env.staging`) ed è
    // raggiungibile da internet: con una lista di esclusi il banco ci sarebbe
    // finito sopra senza che nessuno prendesse la decisione.
    ambiente('staging');
    $this->refreshApplication();

    expect(Route::has('banco'))->toBeFalse();

    $this->get('/design-system')->assertNotFound();
});
