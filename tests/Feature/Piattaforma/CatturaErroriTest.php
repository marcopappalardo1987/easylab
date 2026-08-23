<?php

use App\Models\Errore;
use App\Support\Errori\CatturaErrori;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La cattura dell'error tracker interno (S6 — 🔗 `docs/Architettura/Error
 * Tracker Interno (piano).md`): `App\Support\Errori\CatturaErrori` e il suo
 * aggancio in `bootstrap/app.php`.
 *
 * Questo file prova **tre cose diverse**, e vale la pena distinguerle perché si
 * rompono per ragioni diverse:
 *
 * 1. che il callback sia **davvero invocato** — e da cosa dipenda;
 * 2. che non faccia **danni** quando è lui a rompersi (la risposta resta quella
 *    di prima, il logger riceve l'errore vero, non c'è ricorsione);
 * 3. che l'**impronta** raggruppi ciò che è lo stesso bug e separi ciò che non
 *    lo è, anche dopo un deploy.
 *
 * ⚠️ **Il tracker resta acceso per tutta la suite**, e questi test creano
 * eccezioni di proposito: dove conta si asserisce **sull'impronta o sulla
 * classe**, non su un `Errore::count()` che un giorno potrebbe contare anche le
 * righe di qualcun altro.
 */

/**
 * Un helper con **un solo** `throw`, chiamato da posti diversi: è la forma su
 * cui l'impronta a un frame sola sbaglierebbe, e sta fuori dai test perché il
 * punto è proprio che la riga del `throw` sia **la stessa** per tutti i
 * chiamanti.
 */
function lancioDentroUnHelper(string $messaggio): never
{
    throw new RuntimeException($messaggio);
}

/**
 * Esegue `$azione` con `error_log()` dirottato su un file, e ne restituisce le
 * righe.
 *
 * ⚠️ Serve perché il catch di `CatturaErrori` scrive **solo** lì — non con
 * `Log::`, che dentro il gestore delle eccezioni sarebbe un altro giro dello
 * stesso guasto. Senza il dirottamento quelle righe finirebbero su stderr in
 * mezzo all'output della suite, e soprattutto non sarebbero contabili: contarle
 * è l'unico modo di vedere che il catch è entrato **una volta sola**.
 *
 * ⚠️ **Si tengono solo le righe che portano il nostro marcatore, e la ragione è
 * una divergenza SQLite/Postgres pagata proprio qui**: il messaggio di una
 * `QueryException` di Postgres è su **tre righe** (l'errore, la `LINE 1:` con
 * l'SQL, il cursore `^`), mentre su SQLite sta su una. Contando le righe del
 * file, lo stesso identico comportamento valeva 1 in locale e 3 su Postgres —
 * cioè il test avrebbe detto «ricorsione» dove non ce n'era. Si contano gli
 * **ingressi nel catch**, che è ciò che si voleva contare.
 *
 * @return list<string>
 */
function conErrorLog(callable $azione): array
{
    $file = tempnam(sys_get_temp_dir(), 'easylab-error-log-');
    $precedente = ini_get('error_log');

    ini_set('error_log', $file);

    try {
        $azione();
    } finally {
        ini_set('error_log', $precedente === false ? '' : $precedente);
    }

    $righe = array_values(array_filter(
        explode("\n", (string) file_get_contents($file)),
        fn (string $riga): bool => str_contains($riga, '[easylab]'),
    ));

    @unlink($file);

    return $righe;
}

/**
 * Fa nascere **sempre la stessa** eccezione dallo **stesso** punto: la riga del
 * `throw` è quella dell'helper, e il chiamante è questa riga qui sotto. Due
 * invocazioni condividono quindi l'impronta, che è ciò che serve per provare il
 * raggruppamento, la riapertura e il costo.
 */
function scatenaSempreLoStessoErrore(string $messaggio = 'sempre lo stesso'): void
{
    try {
        lancioDentroUnHelper($messaggio);
    } catch (Throwable $e) {
        report($e);
    }
}

beforeEach(function () {
    // Il logger di default è spiato in tutto il file: i test che lo riguardano
    // hanno bisogno della spia, e gli altri hanno bisogno di non riempire
    // `laravel.log` con una decina di eccezioni finte a ogni giro di suite.
    Log::spy();
});

it('is actually invoked, which depends on the type hint', function () {
    // 🔴 **La mutazione che conta qui è togliere `Throwable` dalla closure di
    // `bootstrap/app.php`, e il suo esito non è «una riga in meno».**
    // `ReportableHandler::handles()` legge il tipo del primo parametro con
    // `firstClosureParameterTypes()`, che **lancia** quando non ne trova: senza
    // il type hint ogni eccezione riportabile crasha **prima** del logger, cioè
    // con zero righe in `laravel.log`. Il caso davvero silenzioso è l'opposto —
    // un hint più stretto (`RuntimeException`), che lascerebbe passare tutto il
    // resto senza dire niente.
    report(new RuntimeException('la prima volta'));

    $errore = Errore::sole();

    expect($errore->classe)->toBe(RuntimeException::class)
        ->and($errore->messaggio)->toBe('la prima volta')
        ->and($errore->stato)->toBe('aperto')
        ->and($errore->occorrenze)->toBe(1)
        // sha1 esadecimale: 40 caratteri, che è la larghezza dichiarata dalla
        // colonna. Se un domani l'algoritmo cambiasse, su Postgres un varchar
        // troppo corto è un errore e non un troncamento — e l'errore verrebbe
        // inghiottito dal catch del tracker, lasciando zero righe e nessun
        // sintomo. Questa asserzione è l'unico posto in cui le due decisioni
        // (algoritmo e colonna) si guardano.
        ->and($errore->impronta)->toHaveLength(40)
        // ⚠️ **Percorso relativo alla radice del progetto**, e non è cosmesi: su
        // Laravel Cloud ogni release vive in una directory diversa, quindi con
        // il percorso assoluto ogni deploy azzererebbe il raggruppamento.
        ->and($errore->file)->toBe('tests/Feature/Piattaforma/CatturaErroriTest.php')
        ->and($errore->file)->not->toContain(base_path());
});

it('groups two occurrences of the same throw into one issue', function () {
    // Stesso `throw`, stesso chiamante, due volte: una riga sola e il contatore
    // a due. È il caso felice del raggruppamento, e da solo non basterebbe —
    // lo completa il test qui sotto, che è quello che può cadere.
    foreach ([1, 2] as $volta) {
        scatenaSempreLoStessoErrore("occorrenza {$volta}");
    }

    $errore = Errore::sole();

    expect($errore->occorrenze)->toBe(2)
        // Il messaggio resta quello della **prima** volta: è un campione, non un
        // dato aggiornato, e la pagina lo etichetterà come tale.
        ->and($errore->messaggio)->toBe('occorrenza 1');
});

it('keeps two different throws that pass through the same helper as two issues', function () {
    // 🔴 **Il difetto dell'impronta a un frame solo, scritto come test.**
    // `lancioDentroUnHelper()` ha **una** riga di `throw`: con la sola posizione
    // d'origine, cento chiamanti diversi diventerebbero **una** issue per cento
    // cause — cioè il raggruppamento muto che questo tracker esiste per
    // evitare. E da lì «ignorato» su quella riga zittirebbe bug non ancora
    // scritti.
    //
    // Mutazione che lo rende rosso: in `identifica()`, `$chiamante =
    // $applicative[1] ?? ''` → `$chiamante = ''`.
    try {
        lancioDentroUnHelper('prima causa');
    } catch (Throwable $e) {
        report($e);
    }

    try {
        lancioDentroUnHelper('seconda causa');
    } catch (Throwable $e) {
        report($e);
    }

    // Due issue…
    expect(Errore::count())->toBe(2)
        ->and(Errore::pluck('impronta')->unique())->toHaveCount(2);

    // …e la prova che è **lo stesso punto d'origine** a distinguerle solo grazie
    // al chiamante: `file` e `riga` sono identici sulle due righe. Senza questa
    // asserzione il test sarebbe verde anche se le due eccezioni nascessero da
    // posti diversi, cioè non proverebbe niente sull'helper.
    expect(Errore::pluck('file')->unique())->toHaveCount(1)
        ->and(Errore::pluck('riga')->unique())->toHaveCount(1);
});

it('keeps the fingerprint stable across a change of absolute path', function () {
    // 🔴 **La prova che un deploy non azzera le issue.** Non si può fabbricare
    // un'eccezione che dica di venire da un'altra directory — `getFile()`,
    // `getLine()` e `getTrace()` sono `final` su `Exception`, e `Throwable` non
    // è implementabile — quindi si sposta la **radice del progetto** sotto i
    // piedi del calcolo, che è esattamente ciò che Laravel Cloud fa a ogni
    // release.
    //
    // È anche la ragione per cui `identifica()` è pubblica e prende le posizioni
    // già estratte: senza quella firma questo test non esisterebbe.
    $radiceVera = base_path();

    $improntaSotto = function (string $radice): array {
        app()->setBasePath($radice);

        return CatturaErrori::identifica(RuntimeException::class, [
            $radice.'/app/Support/Errori/CatturaErrori.php:42',
            $radice.'/app/Livewire/Piattaforma/Errori.php:88',
        ]);
    };

    try {
        [$improntaIeri, $fileIeri, $rigaIeri] = $improntaSotto('/var/www/releases/2026_08_22');
        [$improntaOggi, $fileOggi] = $improntaSotto('/var/www/releases/2026_08_23');
    } finally {
        app()->setBasePath($radiceVera);
    }

    expect($improntaOggi)->toBe($improntaIeri)
        ->and($fileIeri)->toBe('app/Support/Errori/CatturaErrori.php')
        ->and($rigaIeri)->toBe(42)
        ->and($fileOggi)->toBe($fileIeri);
});

it('falls back to the birthplace when no frame belongs to the project', function () {
    // Un'eccezione nata e morta dentro il framework: nessuna posizione è codice
    // nostro, e senza fallback l'impronta sarebbe la stessa per tutte — cioè
    // ogni guasto interno confluirebbe in un'unica issue.
    //
    // Si asserisce anche che due punti d'origine diversi restino **due**
    // impronte: è la metà che può cadere.
    $radiceVera = base_path();

    try {
        app()->setBasePath('/var/www/releases/2026_08_23');

        [$impronta, $file, $riga] = CatturaErrori::identifica(RuntimeException::class, [
            '/var/www/releases/2026_08_23/vendor/laravel/framework/src/Illuminate/Database/Connection.php:825',
            '/var/www/releases/2026_08_23/vendor/laravel/framework/src/Illuminate/Database/Connection.php:571',
        ]);

        [$altra] = CatturaErrori::identifica(RuntimeException::class, [
            '/var/www/releases/2026_08_23/vendor/laravel/framework/src/Illuminate/Queue/Worker.php:120',
        ]);
    } finally {
        app()->setBasePath($radiceVera);
    }

    expect($file)->toBe('vendor/laravel/framework/src/Illuminate/Database/Connection.php')
        ->and($riga)->toBe(825)
        ->and($altra)->not->toBe($impronta);
});

it("inherits the framework's ignore list instead of copying it", function () {
    // 🔴 **Asserito sul dato, non su un elenco.** Un test che confronta la nostra
    // lista con quella di Laravel proverebbe soltanto che due array si
    // somigliano; qui si riporta un'eccezione **per ciascuna** classe e si
    // guarda cosa è finito a database. I callback di `report()` girano **dopo**
    // `shouldntReport()`, quindi la lista si eredita: ridichiararla la farebbe
    // divergere in silenzio alla prima versione di Laravel che la allunga.
    report(ValidationException::withMessages(['nome' => 'obbligatorio']));
    report(new AuthenticationException);
    report(new AuthorizationException);
    report(new ModelNotFoundException);
    report(new TokenMismatchException);
    report(new NotFoundHttpException);

    // ⚠️ **La sorpresa che va saputa**: `NotFoundHttpException` non è elencata
    // letteralmente in `$internalDontReport` — è coperta da `HttpException`, che
    // esclude **tutta la famiglia**. Quindi un `abort(500)` deliberato non
    // finisce nel tracker. Non è un difetto di questa riga, è la conseguenza di
    // ereditare la lista invece di copiarla, e chi un giorno vorrà i 500
    // deliberati dovrà chiederli a parte.
    report(new HttpException(500, 'abort(500) deliberato'));

    // Il controllo che rende il test falsificabile: senza questa eccezione
    // «zero righe» si leggerebbe uguale sia con la lista ereditata sia con il
    // callback mai invocato.
    report(new RuntimeException('questa invece si traccia'));

    expect(Errore::pluck('classe')->all())->toBe([RuntimeException::class]);
});

it('never silences the default logger', function () {
    // 🔴 **`Handler::reportThrowable()` interrompe la catena su `=== false`**, e
    // da lì il logger di default non riceve più nulla: il tracker interno
    // *sostituirebbe* `laravel.log` invece di affiancarlo — e su un database
    // irraggiungibile non resterebbe niente da nessuna parte.
    //
    // ⚠️ **Il test ovvio non coglierebbe la regressione**: contare le righe in
    // `errori` resta verde anche con `return false`, perché la riga viene
    // scritta comunque — è il *logger* a sparire, non il tracker. Serve la spia.
    //
    // Mutazione: nella closure di `bootstrap/app.php`, `CatturaErrori::cattura($e);`
    // → `CatturaErrori::cattura($e); return false;` (col tipo di ritorno tolto).
    report(new RuntimeException('deve arrivare anche al logger'));

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $messaggio) => $messaggio === 'deve arrivare anche al logger');

    // E il tracker ha comunque scritto: le due cose convivono, non si escludono.
    expect(Errore::count())->toBe(1);
});

it('keeps the response and the log intact when the tracker itself explodes', function () {
    // 🔴 **La prova centrale del blocco, ed è la risposta a «e se il database non
    // risponde?».** Il tracker gira dentro il gestore delle eccezioni, cioè
    // quando l'applicazione sta già andando male: se lanciasse, sostituirebbe
    // l'errore vero col proprio e la pagina di errore direbbe «tabella errori
    // non trovata» al posto della causa — mentre `laravel.log`, l'unica traccia
    // rimasta, non riceverebbe l'eccezione originale.
    //
    // Mutazione: togliere il `try/catch` da `cattura()` → rosso.
    Route::get('/prova/esplosione', fn () => throw new RuntimeException('la causa vera'));

    // Il figlio prima del padre: la FK è `cascadeOnDelete`, e su Postgres una
    // tabella con dipendenti non si lascia cancellare.
    Schema::drop('occorrenze_errore');
    Schema::drop('errori');

    $righe = conErrorLog(function () {
        $risposta = $this->get('/prova/esplosione');

        // La risposta è quella di sempre: un 500 dell'applicazione, non un
        // secondo guasto.
        $risposta->assertStatus(500);
    });

    // 🔴 **E il log ha ricevuto l'eccezione VERA**, non quella del tracker:
    // `once()` è metà dell'asserzione — se ne fossero arrivate due, la seconda
    // sarebbe il guasto del tracker travestito da errore dell'applicazione.
    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $messaggio) => $messaggio === 'la causa vera');

    // Il guasto del tracker non è sparito: è finito dove non può fare danni,
    // e dice **su cosa** si è rotto.
    expect($righe)->toHaveCount(1)
        ->and($righe[0])->toContain('error tracker')
        ->and($righe[0])->toContain(RuntimeException::class);

    // 🔴 **E il database è ancora utilizzabile dopo il fallimento del tracker.**
    //
    // ⚠️ Su SQLite questa riga è verde comunque, quindi sembra decorativa e non
    // lo è: su **Postgres** una query fallita mette l'INTERA transazione in
    // stato aborted (25P02), e ogni istruzione successiva risponde «current
    // transaction is aborted». Senza il `SAVEPOINT` che `CatturaErrori::registra()`
    // apre attorno alla propria scrittura, un tracker che non riesce a scrivere
    // lascerebbe morta anche la transazione dell'applicazione che l'ha chiamato:
    // un log mancato diventerebbe un guasto vero, cioè esattamente il contrario
    // di ciò per cui esiste il `try/catch`. La mutazione che toglie quella
    // transazione **cade solo su Postgres** — è la stessa forma già pagata su
    // `Ricambio::collegaOCrea()`.
    expect(DB::table('users')->count())->toBe(0);
});

it('does not recurse when the write itself throws', function () {
    // ⚠️ **Il sintomo di una regressione qui non è un rosso: è la suite che
    // muore per memoria.** `rescue()` — la forma idiomatica, e quella che verrà
    // in mente a chi rilegge — chiama `report()` sul proprio guasto, e
    // `report()` è esattamente ciò che ci ha chiamati: misurato sul vendor, 21
    // rientri prima che il framework smetta. Da cui il `try/catch (\Throwable)`
    // **nudo**, e nel catch `error_log()` e non `Log::`.
    //
    // L'asserzione è quindi che il catch sia entrato **una volta sola**.
    Schema::drop('occorrenze_errore');
    Schema::drop('errori');

    $righe = conErrorLog(fn () => CatturaErrori::cattura(new RuntimeException('boom')));

    expect($righe)->toHaveCount(1);
});

it('reopens a resolved issue, and never reopens an ignored one', function () {
    scatenaSempreLoStessoErrore();

    $errore = Errore::sole();

    // Qualcuno l'ha chiusa, con tanto di contesti già spesi.
    //
    // ⚠️ **`forceFill()` e non `update()`**, ed è una trappola che questo test ha
    // pagato scrivendosi: `Errore::$fillable` elenca **solo** ciò che il tracker
    // scrive alla nascita di una issue — `stato`, `contesti` e `risolto_at` ne
    // sono fuori di proposito, perché si muovono da gesti espliciti (blocco 6).
    // Un `update()` di massa li **scarta in silenzio**: la issue restava aperta,
    // il ramo della riapertura non veniva mai percorso, e il test falliva
    // dicendo tutt'altro.
    $errore->forceFill([
        'stato' => 'risolto',
        'risolto_at' => now()->subDay(),
        'contesti' => 20,
        'ultimo_contesto_at' => now()->subDay(),
    ])->save();

    // …e succede di nuovo.
    scatenaSempreLoStessoErrore();

    $errore->refresh();

    expect($errore->stato)->toBe('aperto')
        ->and($errore->occorrenze)->toBe(2)
        ->and($errore->riaperto_automaticamente_at)->not->toBeNull()
        ->and($errore->risolto_at)->toBeNull()
        // ⚠️ **Il budget dei contesti si azzera con la riapertura, e `1` è
        // esattamente la sua prova** — non `0`, come diceva questa riga finché
        // il campionamento non esisteva (blocco 3). Dal blocco 4 l'occorrenza
        // che riapre la issue **spende subito** il budget appena azzerato, ed è
        // giusto che lo faccia: è la prima prova dopo il tentativo di
        // correzione, cioè la risposta a «l'ho corretto, perché succede
        // ancora?», e conservarla è tutto il senso dell'azzeramento.
        //
        // Il test resta falsificabile, e con lo stesso verso di prima: senza
        // l'azzeramento la issue sarebbe ancora a 20 — cioè al tetto — il
        // campionamento non scatterebbe affatto e qui si leggerebbe `20`.
        // Mutazione: in `incrementa()`, togliere `'contesti' => 0` dal ramo
        // della riapertura → rosso.
        ->and($errore->contesti)->toBe(1)
        ->and($errore->ultimo_contesto_at)->not->toBeNull();

    // **La riapertura non è il gesto di una persona e non scrive audit**: il
    // registro racconta chi ha fatto cosa, e qui non c'è nessun chi. Il fatto
    // resta leggibile in `riaperto_automaticamente_at`.
    expect(Activity::count())->toBe(0);

    // Ora la si mette a tacere.
    $errore->forceFill(['stato' => 'ignorato', 'riaperto_automaticamente_at' => null])->save();

    scatenaSempreLoStessoErrore();

    $errore->refresh();

    // 🔴 **`ignorato` è l'unico interruttore di silenzio del tracker**: se una
    // issue ignorata tornasse aperta alla prima occorrenza successiva,
    // ignorare non vorrebbe dire niente. Il contatore cresce lo stesso — la
    // riga continua a dire la verità su quante volte succede.
    expect($errore->stato)->toBe('ignorato')
        ->and($errore->riaperto_automaticamente_at)->toBeNull()
        ->and($errore->occorrenze)->toBe(3);
});

it('costs two queries on the hot path', function () {
    // ⚠️ **Contato sul metodo in isolamento e non su una richiesta HTTP**: una
    // richiesta porta con sé sessione, utente e permessi, e il numero direbbe
    // altro. Qui si misura ciò che il tracker aggiunge a un'eccezione.
    // ⚠️ **La stessa istanza di eccezione**, non due gemelle: l'impronta nasce da
    // dove il `throw` è avvenuto *e* da chi ha chiamato, quindi due `throw`
    // scritti su due righe diverse di questo file sono — correttamente — due
    // issue, e la seconda cattura misurerebbe il ramo dell'INSERT invece di
    // quello dell'UPDATE. (Costano due query entrambi, ma è l'UPDATE atomico che
    // si vuole guardare qui sotto.)
    $eccezione = new RuntimeException('non arriva mai a servire');

    try {
        lancioDentroUnHelper('la stessa, due volte');
    } catch (Throwable $e) {
        $eccezione = $e;
    }

    CatturaErrori::cattura($eccezione);

    DB::enableQueryLog();
    DB::flushQueryLog();

    CatturaErrori::cattura($eccezione);

    $query = DB::getQueryLog();
    DB::disableQueryLog();

    // La SELECT sull'impronta e l'UPDATE del contatore. La transazione annidata
    // non compare: `SAVEPOINT` passa da `PDO::exec()` e non emette
    // `QueryExecuted`.
    expect($query)->toHaveCount(2);

    // ⚠️ **E l'UPDATE è atomico**: `occorrenze = occorrenze + 1` calcolato dal
    // database. Con `$errore->occorrenze + 1` calcolato in PHP due processi che
    // incrementano insieme ne perderebbero uno, e il contatore mentirebbe
    // proprio sugli errori più frequenti — quelli per cui la cifra conta.
    // Nessun test può mettere in scena la corsa; questa asserzione guarda lo
    // SQL, che è la sola prova disponibile.
    expect($query[1]['query'])->toContain('"occorrenze" = "occorrenze" + 1');
});

it('stays silent when the tracker is switched off', function () {
    // L'interruttore d'emergenza. ⚠️ In produzione la config è cachata in build,
    // quindi spegnerlo **richiede un redeploy**: è lento apposta, e va saputo
    // prima di averne bisogno.
    config(['easylab.errori.abilitato' => false]);

    report(new RuntimeException('nessuno mi registra'));

    expect(Errore::count())->toBe(0);

    // Ma il logger continua a ricevere: spegnere il tracker non spegne
    // `laravel.log`.
    Log::shouldHaveReceived('error')->once();
});

it('never runs inside itself, whatever sits on the road of its own write', function () {
    // 🔴 **La superficie vera del flag di rientranza**, che nessun test copriva:
    // qualunque cosa riporti un'eccezione *mentre* il tracker sta scrivendo —
    // un observer, un listener, un `report()` di cortesia — riaprirebbe il giro.
    //
    // ⚠️ Non è il caso che il docblock attribuiva al flag in prima stesura
    // (`Prunable::pruneAll()`): quello chiama `report()` **dopo** che `cattura()`
    // è uscita, quindi il flag non è più sullo stack e non protegge. Questo
    // invece sta dentro, ed è ciò che il flag ferma davvero.
    Errore::created(function () {
        report(new RuntimeException('un guasto sulla strada della scrittura'));
    });

    CatturaErrori::cattura(new RuntimeException('il guasto vero'));

    Errore::flushEventListeners();

    // Una riga sola: il secondo giro non è partito. Senza il flag sarebbero due.
    expect(Errore::count())->toBe(1);
});

it('never mistakes vendor or a compiled view for our own code', function () {
    // 🔴 **Le due voci della denylist non avevano rete**, ed erano fra le
    // decisioni più delicate del blocco: verificato che toglierle lasciava la
    // suite tutta verde.
    //
    // `vendor/` è ovvio. `storage/` no, ed è il caso che conta: il nome di una
    // vista Blade compilata è un hash del percorso **assoluto**
    // (`Compiler::getCompiledPath()`), quindi su Cloud cambia a ogni release.
    // Se una vista contasse come «codice nostro», un errore dentro un Blade
    // raggrupperebbe **per deploy** — cioè la deriva che i percorsi relativi
    // esistono per impedire, rientrata dalla porta di servizio.
    $radice = base_path().'/';

    [$impronta] = CatturaErrori::identifica('RuntimeException', [
        $radice.'storage/framework/views/1111111111111111.php:8',
        $radice.'vendor/laravel/framework/src/Illuminate/Routing/Controller.php:54',
        $radice.'app/Support/Qualcosa.php:12',
        $radice.'app/Http/Controllers/Chiamante.php:30',
    ]);

    // La stessa eccezione, dopo un deploy: la vista compilata cambia nome, il
    // frame di vendor pure. L'impronta non si muove.
    [$dopoIlDeploy] = CatturaErrori::identifica('RuntimeException', [
        $radice.'storage/framework/views/9999999999999999.php:8',
        $radice.'vendor/laravel/framework/src/Illuminate/Routing/Controller.php:71',
        $radice.'app/Support/Qualcosa.php:12',
        $radice.'app/Http/Controllers/Chiamante.php:30',
    ]);

    expect($dopoIlDeploy)->toBe($impronta);

    // E il file scelto è quello **nostro**, non la vista né il vendor.
    [, $file] = CatturaErrori::identifica('RuntimeException', [
        $radice.'storage/framework/views/1111111111111111.php:8',
        $radice.'app/Support/Qualcosa.php:12',
        $radice.'app/Http/Controllers/Chiamante.php:30',
    ]);

    expect($file)->toBe('app/Support/Qualcosa.php');
});

it('keeps a purely vendor exception out of the per-deploy drift', function () {
    // Il ramo di **fallback**, quando nessun frame è codice nostro: senza una
    // rete, il fallback può ripescare proprio la vista compilata e reintrodurre
    // la deriva per deploy dalla porta di servizio.
    $radice = base_path().'/';

    $prima = CatturaErrori::identifica('RuntimeException', [
        $radice.'vendor/pacchetto/src/A.php:10',
        $radice.'vendor/pacchetto/src/B.php:20',
    ])[0];

    $dopo = CatturaErrori::identifica('RuntimeException', [
        $radice.'vendor/pacchetto/src/A.php:10',
        $radice.'vendor/pacchetto/src/B.php:20',
    ])[0];

    // ⚠️ **E un file fuori dalla radice del progetto non deve portarsi dentro il
    // proprio percorso assoluto**: su Cloud la radice cambia a ogni release.
    $fuori = CatturaErrori::identifica('RuntimeException', ['/opt/php/lib/qualcosa.php:3'])[0];

    expect($dopo)->toBe($prima)
        ->and($fuori)->not->toBe('');
});
