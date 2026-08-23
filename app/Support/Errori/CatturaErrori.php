<?php

namespace App\Support\Errori;

use App\Models\Errore;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * 🔴 La cattura dell'error tracker interno (S6 — 🔗 `docs/Architettura/Error
 * Tracker Interno (piano).md`, ADR-017 quando sarà scritto).
 *
 * Agganciata a `$exceptions->report()` in `bootstrap/app.php`, scrive **una
 * riga in `errori`** per ogni punto d'origine: la prima volta la crea, dalla
 * seconda in poi incrementa il contatore. Il **contesto** (stack trace, input,
 * utente) non nasce qui: è materia del blocco successivo, e questa classe non
 * tocca `occorrenze_errore`.
 *
 * ## Le regole che questa classe non può violare, e perché
 *
 * **1. Non lancia mai.** Gira dentro il gestore delle eccezioni, cioè quando
 * l'applicazione sta già andando male: un'eccezione lanciata da qui
 * sostituirebbe l'errore vero con il proprio, e la pagina di errore mostrerebbe
 * «tabella errori non trovata» al posto della causa. Da cui il `try/catch
 * (\Throwable)` **nudo**, e nel catch `error_log()` — la sola scrittura di log
 * che non passa da Laravel.
 *
 * ⚠️ **Mai `rescue()`**, per quanto sarebbe la forma idiomatica: `rescue()`
 * chiama `report()` sul proprio guasto, e `report()` è esattamente ciò che ci ha
 * chiamati. Misurato sul vendor: **21 rientri** prima che il framework smetta.
 * Per la stessa ragione nel catch non si usa `Log::` — un logger che non riesce
 * a scrivere lancia, e saremmo di nuovo qui.
 *
 * **2. Non sopprime il logger.** `Handler::reportThrowable()` interrompe la
 * catena se un callback restituisce `false` (`=== false`), e da lì `laravel.log`
 * non riceve più nulla: il tracker interno *sostituirebbe* il log invece di
 * affiancarlo, e su un database irraggiungibile non resterebbe niente da
 * nessuna parte. `cattura()` è `void` apposta.
 *
 * **3. Non ridichiara la lista degli ignorati.** I callback di `report()` girano
 * **dopo** `shouldntReport()`, quindi `ValidationException`,
 * `AuthenticationException`, `AuthorizationException`, `ModelNotFoundException`,
 * `TokenMismatchException` e tutta la famiglia `HttpException` non arrivano mai
 * qui: la lista si **eredita**, non si copia — una copia divergerebbe in
 * silenzio alla prima versione di Laravel che la allunga.
 *
 * ⚠️ **Conseguenza che sorprende, e va saputa**: `NotFoundHttpException` non è
 * elencata letteralmente, è coperta da `HttpException`. Quindi **un `abort(500)`
 * non viene tracciato**, perché `HttpException` esclude tutta la famiglia, non
 * solo i 4xx. Chi vorrà vedere i 500 deliberati dovrà chiederli a parte.
 *
 * ⚠️ **E non si usa `$exceptions->throttle()`**: sta *dentro* `shouldntReport()`,
 * cioè strozzerebbe anche `laravel.log`. Il verso è sbagliato.
 *
 * ## Il costo
 *
 * Due query **di dominio** sul percorso caldo: la SELECT sull'impronta e, a
 * seconda del ramo, l'INSERT della issue nuova o l'UPDATE atomico del contatore.
 * È la ragione per cui la contabilità del campionamento vive su `errori` e non
 * si deriva contando le occorrenze già salvate.
 *
 * ⚠️ **I round trip veri sono di più, e va detto**: il savepoint che protegge la
 * transazione del chiamante ne aggiunge (BEGIN, e SAVEPOINT sul ramo di
 * collisione), e Laravel non emette mai `RELEASE SAVEPOINT`, quindi il query log
 * ne conta meno di quanti ne partono davvero. Il «due» qui sopra è ciò che si
 * misura e ciò su cui si decide la forma dei dati, non il costo di rete.
 */
final class CatturaErrori
{
    /**
     * Le due sole directory del progetto che **non** sono codice
     * dell'applicazione, e la seconda non è ovvia.
     *
     * `vendor/` è il framework: un frame là dentro dice dove il codice altrui si
     * è accorto del guasto, non dove l'abbiamo causato.
     *
     * ⚠️ `storage/` c'è per i **template Blade compilati**, e senza quella voce
     * l'impronta deriverebbe a ogni deploy proprio dopo tutto il lavoro fatto
     * per impedirlo: il nome del file compilato è `hash('xxh128', 'v2'.$path)`
     * dove `$path` è il percorso **assoluto** della vista, quindi su Laravel
     * Cloud — dove ogni release vive in una directory diversa — l'hash cambia a
     * ogni rilascio. Un errore dentro una vista raggrupperebbe per file
     * compilato, cioè per deploy. Escludendo `storage/` si scende invece al
     * primo frame vero, che è il componente Livewire o il controller.
     */
    private const NON_APPLICATIVE = ['vendor/', 'storage/'];

    /**
     * Guardia di rientranza: `cattura()` non gira dentro sé stessa.
     *
     * Non è prudenza generica, ha una superficie che si può nominare: chiunque
     * metta un `report()`, un observer o un listener **sulla strada della nostra
     * scrittura** riaprirebbe il giro, e con questa riga il secondo giro non
     * parte. Un observer su `Errore` che riporta lo dimostra: col flag una riga,
     * senza due.
     *
     * ⚠️ **NON copre `Prunable::pruneAll()`**, che la prima stesura di questo
     * docblock gli attribuiva. Verificato: `pruneAll()` cattura `Throwable` e
     * chiama `report()` **dopo** che `cattura()` è già uscita, quindi il flag non
     * è più sullo stack e un guasto mentre si potano gli errori finisce **dentro
     * `errori`**. Il blocco 7 non può contarci: se quel caso va evitato, serve
     * una guardia sua.
     *
     * ⚠️ **`static` e non d'istanza**: la classe non si istanzia, e comunque il
     * rientro arriverebbe da una chiamata nuova, non dallo stesso oggetto.
     */
    private static bool $inCorso = false;

    /**
     * Il punto d'ingresso, agganciato in `bootstrap/app.php`.
     *
     * ⚠️ **Il type hint `Throwable` sul callback non è decorazione.**
     * `ReportableHandler::handles()` legge il tipo del primo parametro con
     * `firstClosureParameterTypes()`, che **lancia** se non ne trova nessuno: un
     * callback senza type hint non è un tracker silenzioso, è **ogni eccezione
     * riportabile che crasha con zero righe di log**. Il caso davvero silenzioso
     * è l'opposto — un hint *troppo stretto* (`RuntimeException`), che fa
     * passare la maggior parte degli errori senza dire niente.
     *
     * Il ritorno è `void` per la regola 2 del docblock di classe.
     */
    public static function cattura(Throwable $e): void
    {
        if (self::$inCorso) {
            return;
        }

        self::$inCorso = true;

        try {
            // ⚠️ **`config()` sta DENTRO il `try`, e non è pedanteria.** La prima
            // stesura lo leggeva prima, fuori: se il container non ha ancora (o
            // non ha più) il binding `config` — succede in fasi di boot e di
            // teardown — `config()` lancia `BindingResolutionException`, e a
            // lanciare è **il tracker**, dentro il gestore delle eccezioni.
            // Verificato: era l'unica riga capace di rompere l'invariante che dà
            // senso a tutto questo blocco («non lancia mai»), e il docblock la
            // dichiarava assoluta.
            //
            // L'interruttore d'emergenza resta il primo gesto utile: in
            // produzione la config è cachata in build, quindi spegnerlo richiede
            // un redeploy — è lento apposta, e va saputo prima di averne bisogno.
            if (! config('easylab.errori.abilitato')) {
                return;
            }

            self::registra($e);
        } catch (Throwable $guasto) {
            // `error_log()` e non `Log::`: vedi la regola 1. Si nomina
            // l'eccezione **vera** oltre al guasto, o dal log si saprebbe che il
            // tracker si è rotto senza sapere su cosa.
            error_log(sprintf(
                '[easylab] error tracker non ha potuto registrare %s: %s',
                $e::class,
                $guasto->getMessage(),
            ));
        } finally {
            // `finally` e non una riga dopo il catch: senza, un `return`
            // anticipato o un errore fatale lascerebbe la guardia alzata per il
            // resto del processo — cioè un tracker spento in silenzio.
            self::$inCorso = false;
        }
    }

    /**
     * L'impronta, il file e la riga di un'eccezione.
     *
     * **Due posizioni e non una**, ed è la decisione meno ovvia del blocco: con
     * il solo punto d'origine, un `throw` dentro un helper chiamato da cento
     * posti diventerebbe **una** issue per cento cause diverse — cioè il
     * raggruppamento muto che questo tracker esiste per evitare, e da lì
     * «ignorato» su quella riga zittirebbe bug non ancora scritti. La seconda
     * posizione è il **chiamante applicativo**, che distingue le cento cause.
     *
     * **Il messaggio è fuori.** È interpolato («Utente 42 non trovata»), quindi
     * dentro l'impronta darebbe una issue per occorrenza; e `impronta` è
     * `unique`, cioè l'ultimo posto in cui si vuole un dato personale.
     *
     * ⚠️ **Percorsi relativi alla radice del progetto.** Su Laravel Cloud ogni
     * release vive in una directory diversa: con i percorsi assoluti ogni deploy
     * azzererebbe il raggruppamento, e la issue di ieri e quella di oggi
     * sarebbero due righe senza che nulla lo dica.
     *
     * ⚠️ **Anche la classe viene relativizzata**, e sembra un vezzo finché non
     * si incontra una classe anonima: il suo nome è
     * `class@anonymous/percorso/assoluto/File.php:12$0`, cioè porta dentro la
     * radice del deploy. Senza questa riga la deriva rientrerebbe dalla porta di
     * servizio proprio per le classi definite al volo.
     *
     * ⚠️ **Firma pubblica e con le posizioni già estratte**, e non è per
     * comodità: è il solo modo di provare che l'impronta sopravvive a un cambio
     * di radice. `getFile()`, `getLine()` e `getTrace()` sono `final` su
     * `Exception` e `Throwable` non è implementabile, quindi non esiste modo di
     * fabbricare un'eccezione che dica di venire da un altro deploy. Con questa
     * firma il test sposta la radice, non l'eccezione.
     *
     * @param  list<string>  $posizioni  posizioni `percorso:riga` **assolute**, dalla più interna alla più esterna
     * @return array{0: string, 1: string, 2: int} `[impronta, file, riga]`
     */
    public static function identifica(string $classe, array $posizioni): array
    {
        $radice = base_path().'/';

        $relative = array_map(
            static fn (string $posizione): string => str_replace($radice, '', $posizione),
            $posizioni,
        );

        $applicative = array_values(array_filter($relative, self::applicativa(...)));

        // Il fallback: nessun frame nel nostro codice — succede per un'eccezione
        // nata e morta dentro il framework, o sollevata da un `set_error_handler`
        // su codice altrui. Si tiene la prima posizione così com'è: raggruppa
        // comunque per punto d'origine, e dire `vendor/...` è più onesto che
        // dire «sconosciuto».
        $origine = $applicative[0] ?? ($relative[0] ?? ':0');

        // Vuoto quando il chiamante applicativo non c'è (l'origine è il primo
        // frame in assoluto, oppure siamo nel fallback). Concatenato lo stesso,
        // perché l'assenza è essa stessa parte dell'identità del punto.
        $chiamante = $applicative[1] ?? '';

        [$file, $riga] = self::spezza($origine);

        return [
            sha1(str_replace($radice, '', $classe).'|'.$origine.'|'.$chiamante),
            $file,
            $riga,
        ];
    }

    /**
     * Le posizioni di un'eccezione, dalla più interna alla più esterna.
     *
     * ⚠️ **`getFile()` viene prima del trace, e i due non dicono la stessa
     * cosa**: `getFile():getLine()` è dove l'eccezione è **nata** (la riga del
     * `throw`), mentre `getTrace()[0]` è già il **chiamante**. Leggere solo il
     * trace perderebbe quindi la riga del `throw` e sposterebbe tutto di un
     * gradino — con la conseguenza pratica che due `throw` diversi nella stessa
     * funzione diventerebbero la stessa issue.
     *
     * I frame senza `file` (funzione interna, `call_user_func`) si saltano: non
     * hanno una posizione da confrontare.
     *
     * @return list<string>
     */
    private static function posizioni(Throwable $e): array
    {
        $posizioni = [$e->getFile().':'.$e->getLine()];

        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $posizioni[] = $frame['file'].':'.$frame['line'];
            }
        }

        return $posizioni;
    }

    /**
     * Una posizione già relativizzata è **codice nostro**?
     *
     * Denylist e non allowlist, di proposito: con un elenco di directory
     * ammesse, una cartella nuova del progetto (o `routes/`, `database/`,
     * `bootstrap/`, che di eccezioni ne producono eccome) cadrebbe fuori **in
     * silenzio** e finirebbe nel ramo di fallback — cioè il difetto sarebbe
     * indistinguibile dal funzionamento normale. Con la denylist il default è
     * «è nostro», che è il verso giusto in cui sbagliare.
     *
     * Il primo carattere `/` significa che `str_replace()` non ha tolto nulla,
     * cioè che il file sta **fuori** dalla radice del progetto: non è nostro.
     */
    private static function applicativa(string $posizione): bool
    {
        if (str_starts_with($posizione, '/')) {
            return false;
        }

        foreach (self::NON_APPLICATIVE as $prefisso) {
            if (str_starts_with($posizione, $prefisso)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `percorso:riga` → `[percorso, riga]`. `strrpos` e non `explode`: su
     * Windows un percorso comincia con `C:`, e spezzare sul primo `:` darebbe
     * la lettera di unità come nome di file.
     *
     * @return array{0: string, 1: int}
     */
    private static function spezza(string $posizione): array
    {
        $taglio = strrpos($posizione, ':');

        if ($taglio === false) {
            return [$posizione, 0];
        }

        return [substr($posizione, 0, $taglio), (int) substr($posizione, $taglio + 1)];
    }

    /**
     * La scrittura vera e propria: trova-o-crea sull'impronta, poi il contatore.
     *
     * ⚠️ **Non `firstOrCreate()`**, che sotto fa SELECT-poi-INSERT senza rete: su
     * due richieste concorrenti che vedono la stessa eccezione entrambe
     * arrivano al ramo «non esiste», e la seconda sbatte contro l'unique. Si
     * intercetta la violazione e si **rilegge** — è lo stesso schema di
     * `Ricambio::collegaOCrea()`, con la stessa cicatrice.
     *
     * ⚠️ **La transazione esterna non è decorativa, ed è il rimedio a un guasto
     * che su SQLite non si vede.** Su Postgres una query fallita mette
     * l'**intera** transazione in stato aborted (25P02): senza il savepoint, un
     * tracker che non riesce a scrivere — tabella assente, disco pieno,
     * connessione morta — lascerebbe morta anche la transazione
     * dell'applicazione che ci ha chiamati, trasformando un log mancato in un
     * guasto vero. Col savepoint il rollback torna al punto e il chiamante
     * prosegue.
     *
     * ⚠️ **E resta un limite dichiarato**: se il chiamante è dentro una
     * transazione **già** abortita — perché l'eccezione da registrare *è* la
     * query che l'ha abortita, e l'ha catturata proseguendo — nemmeno il
     * `SAVEPOINT` si può aprire, e l'errore non viene registrato. Serve una
     * seconda connessione per uscirne, che qui non si apre: la si pagherebbe a
     * ogni eccezione per un caso che il flusso normale (eccezione → rollback →
     * report) non produce.
     */
    private static function registra(Throwable $e): void
    {
        [$impronta, $file, $riga] = self::identifica($e::class, self::posizioni($e));

        $adesso = now();
        $connessione = (new Errore)->getConnection();

        $connessione->transaction(function () use ($e, $impronta, $file, $riga, $adesso, $connessione): void {
            $trova = fn (): ?Errore => Errore::query()->where('impronta', $impronta)->first();

            $errore = $trova();

            if ($errore === null) {
                try {
                    // Savepoint suo, dentro quello esterno: la collisione
                    // sull'unique deve poter essere ripulita **senza** portarsi
                    // via la SELECT di recupero qui sotto.
                    $connessione->transaction(fn () => Errore::create([
                        'impronta' => $impronta,
                        // `classe` e `file` sono varchar(255). Una classe
                        // anonima ci arriva vicino, e su Postgres un varchar
                        // troppo corto è un **errore**, non un troncamento: lo
                        // inghiottirebbe il catch di `cattura()` e la issue non
                        // esisterebbe. È la lezione già pagata su `user_agent`.
                        'classe' => mb_substr($e::class, 0, 255),
                        'messaggio' => $e->getMessage(),
                        'file' => mb_substr($file, 0, 255),
                        'riga' => $riga,
                        'prima_occorrenza_at' => $adesso,
                        'ultima_occorrenza_at' => $adesso,
                    ]));

                    // Issue nuova: `occorrenze` nasce a 1 dal default del model,
                    // quindi qui non si incrementa niente. Il ramo dell'alert
                    // (blocco 8) si aggancerà a questo `return`.
                    return;
                } catch (QueryException $collisione) {
                    // Corsa persa: l'unique ha fatto il suo lavoro e la riga ora
                    // c'è. Si rilegge invece di ispezionare lo SQLSTATE, che
                    // differisce fra i driver (23505 su Postgres, «UNIQUE
                    // constraint failed» su SQLite). Se non si trova nulla,
                    // l'errore non era una collisione e va rilanciato — a
                    // raccoglierlo c'è il catch di `cattura()`.
                    $errore = $trova() ?? throw $collisione;
                }
            }

            self::incrementa($errore, $adesso);
        });
    }

    /**
     * Il contatore, e la **riapertura automatica**.
     *
     * ⚠️ **`increment()` e non una lettura seguita da una scrittura**: compila
     * `occorrenze = occorrenze + 1` **nel database**, quindi due processi che
     * incrementano insieme fanno +2. Con `$errore->occorrenze + 1` calcolato in
     * PHP ne perderebbero uno, e il contatore mentirebbe proprio sugli errori
     * più frequenti — quelli per cui la cifra conta.
     *
     * **La riapertura non è il gesto di una persona e non scrive audit.** Il
     * registro racconta chi ha fatto cosa; qui non c'è nessun chi. Il fatto
     * resta leggibile in `riaperto_automaticamente_at`, che è anche ciò a cui il
     * secondo alert si aggancerà (blocco 8).
     *
     * ⚠️ **`ignorato` non si riapre mai.** È l'unico interruttore di silenzio del
     * tracker: se una issue ignorata tornasse «aperta» alla prima occorrenza
     * successiva, ignorare non vorrebbe dire niente. Il contatore cresce lo
     * stesso — la riga continua a dire la verità su quante volte succede.
     *
     * ⚠️ **La riapertura azzera anche il budget dei contesti**, che oggi nessuno
     * spende ancora: senza, dopo un tentativo di correzione la issue sarebbe già
     * al cap e non catturerebbe **mai più** la prova che serve a rispondere a
     * «l'ho corretto, perché succede ancora?». Sta qui e non nel blocco che
     * introduce il campionamento perché è una proprietà della *riapertura*, e
     * lasciarla a dopo significa affidarla a un ricordo.
     */
    private static function incrementa(Errore $errore, CarbonInterface $adesso): void
    {
        $altre = ['ultima_occorrenza_at' => $adesso];

        if ($errore->stato === 'risolto') {
            $altre += [
                'stato' => 'aperto',
                'riaperto_automaticamente_at' => $adesso,
                'risolto_at' => null,
                'risolto_da' => null,
                'contesti' => 0,
                'ultimo_contesto_at' => null,
            ];
        }

        Errore::query()->whereKey($errore->getKey())->increment('occorrenze', 1, $altre);
    }
}
