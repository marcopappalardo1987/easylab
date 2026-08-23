<?php

use App\Models\Errore;
use App\Models\OccorrenzaErrore;
use App\Models\User;
use App\Support\Errori\CatturaErrori;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 Il **contesto** delle occorrenze dell'error tracker (S6 — blocco 4 di
 * `docs/Architettura/Error Tracker Interno (piano).md`; Privacy T8).
 *
 * `CatturaErroriTest` prova che l'errore venga *registrato* e che il tracker non
 * faccia danni. Questo file prova l'altra metà, che è quasi tutta una questione
 * di **privacy**: cosa finisce in `occorrenze_errore`, e soprattutto cosa non ci
 * finisce.
 *
 * ⚠️ **Il gate rende questi test l'unica sorveglianza che c'è.** La pagina degli
 * errori è del **solo Developer** (`system.logs.view`, permesso bloccato): un
 * segreto scritto qui dentro non lo vedrebbe nessuno che possa segnalarlo, e ci
 * resterebbe per tutta la retention. Non c'è una revisione a valle da cui
 * accorgersene — c'è questo file.
 *
 * ⚠️ **Si legge il DATABASE, non la pagina.** La sanificazione avviene **in
 * scrittura**, a monte: un filtro in lettura lascerebbe il dato in chiaro nella
 * riga, cioè risolverebbe il sintomo lasciando il fatto. Le asserzioni passano
 * quindi da `DB::table()`, e dove conta scorrono **tutte** le colonne — perché
 * il punto non è che un segreto non sia in `input`, è che non sia da nessuna
 * parte.
 */

/**
 * Un helper che riceve un segreto e lancia: è la forma su cui gli **argomenti
 * di funzione** finiscono nello stack trace.
 *
 * Il parametro non si usa apposta — serve solo a stare nel frame.
 */
function chiamataConUnSegreto(string $parolaDordine): never
{
    throw new RuntimeException('un guasto con un argomento addosso');
}

/**
 * Ogni colonna di ogni riga di `occorrenze_errore`, come stringhe piatte.
 *
 * ⚠️ **Si passa da `DB::table()` e non dal model**: il cast `array` di `input`
 * restituirebbe un array, e un `toContain()` su un array cerca un **elemento**,
 * non una sottostringa — quindi un segreto annidato dentro `payload.api_token`
 * non verrebbe trovato e il test sarebbe verde su una fuga vera. In forma
 * grezza `input` è il JSON, dove una sottostringa è una sottostringa.
 *
 * @return list<string>
 */
function valoriDelleOccorrenze(): array
{
    $valori = [];

    foreach (DB::table('occorrenze_errore')->get() as $riga) {
        foreach ((array) $riga as $valore) {
            $valori[] = (string) $valore;
        }
    }

    return $valori;
}

beforeEach(function () {
    // Come in `CatturaErroriTest`: qui si fanno esplodere cose di proposito, e
    // `laravel.log` non deve raccoglierle a ogni giro di suite.
    Log::spy();
});

// --- Il caso che ha dettato la denylist -------------------------------------

it('never lets a 2FA recovery code reach any column of the tracker', function () {
    // 🔴 **Il test per cui `ChiaviSensibili` è nata estesa, ed è una POST vera.**
    // `two-factor-challenge.blade.php` spedisce due campi che si chiamano `code`
    // e `recovery_code`: con la denylist di `DettaglioAttivita`
    // (`password`, `token`, `secret`) un guasto su questa richiesta avrebbe
    // scritto il **codice di recupero 2FA** in chiaro — una credenziale
    // permanente, non un numero che scade in trenta secondi — in una tabella che
    // solo il Developer può leggere e che nessun altro può controllare.
    //
    // Mutazione: togliere `'code'` da `ChiaviSensibili::CHIAVI` → rosso.
    //
    // ⚠️ **Il guasto è vero e nasce dentro Fortify**, non da una rotta finta:
    // `recoveryCodes()` decifra `two_factor_recovery_codes`, e su un payload che
    // non è cifrato lancia `DecryptException` — che non è nella lista ereditata
    // degli ignorati, quindi arriva al tracker. È l'unico modo di far passare
    // davvero la richiesta dal controller che riceve quei campi.
    $utente = User::factory()->create();

    $utente->forceFill([
        'two_factor_secret' => encrypt('SEGRETOTOTP'),
        'two_factor_recovery_codes' => 'questo-non-e-un-payload-cifrato',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $codiceDiRecupero = 'RECUPERO-7f3a91b2-DA-NON-SALVARE';
    $codiceTotp = '654321-CODICE-TOTP';

    $this->withSession(['login.id' => $utente->id])->post('/two-factor-challenge', [
        '_token' => 'csrf-fittizio',
        'code' => $codiceTotp,
        'recovery_code' => $codiceDiRecupero,
    ]);

    $occorrenza = OccorrenzaErrore::sole();

    // Il contesto è stato riconosciuto come HTTP e la richiesta è quella giusta:
    // senza questa metà, «il codice non c'è» si leggerebbe uguale se non fosse
    // stata registrata nessuna occorrenza affatto.
    expect($occorrenza->percorso)->toBe('two-factor-challenge')
        ->and($occorrenza->metodo)->toBe('POST')
        ->and($occorrenza->contesto)->toBe('http');

    // 🔴 **Nessuna colonna, non «non in `input`».** Un segreto ha più di una
    // strada per entrare — l'input, gli argomenti dei frame, il messaggio, il
    // percorso — e chiuderne una sola è il difetto che questo blocco ha già
    // ripetuto tre volte. Si guardano tutte le colonne, sempre.
    //
    // ⚠️ *La prima stesura di questo commento aggiungeva una seconda ragione
    // inventata: che il codice sarebbe uscito dagli argomenti di
    // `TwoFactorLoginRequest::validRecoveryCode()`. Verificato: quel metodo
    // **non ha parametri**, legge da `$this->recovery_code`. Il test è verde per
    // la ragione giusta — togliere `code` dalla denylist lo rende rosso — ma la
    // motivazione era falsa.*
    foreach (valoriDelleOccorrenze() as $valore) {
        expect($valore)->not->toContain($codiceDiRecupero);
        expect($valore)->not->toContain($codiceTotp);
    }

    // E qui l'input finisce **a `null`**, non svuotato: tutte e tre le chiavi
    // della schermata 2FA sono sensibili (`code`, `recovery_code`, `_token`),
    // quindi dopo la ripulitura non resta niente da salvare. È il caso limite
    // giusto, e va detto — la prima stesura del commento diceva «non buttato
    // via» due righe sopra un assert che verifica esattamente il contrario.
    expect($occorrenza->input)->toBeNull();
});

// --- Gli argomenti dei frame ------------------------------------------------

it('saves a stack trace rebuilt from the frames, and never a function argument', function () {
    // 🔴 **Le due mutazioni, e la seconda è la ragione per cui il trace rende il
    // residuo del frame invece di leggere le chiavi attese.**
    //
    // 1. `self::traccia($e)` → `$e->getTraceAsString()`: **rosso**.
    //    `getTraceAsString()` tronca gli argomenti a **15 caratteri** ma li
    //    stampa — verificato: `conSegreto('PAROLADORDINE12...')`. Di una password
    //    quindici caratteri sono più che abbastanza per riconoscerla.
    // 2. In `traccia()`, togliere `unset($frame['args'])`: **rosso**, perché il
    //    residuo del frame finisce nella riga. Con un'allowlist di chiavi quella
    //    mutazione sarebbe **verde**, cioè la guardia più importante del blocco
    //    sarebbe l'unica senza rete.
    $segreto = 'PAROLADORDINE12345678';

    try {
        chiamataConUnSegreto($segreto);
    } catch (Throwable $e) {
        report($e);
    }

    $occorrenza = OccorrenzaErrore::sole();

    // Il trace c'è ed è **nostro**, ricostruito dai frame: la prima riga dice
    // dov'è nata l'eccezione, che `getTrace()[0]` non direbbe (quello è già il
    // chiamante).
    expect($occorrenza->stack_trace)->toContain('chiamataConUnSegreto()');
    expect($occorrenza->stack_trace)->toContain('origine tests/Feature/Piattaforma/ContestoErroriTest.php:');
    // Percorsi **relativi**: su Laravel Cloud ogni release vive in una directory
    // diversa, e un trace pieno di percorsi assoluti invecchia in un giorno.
    expect($occorrenza->stack_trace)->not->toContain(base_path());

    // 🔴 Il segreto intero…
    expect($occorrenza->stack_trace)->not->toContain($segreto);
    // 🔴 …e i suoi primi quindici caratteri, che è ciò che `getTraceAsString()`
    // lascerebbe passare. Senza questa seconda riga la mutazione 1 resterebbe
    // verde.
    expect($occorrenza->stack_trace)->not->toContain(mb_substr($segreto, 0, 15));

    // Per sicurezza: da nessuna parte, non solo nel trace.
    foreach (valoriDelleOccorrenze() as $valore) {
        expect($valore)->not->toContain(mb_substr($segreto, 0, 15));
    }
});

it('strips the NUL byte an anonymous class drags into the trace', function () {
    // ⚠️ Il nome di una classe anonima è `class@anonymous\0/percorso/File.php:12$0`,
    // **col byte NUL dentro**. Su Postgres `text` lo rifiuta
    // (`invalid byte sequence for encoding "UTF8": 0x00`), su SQLite tronca in
    // silenzio: nel primo caso la riga non si scrive affatto e il guasto viene
    // inghiottito dal catch di `cattura()`, lasciando una issue con zero
    // contesti — indistinguibile dal campionamento.
    $anonima = new class
    {
        public function esplodi(): never
        {
            throw new RuntimeException('da una classe senza nome');
        }
    };

    try {
        $anonima->esplodi();
    } catch (Throwable $e) {
        report($e);
    }

    $occorrenza = OccorrenzaErrore::sole();

    expect($occorrenza->stack_trace)->toContain('class@anonymous');
    expect($occorrenza->stack_trace)->not->toContain("\0");
});

// --- La firma, che rientra dai parametri ------------------------------------

it('keeps a URL signature out of the row, and out of the input it would sneak back in from', function () {
    // 🔴 **Chiudere la porta non basta: la firma rientra dalla finestra.**
    // Salvare `path()` invece di `fullUrl()` toglie la query string dal
    // percorso, ma `$request->input()` è **input + query**: su una rotta firmata
    // — i download da Backblaze, ADR-026 — `signature` ed `expires` rientrano da
    // lì. Verificato, ed è la ragione per cui le due chiavi sono nella denylist.
    //
    // Mutazione: togliere `'signature'` da `ChiaviSensibili::CHIAVI` → rosso.
    Route::middleware('web')->get('/prova/firmata', fn () => throw new RuntimeException('guasto su rotta firmata'));

    $firma = 'FIRMA-INCONFONDIBILE-abc123';

    $this->get('/prova/firmata?signature='.$firma.'&expires=1798761600&documento=7');

    $occorrenza = OccorrenzaErrore::sole();

    // La porta: il percorso è `path()`, senza query string.
    expect($occorrenza->percorso)->toBe('prova/firmata');

    // 🔴 **Asserito sul VALORE, non sulla presenza della chiave.** Un test che
    // guarda `array_key_exists('signature', $input)` sarebbe verde anche se la
    // firma comparisse nel percorso, nel messaggio o nello stack trace.
    foreach (valoriDelleOccorrenze() as $valore) {
        expect($valore)->not->toContain($firma);
        expect($valore)->not->toContain('1798761600');
    }

    // …e ciò che non è sensibile resta, o si sarebbe buttato via il contesto
    // invece di ripulirlo.
    expect($occorrenza->input)->toBe(['documento' => '7']);
});

it('keeps a signature out even when it arrives from the request body', function () {
    // La stessa chiave, dall'altra via: la denylist guarda il **nome**, quindi
    // deve prendere anche un `signature` spedito nel corpo di una POST — che è
    // la forma di un webhook, non di una URL firmata.
    Route::middleware('web')->post('/prova/webhook', fn () => throw new RuntimeException('guasto su webhook'));

    $firma = 'FIRMA-DAL-CORPO-xyz789';

    $this->post('/prova/webhook', ['signature' => $firma, 'evento' => 'invoice.paid']);

    foreach (valoriDelleOccorrenze() as $valore) {
        expect($valore)->not->toContain($firma);
    }

    expect(OccorrenzaErrore::sole()->input)->toBe(['evento' => 'invoice.paid']);
});

// --- L'annidamento ----------------------------------------------------------

it('cleans nested keys on the way in, not on the way out', function () {
    // 🔴 **In scrittura, e si legge il database.** Un filtro applicato in lettura
    // lascerebbe il segreto nella riga: risolverebbe il sintomo (la pagina non
    // lo mostra) lasciando il fatto (il database ce l'ha), dietro un gate che
    // nessuno tranne il Developer può ispezionare.
    //
    // Mutazione: in `ChiaviSensibili::ripulisci()`, non ricorrere sugli
    // annidamenti → rosso. È la forma normale di un corpo un po' strutturato,
    // non un caso di laboratorio.
    Route::middleware('web')->post('/prova/annidata', fn () => throw new RuntimeException('guasto con payload'));

    $this->post('/prova/annidata', [
        'payload' => [
            'api_token' => 'TOKEN-ANNIDATO-999',
            'guard' => 'web',
            'utente' => ['password' => 'Segret1ssima!', 'email' => 'anna@esempio.it'],
        ],
        'nota' => 'la riga che deve restare',
    ]);

    // Letto **grezzo**: è il JSON che sta nella colonna, non l'array che il cast
    // ricostruisce.
    $grezzo = (string) DB::table('occorrenze_errore')->value('input');

    expect($grezzo)->not->toContain('TOKEN-ANNIDATO-999');
    expect($grezzo)->not->toContain('Segret1ssima!');

    // E il resto è sopravvissuto a ogni livello, chiave `email` compresa — che è
    // il limite dichiarato della denylist (guarda la chiave, mai il valore) e
    // non un difetto da chiudere qui: la risposta è la retention di T8.
    expect(OccorrenzaErrore::sole()->input)->toBe([
        'payload' => [
            'guard' => 'web',
            'utente' => ['email' => 'anna@esempio.it'],
        ],
        'nota' => 'la riga che deve restare',
    ]);
});

it('saves the context even when the input carries malformed UTF-8', function () {
    // ⚠️ **Un byte solo renderebbe insalvabile l'INTERA riga.** Il cast `array`
    // di Eloquent serializza con `json_encode()` nudo, che su UTF-8 malformato
    // lancia `JsonEncodingException`: a raccoglierla ci sarebbe il catch di
    // `cattura()`, che scarterebbe **tutto** il contesto — stack trace compreso —
    // per un byte arrivato da un client. Si perderebbe la prova proprio sulle
    // richieste strane, che sono quelle per cui si guarda un error tracker.
    //
    // Mutazione: in `input()`, `self::json()` → `json_encode()` nudo, e il
    // contesto sparisce (zero occorrenze) invece di arrivare monco.
    Route::middleware('web')->post('/prova/bytes', fn () => throw new RuntimeException('guasto con byte strani'));

    $this->post('/prova/bytes', ['nota' => "prima \xB1\x31\xB2 dopo", 'buona' => 'intatta']);

    $occorrenza = OccorrenzaErrore::sole();

    expect($occorrenza->stack_trace)->toContain('guasto con byte strani');
    // La chiave buona non è stata trascinata via dalla vicina malformata.
    expect($occorrenza->input['buona'])->toBe('intatta');
    expect($occorrenza->input)->toHaveKey('nota');
});

// --- Chi c'era --------------------------------------------------------------

it('attributes an occurrence to the impersonated user, and the impersonator apart', function () {
    // 🔴 **`user_id` è chi ha subito l'errore, `impersonato_da` chi stava davvero
    // agendo**, ed è lo stesso verso del timbro che l'audit mette su ogni riga
    // (`properties.impersonato_da`). Invertirli attribuirebbe al cliente un
    // guasto incontrato da EasyLab: un dato falso su una persona.
    //
    // ⚠️ Dev'essere giusto **dalla nascita**: `occorrenze_errore` è append-only e
    // non si corregge, e questa è la sola riga del tracker che nomini qualcuno.
    $this->seed(RolesAndPermissionsSeeder::class);

    $developer = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');

    $cliente = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $cliente->assignRole('Admin');

    $this->actingAs($developer->fresh())->get(route('impersonate', $cliente));

    expect(app('impersonate')->isImpersonating())->toBeTrue();

    CatturaErrori::cattura(new RuntimeException('un guasto durante un\'impersonazione'));

    $occorrenza = OccorrenzaErrore::sole();

    expect($occorrenza->user_id)->toBe($cliente->id)
        ->and($occorrenza->impersonato_da)->toBe($developer->id);
});

it('never invents an HTTP context for an error born in console', function () {
    // ⚠️ **Fuori da HTTP `request()` non è assente: è FABBRICATA da `$_SERVER`**,
    // e dice `GET`, percorso `/`, ip `127.0.0.1`, user agent `Symfony`.
    // Verificato in console. Salvarla darebbe a ogni errore di un comando o di un
    // job un contesto HTTP inventato di sana pianta — dati falsi su una riga che
    // esiste per dire la verità su cosa è successo, e un ip che nessuno ha usato.
    //
    // Mutazione: in `contesto()`, `$richiesta = request()` senza la guardia
    // sull'ambiente → rosso su tutte e quattro le colonne qui sotto.
    CatturaErrori::cattura(new RuntimeException('un guasto in console'));

    $occorrenza = OccorrenzaErrore::sole();

    expect($occorrenza->contesto)->toBe('console')
        ->and($occorrenza->percorso)->not->toBe('/')
        ->and($occorrenza->metodo)->toBe('CLI')
        ->and($occorrenza->codice_http)->toBeNull()
        ->and($occorrenza->ip)->toBeNull()
        ->and($occorrenza->user_agent)->toBeNull()
        ->and($occorrenza->input)->toBeNull();
});

// --- Il campionamento -------------------------------------------------------

it('stops keeping contexts past the cap, while the counter keeps counting', function () {
    // 🔴 **Provato SUPERANDO il tetto, e non toccandolo.** Tre eccezioni → tre
    // occorrenze è verde anche senza nessun cap: l'unica forma che può cadere è
    // chiederne più del consentito e contare quante ne restano.
    //
    // La finestra è messa a zero per isolare il cap: qui si prova una delle due
    // guardie per volta, o un test rosso non direbbe quale.
    config([
        'easylab.errori.contesti_per_errore' => 3,
        'easylab.errori.finestra_contesto_secondi' => 0,
    ]);

    // La **stessa istanza** cinque volte: due `throw` su righe diverse sarebbero
    // — correttamente — due issue, e ciascuna avrebbe il proprio budget.
    $eccezione = new RuntimeException('lo stesso guasto, cinque volte');

    foreach (range(1, 5) as $volta) {
        CatturaErrori::cattura($eccezione);
    }

    $errore = Errore::sole();

    // Il contatore dice la verità su **quante volte succede**…
    expect($errore->occorrenze)->toBe(5)
        // …e la contabilità dei contesti su **quante prove se ne sono tenute**.
        // Le due cifre non sono la stessa cosa, ed è la ragione per cui la
        // pagina dovrà dirle insieme.
        ->and($errore->contesti)->toBe(3)
        ->and(OccorrenzaErrore::count())->toBe(3);

    // Mutazione: in `daCampionare()`, `>=` → `>` sul tetto, e ne passerebbe una
    // quarta.
});

it('keeps at most one context per window, however hot the loop', function () {
    // La seconda guardia, isolata dalla prima: la finestra è ciò che impedisce a
    // un errore che scatta mille volte al minuto di spendere l'intero budget in
    // un secondo — e di spenderlo tutto sullo **stesso istante**, cioè venti
    // copie della stessa fotografia invece di venti fotografie.
    config([
        'easylab.errori.contesti_per_errore' => 20,
        'easylab.errori.finestra_contesto_secondi' => 60,
    ]);

    $eccezione = new RuntimeException('un errore in loop caldo');

    foreach (range(1, 4) as $volta) {
        CatturaErrori::cattura($eccezione);
    }

    expect(Errore::sole()->occorrenze)->toBe(4)
        ->and(OccorrenzaErrore::count())->toBe(1);

    // Passata la finestra, il campionamento riparte: la guardia rallenta, non
    // spegne. Senza questa metà il test sarebbe verde anche su un tracker che
    // conserva **un solo** contesto per issue e poi tace per sempre.
    $this->travel(61)->seconds();

    CatturaErrori::cattura($eccezione);

    expect(OccorrenzaErrore::count())->toBe(2);
});

it('spends the freshly reset budget on the very occurrence that reopened the issue', function () {
    // 🔴 **La ragione per cui l'azzeramento esiste, scritta come dato.** Una issue
    // risolta che torna: senza il reset sarebbe già al tetto e non
    // catturerebbe **mai più** contesto, cioè si perderebbe proprio la prova che
    // serve a rispondere a «l'ho corretto, perché succede ancora?».
    //
    // ⚠️ E il reset non basta a database: se l'istanza in memoria non venisse
    // allineata, `contesto()` deciderebbe sul budget **vecchio** (20) e la prova
    // non verrebbe scritta lo stesso — con la riga di `errori` che dice `0` e la
    // tabella dei contesti vuota. Mutazione: in `incrementa()`, togliere
    // `$errore->forceFill($altre)->syncOriginal()` → rosso qui, verde altrove.
    $eccezione = new RuntimeException('era stato corretto');

    CatturaErrori::cattura($eccezione);

    $errore = Errore::sole();

    // `forceFill()` e non `update()`: `$fillable` elenca solo ciò che il tracker
    // scrive alla nascita, e un `update()` di massa scarterebbe `stato` e
    // `contesti` **in silenzio**.
    $errore->forceFill([
        'stato' => 'risolto',
        'risolto_at' => now()->subDay(),
        'contesti' => 20,
        'ultimo_contesto_at' => now()->subDay(),
    ])->save();

    CatturaErrori::cattura($eccezione);

    expect($errore->fresh()->contesti)->toBe(1)
        ->and(OccorrenzaErrore::query()->where('errore_id', $errore->id)->count())->toBe(2);
});

it('does not lose the issue when the context cannot be written', function () {
    // ⚠️ **Le due scritture non condividono la sorte**, ed è una scelta: la issue
    // e il suo contatore sono il dato che non si può perdere, il contesto è una
    // prova in più. Con una transazione sola, una colonna troppo corta o un JSON
    // che non si serializza porterebbero via anche il fatto che l'errore è
    // successo — cioè il tracker perderebbe tutto proprio sugli errori più
    // strani.
    //
    // ⚠️ Su **Postgres** questa riga prova anche il savepoint: una query fallita
    // aborta l'intera transazione (25P02), e senza la transazione propria di
    // `contesto()` la SELECT qui sotto risponderebbe «current transaction is
    // aborted».
    Schema::drop('occorrenze_errore');

    CatturaErrori::cattura(new RuntimeException('la issue deve restare'));

    $errore = Errore::sole();

    expect($errore->messaggio)->toBe('la issue deve restare')
        ->and($errore->occorrenze)->toBe(1)
        // Il budget non è stato consumato da un contesto che non è mai stato
        // scritto: alla prossima occorrenza si riproverà.
        ->and($errore->contesti)->toBe(0);
});

it('never writes a route parameter value, which is where the live tokens are', function () {
    // 🔴 **La porta era chiusa e la finestra accanto no.** Il blocco evitava
    // `fullUrl()` per non scrivere la firma, e metteva `signature` nella
    // denylist per l'input — ma non guardava **dentro il percorso**. Questo
    // progetto ha davvero `reset-password/{token}` (Fortify), `q/{token}` (QR)
    // e `email/verify/{id}/{hash}`: con `path()` un'eccezione su quelle pagine
    // scriveva un **gettone vivo** in una colonna conservata mesi, dietro il
    // gate che nessuno tranne il Developer può ispezionare.
    $gettone = 'GETTONE-VIVO-abcdef0123456789';

    Route::middleware('web')->get('sonda/reset-password/{token}', function (string $token) {
        throw new RuntimeException('esplode con un gettone nell indirizzo');
    });

    $this->get('sonda/reset-password/'.$gettone);

    $occorrenza = OccorrenzaErrore::latest('id')->firstOrFail();

    // ⚠️ Si asserisce **sul valore** e su tutte le colonne, non sulla presenza
    // di una chiave: è la lezione della firma, un gradino più in là.
    foreach ($occorrenza->getAttributes() as $colonna => $valore) {
        expect((string) $valore)->not->toContain($gettone);
    }

    // E ciò che resta è lo **schema**, che è anche il raggruppamento giusto:
    // mille gettoni diversi sono una riga sola, non mille.
    expect($occorrenza->percorso)->toBe('sonda/reset-password/{token}');
});

it('never writes a query binding, which the database exception interpolates', function () {
    // 🔴 `QueryException` costruisce il messaggio interpolando i binding
    // nell'SQL: **ogni valore di ogni query fallita** finisce lì, e il messaggio
    // non passava da nessuna denylist. Il valore usciva in tre colonne, fra cui
    // `errori.messaggio` — che si scrive una volta sola, non si campiona, e vive
    // quanto la issue.
    $segreto = 'RECUPERO-SEGRETO-999';

    // ⚠️ **`DB::transaction()` annidato (SAVEPOINT), o su Postgres il test cade
    // per la ragione sbagliata**: là una query fallita aborta l'intera
    // transazione, e dentro `RefreshDatabase` ogni lettura successiva risponde
    // «current transaction is aborted». È la trappola già pagata nei blocchi 1
    // e 3, e tocca *qualunque* test che asserisca dopo un errore atteso.
    try {
        DB::transaction(function () use ($segreto) {
            DB::select('select * from tabella_che_non_esiste where recovery_code = ?', [$segreto]);
        });
    } catch (Throwable $e) {
        // La cattura sta **fuori** dalla transazione, non dentro: è
        // `DB::transaction()` a fare il `ROLLBACK TO SAVEPOINT` quando la query
        // fallisce, e solo dopo quel rollback la connessione torna usabile. Con
        // la cattura dentro, il rollback non avverrebbe mai e ogni lettura
        // successiva risponderebbe «transazione abortita».
        CatturaErrori::cattura($e);
    }

    $issue = Errore::latest('id')->firstOrFail();
    $occorrenza = OccorrenzaErrore::latest('id')->firstOrFail();

    foreach ([...array_values($issue->getAttributes()), ...array_values($occorrenza->getAttributes())] as $valore) {
        expect((string) $valore)->not->toContain($segreto);
    }

    // ⚠️ E la diagnostica resta: non si taglia la coda `SQL: …`, che è la cosa
    // più utile che un tracker possa dire — si ricostruisce dai segnaposto.
    expect($issue->messaggio)->toContain('tabella_che_non_esiste')
        ->and($issue->messaggio)->toContain('?');
});

it('never writes the name of an uploaded file', function () {
    // 🔴 La guardia col docblock più lungo del blocco — `input()` e non `all()` —
    // non aveva **nessuna rete**: sostituirla lasciava la suite tutta verde.
    // `all()` include i file, e un `UploadedFile` attraversato dalla ripulitura
    // si apre nei campi privati, portando dentro il nome originale del file (che
    // su questo progetto può essere «referto-Rossi-2026.pdf») e chiavi con byte
    // NUL.
    $nome = 'referto-PAZIENTE-SEGRETO.pdf';

    Route::middleware('web')->post('sonda/carica', function () {
        throw new RuntimeException('esplode con un allegato');
    });

    $this->post('sonda/carica', [
        'nota' => 'ciao',
        'allegato' => UploadedFile::fake()->create($nome, 10),
    ]);

    $occorrenza = OccorrenzaErrore::latest('id')->firstOrFail();

    foreach ($occorrenza->getAttributes() as $valore) {
        expect((string) $valore)->not->toContain('PAZIENTE-SEGRETO');
    }

    expect($occorrenza->input)->toBe(['nota' => 'ciao']);
});
