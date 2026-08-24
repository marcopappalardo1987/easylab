<?php

namespace App\Support\Errori;

use App\Jobs\InviaAllertaErrore;
use App\Models\Errore;
use App\Models\OccorrenzaErrore;
use App\Notifications\NuovoErrore;
use App\Support\ChiaviSensibili;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * 🔴 La cattura dell'error tracker interno (S6 — 🔗 `docs/Architettura/Error
 * Tracker Interno (piano).md`, 🔗 ADR-017).
 *
 * Agganciata a `$exceptions->report()` in `bootstrap/app.php`, scrive **una
 * riga in `errori`** per ogni punto d'origine: la prima volta la crea, dalla
 * seconda in poi incrementa il contatore. E, **a campione**, una riga in
 * `occorrenze_errore` col contesto dell'avvenimento — stack trace, percorso,
 * input, chi c'era.
 *
 * ⚠️ **Le due cifre non sono la stessa cosa**: `errori.occorrenze` conta *tutti*
 * gli avvenimenti, `errori.contesti` quante prove se ne sono conservate (al più
 * `contesti_per_errore`, non più di una ogni `finestra_contesto_secondi`). La
 * pagina dovrà dirle insieme, o la seconda si legge come la prima.
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
 * si deriva contando le occorrenze già salvate: la decisione «conservo il
 * contesto?» si legge sulla riga **che si è appena caricata**, e costa zero.
 *
 * Quando invece il contesto si conserva le query diventano quattro (l'INSERT
 * dell'occorrenza e l'UPDATE della contabilità), ed è un costo **limitato per
 * costruzione** — al più venti volte per issue, e non più di una al minuto.
 * L'errore in loop caldo, cioè il solo caso in cui il costo conterebbe, è
 * esattamente quello che il campionamento riporta a due.
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
     * Quanti frame di stack trace si conservano.
     *
     * Non è una misura di spazio — la colonna è `text` — ma di leggibilità: sotto
     * il cinquantesimo frame c'è il dispatcher del framework, che è identico in
     * ogni occorrenza di ogni issue e non ha mai spiegato niente a nessuno.
     */
    private const FRAMI = 50;

    /** Il `metodo` di un'occorrenza nata fuori da HTTP: non c'è un verbo. */
    private const CLI = 'CLI';

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

        // ⚠️ **La transazione restituisce una COPPIA, e il secondo elemento non
        // è una comodità**: «questa issue merita un alert» è una cosa che si sa
        // solo *dentro* i due rami qui sotto — è nata adesso, oppure
        // `incrementa()` l'ha riaperta — e fuori non si può più ricostruire
        // senza rileggere la riga. Dedurlo da `riaperto_automaticamente_at`
        // sarebbe per giunta **sbagliato**: quella colonna resta valorizzata
        // anche alle mille occorrenze successive alla riapertura, e l'alert
        // partirebbe a ogni avvenimento.
        [$errore, $daAllertare] = $connessione->transaction(function () use ($e, $impronta, $file, $riga, $adesso, $connessione): array {
            $trova = fn (): ?Errore => Errore::query()->where('impronta', $impronta)->first();

            $errore = $trova();

            if ($errore === null) {
                try {
                    // Savepoint suo, dentro quello esterno: la collisione
                    // sull'unique deve poter essere ripulita **senza** portarsi
                    // via la SELECT di recupero qui sotto.
                    //
                    // ⚠️ **L'istanza creata si tiene**, e non è una comodità: è
                    // ciò da cui il campionamento legge `contesti` e
                    // `ultimo_contesto_at` senza spendere una terza query. Con
                    // un `create()` scartato bisognerebbe rileggere la riga
                    // appena scritta per sapere ciò che si è appena scritto.
                    $nata = $connessione->transaction(fn (): Errore => Errore::create([
                        'impronta' => $impronta,
                        // `classe` e `file` sono varchar(255). Una classe
                        // anonima ci arriva vicino, e su Postgres un varchar
                        // troppo corto è un **errore**, non un troncamento: lo
                        // inghiottirebbe il catch di `cattura()` e la issue non
                        // esisterebbe. È la lezione già pagata su `user_agent`.
                        'classe' => mb_substr($e::class, 0, 255),
                        'messaggio' => self::testo(self::messaggio($e)) ?? '',
                        'file' => mb_substr($file, 0, 255),
                        'riga' => $riga,
                        'prima_occorrenza_at' => $adesso,
                        'ultima_occorrenza_at' => $adesso,
                    ]));

                    // Issue nuova: `occorrenze` nasce a 1 dal default del model,
                    // quindi qui non si incrementa niente. È il primo dei due
                    // casi che meritano un alert — l'altro è la riapertura.
                    return [$nata, true];
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

            return [$errore, self::incrementa($errore, $adesso)];
        });

        // Il contesto sta **fuori** dalla transazione della contabilità, e ha la
        // propria (vedi `contesto()`): la issue e il suo contatore sono il dato
        // che non si può perdere, il contesto è una prova in più. Un guasto qui
        // — una colonna troppo corta, un JSON che non si serializza — non deve
        // portarsi via anche il fatto che l'errore è successo.
        self::contesto($errore, $e, $adesso);

        // ⚠️ **L'alert per ULTIMO, e fuori da ogni transazione.** In coda
        // `sync` — che è la configurazione di sviluppo e quella dei test — il
        // `notify()` invia davvero, quindi un SMTP che rifiuta lancia proprio
        // qui: dentro la transazione della contabilità si porterebbe via la
        // issue, prima di `contesto()` si porterebbe via la prova. In fondo,
        // l'unica cosa che si perde è l'email, e a raccogliere il guasto c'è il
        // catch di `cattura()`.
        if ($daAllertare) {
            self::allerta($errore);
        }
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
     * secondo alert si aggancia — vedi `allerta()`.
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
     *
     * @return bool la issue è stata **riaperta adesso** — cioè il secondo dei
     *              due casi che fanno partire un alert. Si restituisce invece
     *              di rileggerlo dopo dalla riga, perché
     *              `riaperto_automaticamente_at` dice «l'ultima riapertura è
     *              stata automatica», non «è avvenuta in questa chiamata».
     */
    private static function incrementa(Errore $errore, CarbonInterface $adesso): bool
    {
        $altre = ['ultima_occorrenza_at' => $adesso];
        $riaperta = $errore->stato === 'risolto';

        if ($riaperta) {
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

        // ⚠️ **E l'istanza in memoria si allinea**, o il campionamento
        // deciderebbe sul budget vecchio: subito dopo una riapertura `$errore`
        // direbbe ancora `contesti = 20` mentre a database ce ne sono 0, e la
        // prima occorrenza dopo il tentativo di correzione — cioè **la** prova
        // per cui l'azzeramento esiste — non verrebbe conservata. `syncOriginal()`
        // perché questa istanza non va mai salvata: la scrittura è già avvenuta,
        // qui si sta solo raccontando alla copia in memoria ciò che il database
        // ha fatto.
        $errore->forceFill($altre)->syncOriginal();

        return $riaperta;
    }

    /**
     * 🔴 L'**allerta**: l'email che fa sapere che qualcosa si è rotto (🔗 ADR-017,
     * Privacy §T8).
     *
     * Parte **solo** su issue nuova o riapertura automatica, cioè sui due fatti
     * che non si ripetono: dalla seconda occorrenza in poi la riga cresce e la
     * casella tace. È il motivo per cui cinquantamila occorrenze dello stesso
     * bug producono **una** email.
     *
     * ⚠️ **Una issue `ignorato` non passa mai di qui**, e non serve una guardia
     * sua: `incrementa()` riapre soltanto i `risolto`, quindi lo stato zittito
     * non produce né il ramo «nuova» né il ramo «riaperta». Il silenzio di
     * `ignora()` vale quindi anche per la posta, che è dove contava di più — ed
     * è anche la ragione per cui il blocco 7 non pota quello stato: una issue
     * ignorata e potata rinascerebbe `aperto`, e l'alert tornerebbe da sé.
     *
     * ⚠️ **Il contenuto non si compone qui.** Questa funzione passa alla
     * notifica quattro scalari — classe, `file:riga`, contatore, id — e la
     * regola su *cosa* non deve uscire (messaggio, stack trace, input, utente)
     * vive nel docblock di `NuovoErrore`, accanto al corpo dell'email. Due sedi
     * per la stessa regola sarebbero due sedi da cui divergere.
     */
    private static function allerta(Errore $errore): void
    {
        $a = self::destinatario();

        // Nessuna casella configurata: il tracker resta **muto senza
        // lamentarsi**. Registrare gli errori ha valore anche senza email, e
        // un'eccezione sollevata qui per una config vuota violerebbe la regola
        // 1 del docblock di classe.
        if ($a === null || ! self::prenotaAlert($errore)) {
            return;
        }

        // ⚠️ **Un job nostro, non la notifica accodata direttamente.** Il
        // perché sta nel docblock di `InviaAllertaErrore`, e in una riga: il
        // mittente di Laravel **rilancia** dopo aver segnalato il fallimento,
        // quindi l'eccezione esce dal job e `Worker::runJob()` la **riporta** —
        // cioè chiama questo stesso aggancio, in un processo dove la guardia di
        // rientranza non c'è. Un alert fallito diventava un errore nuovo.
        InviaAllertaErrore::dispatch($a, new NuovoErrore(
            classe: $errore->classe,
            // Già relativo alla radice: `identifica()` lo ha reso tale prima di
            // scriverlo, ed è ciò che rende la riga leggibile dopo un deploy.
            posizione: $errore->file.':'.$errore->riga,
            occorrenze: (int) $errore->occorrenze,
            erroreId: (int) $errore->getKey(),
            // ⚠️ **`wasRecentlyCreated` distingue i due casi senza una seconda
            // colonna né un secondo parametro**: è `true` solo sull'istanza
            // uscita da `Errore::create()`, e la issue riaperta arriva invece
            // da una `first()`. Dedurlo da `riaperto_automaticamente_at`
            // direbbe «riaperta» anche a una issue nata risolta e riaperta un
            // anno fa.
            riaperto: ! $errore->wasRecentlyCreated,
        ));
    }

    /**
     * Il posto in cui l'alert **si prenota**: il cap giornaliero e il timbro,
     * insieme.
     *
     * ⚠️ **Il cap è globale, non per-issue**, ed è la sola forma che protegga da
     * ciò che deve proteggere: la tempesta che riempie una casella non è un
     * errore che si ripete — quello manda **una** email e basta — è un deploy
     * sbagliato che genera venti issue *diverse*. Un contatore denormalizzato
     * sulla riga non saprebbe nulla delle altre righe; da qui il `count()` su
     * `alert_inviato_at` di oggi, che è la ragione per cui quella colonna esiste
     * (lo dice già la migration).
     *
     * ⚠️ **Il timbro si scrive PRIMA dell'invio, e non dopo.** Non è ottimismo:
     * `alert_inviato_at` è ciò che il cap conta, e scriverlo dopo un `notify()`
     * che sotto coda vera ritorna **prima** della consegna significherebbe
     * contarlo mai o contarlo in ritardo. Il verso sbagliato costa una casella
     * intasata; questo costa, nel caso peggiore, un alert perso — e il fatto
     * resta comunque scritto in `errori`, che è il canale primario.
     *
     * ⚠️ **`now()` e non l'istante dell'eccezione**: la colonna dice quando
     * l'alert è **partito**, non quando l'errore è avvenuto — per quello ci
     * sono le due colonne di occorrenza. Il «giorno» del cap è quello del fuso
     * dell'applicazione: è una strozzatura di frequenza, non un rendiconto.
     *
     * ⚠️ **`DB::transaction()` attorno alle due query** per la ragione già
     * scritta due volte in questa classe: su Postgres una query fallita aborta
     * l'**intera** transazione (25P02), e senza savepoint un cap che non si
     * riesce a leggere lascerebbe morta la transazione del chiamante — cioè
     * trasformerebbe un'email mancata in un guasto vero.
     */
    private static function prenotaAlert(Errore $errore): bool
    {
        $adesso = now();

        return $errore->getConnection()->transaction(function () use ($errore, $adesso): bool {
            $oggi = Errore::query()
                ->where('alert_inviato_at', '>=', $adesso->copy()->startOfDay())
                ->count();

            // `>=` e non `>`: `alert_max_giornalieri` è «quante se ne mandano»,
            // non «dopo quante si smette». Stessa disciplina del tetto dei
            // contesti in `daCampionare()`.
            if ($oggi >= (int) config('easylab.errori.alert_max_giornalieri')) {
                return false;
            }

            // ⚠️ **Update sul query builder, non `forceFill()->save()`**: qui non
            // si sta compiendo un gesto sulla issue (quelli stanno sul model e
            // scrivono audit), si sta segnando che la posta è partita. Una
            // `save()` riscriverebbe per giunta l'intera istanza in memoria
            // sopra ciò che la transazione del contatore ha appena committato.
            Errore::query()->whereKey($errore->getKey())->update(['alert_inviato_at' => $adesso]);

            return true;
        });
    }

    /**
     * A chi va l'alert: la casella configurata, o — se non c'è — il Developer.
     *
     * 🔴 **Mai una query su `users`**, per quanto «l'email del Developer» sia un
     * dato che a database c'è. Questo codice gira **dentro il gestore delle
     * eccezioni**: una query aggiuntiva fallirebbe proprio nei casi in cui
     * l'alert serve di più — database irraggiungibile, connessione morta,
     * transazione del chiamante già abortita — e in quelli in cui il guasto *è*
     * l'autenticazione. La config si legge dalla memoria del processo e non
     * può fallire.
     *
     * ⚠️ **Il fallback ha un default in `config/easylab.php`**
     * (`DEVELOPER_EMAIL`, con un valore anche senza variabile), quindi in
     * pratica il ramo «nessun destinatario» non si raggiunge per dimenticanza:
     * scordarsi `ERRORI_ALERT_EMAIL` non spegne gli alert, li manda al
     * Developer. Il ramo muto resta perché la casella si può **svuotare
     * apposta**, ed è l'unico modo di zittire la posta senza spegnere il
     * tracker.
     *
     * ⚠️ Il destinatario è una **casella**, che non ha né permesso né registro
     * di audit: è dichiarato in Privacy §T8 e vincola il contenuto dell'email
     * (🔗 `NuovoErrore`), non questa riga.
     */
    private static function destinatario(): ?string
    {
        $candidati = [
            config('easylab.errori.alert_email'),
            config('easylab.piattaforma.developer.email'),
        ];

        foreach ($candidati as $candidato) {
            if (is_string($candidato) && trim($candidato) !== '') {
                return trim($candidato);
            }
        }

        return null;
    }

    /**
     * 🔴 Il **contesto** di un avvenimento: stack trace, dove stava succedendo,
     * chi c'era, con quali dati. È la riga che risponde a «con quali input si
     * rompe», e la sola del tracker che porta dati personali in quantità.
     *
     * ## Un campione, non un registro
     *
     * Al più `contesti_per_errore` righe per issue, e non più di una ogni
     * `finestra_contesto_secondi`. La finestra è la guardia contro il loop caldo:
     * senza, un errore che scatta mille volte al minuto spenderebbe l'intero
     * budget in un secondo, e lo spenderebbe **tutto sullo stesso istante** —
     * venti copie della stessa fotografia invece di venti fotografie.
     *
     * La contabilità si legge dalla riga `Errore` **già caricata**: zero query
     * per decidere, che è la ragione per cui quelle due colonne stanno lì e non
     * si ricavano contando `occorrenze_errore`.
     *
     * ## La transazione, e perché è sua
     *
     * Su Postgres una query fallita aborta l'**intera** transazione (25P02):
     * senza savepoint, un contesto che non si riesce a scrivere lascerebbe morta
     * la transazione del chiamante — cioè trasformerebbe una prova mancata in un
     * guasto vero. È la stessa ragione già scritta su `registra()`, un gradino
     * più in là.
     */
    private static function contesto(Errore $errore, Throwable $e, CarbonInterface $adesso): void
    {
        if (! self::daCampionare($errore, $adesso)) {
            return;
        }

        // ⚠️ **L'ambiente si decide PRIMA di leggere la richiesta**, perché fuori
        // da HTTP `request()` non è assente: è una `Request` **fabbricata** da
        // `$_SERVER`, che dice `GET`, percorso `/`, ip `127.0.0.1` e user agent
        // `Symfony`. Verificato in console. Salvarla darebbe a ogni errore di un
        // comando o di un job un contesto HTTP inventato di sana pianta — dati
        // falsi su una riga che esiste per dire la verità su cosa è successo.
        $ambiente = self::ambiente();
        $richiesta = $ambiente === 'http' ? request() : null;

        [$utente, $impersonatore] = self::attori();

        $errore->getConnection()->transaction(function () use ($errore, $e, $adesso, $ambiente, $richiesta, $utente, $impersonatore): void {
            OccorrenzaErrore::create([
                'errore_id' => $errore->getKey(),
                'messaggio' => self::testo(self::messaggio($e)) ?? '',
                'stack_trace' => self::traccia($e),
                'percorso' => self::testo(self::percorso($richiesta)) ?? '',
                'metodo' => $richiesta?->method() ?? self::CLI,
                'codice_http' => $richiesta === null ? null : self::codice($e),
                'user_id' => $utente,
                'impersonato_da' => $impersonatore,
                'ip' => $richiesta?->ip(),
                'user_agent' => self::testo($richiesta?->userAgent()),
                'input' => $richiesta === null ? null : self::input($richiesta),
                'contesto' => $ambiente,
                'avvenuta_at' => $adesso,
            ]);

            // `increment()` e non `$errore->contesti + 1` calcolato in PHP: la
            // ragione è la stessa del contatore delle occorrenze, e qui in più
            // il valore è un **budget** — sovrastimarlo spegne il campionamento
            // in anticipo, sottostimarlo lo lascia correre oltre il tetto
            // dichiarato in T8.
            Errore::query()
                ->whereKey($errore->getKey())
                ->increment('contesti', 1, ['ultimo_contesto_at' => $adesso]);
        });
    }

    /**
     * Si conserva il contesto di questo avvenimento?
     *
     * Due condizioni, e vanno **entrambe** soddisfatte: il budget della issue non
     * è esaurito, ed è passata abbastanza dall'ultima prova conservata.
     *
     * ⚠️ **`>=` sul tetto e non `>`**: `contesti_per_errore` è «quante se ne
     * conservano», non «dopo quante si smette». Con `>` la ventunesima passerebbe.
     */
    private static function daCampionare(Errore $errore, CarbonInterface $adesso): bool
    {
        if ($errore->contesti >= (int) config('easylab.errori.contesti_per_errore')) {
            return false;
        }

        $ultimo = $errore->ultimo_contesto_at;

        // `null` è la issue appena nata (o appena riaperta): la prima prova si
        // prende sempre, ed è quella che vale di più.
        if ($ultimo === null) {
            return true;
        }

        // `true` come secondo argomento: la differenza in **valore assoluto**.
        // Un orologio che torna indietro — l'ora legale, un `travel()` di un
        // test, due repliche non sincronizzate — darebbe altrimenti un negativo,
        // che è sempre `< finestra`: il campionamento si spegnerebbe fino a
        // quando il tempo non ha recuperato.
        return $ultimo->diffInSeconds($adesso, true) >= (int) config('easylab.errori.finestra_contesto_secondi');
    }

    /**
     * 🔴 Lo stack trace **ricostruito dai frame**, senza gli argomenti.
     *
     * ⚠️ **E la strada che sembrava più prudente è quella che perde di più.**
     * `getTraceAsString()` sembra la scelta ovvia e sicura perché tronca gli
     * argomenti a 15 caratteri: è il contrario di ciò che serve. Tronca, ma
     * **stampa** — verificato, una password di 23 caratteri esce con i suoi primi
     * 15 in chiaro, che di una password sono più che abbastanza per essere
     * riconosciuta, e di un codice di recupero 2FA sono la metà. `getTrace()`
     * restituisce invece gli argomenti **integri**, oggetti vivi compresi: da
     * soli, i frame sono la strada peggiore delle due.
     *
     * Ciò che rende buona questa strada è **`unset($frame['args'])`**, che è la
     * sola riga di questo metodo che conti dal punto di vista della privacy.
     *
     * ⚠️ **E non ci si affida a `zend.exception_ignore_args`.** `php.ini-production`
     * la distribuisce a `On`, e dove è attiva gli argomenti non ci sono nemmeno.
     * Ma su Herd è `Off` — misurato, `ini_get()` dice `'0'` — quindi in sviluppo
     * la perdita esisterebbe eccome, e su Laravel Cloud va **letto lì**, non
     * dedotto. Una protezione che dipende da un'ini di cui non si conosce il
     * valore non è una protezione.
     *
     * ⚠️ **Perché il residuo del frame viene reso, invece di leggere solo le
     * chiavi attese.** Con un'allowlist (`file`, `line`, `class`, `type`,
     * `function`) l'`unset()` sarebbe **decorativo**: togliendolo non cambierebbe
     * un carattere dell'output, e il test di mutazione che deve tenerlo in piedi
     * resterebbe verde su codice rotto — cioè la guardia più importante del
     * blocco sarebbe l'unica senza rete. Rendendo ciò che resta del frame,
     * `unset()` diventa la sola cosa fra `args` e il database, e togliendolo il
     * test diventa rosso. Il prezzo dichiarato è che una chiave nuova nei frame
     * di PHP finirebbe qui: i frame di `Throwable::getTrace()` ne hanno sei
     * (`file`, `line`, `function`, `class`, `type`, `args`) e `object` non
     * compare mai — è di `debug_backtrace()` con `DEBUG_BACKTRACE_PROVIDE_OBJECT`,
     * che qui non passa. Si preferisce una guardia falsificabile a una guardia
     * elegante.
     */
    private static function traccia(Throwable $e): string
    {
        // La prima riga dice **dov'è nata** l'eccezione (la riga del `throw`),
        // che `getTrace()[0]` non dice: quello è già il chiamante. È la stessa
        // ragione per cui `posizioni()` mette `getFile()` davanti al trace.
        $righe = [
            // ⚠️ `self::messaggio()` e non `getMessage()`: la prima riga del
            // trace ricopia il messaggio, quindi senza questa i binding di una
            // `QueryException` rientrerebbero da qui dopo essere stati tolti
            // dalla colonna. È la stessa fuga, un centimetro più in là.
            $e::class.': '.self::messaggio($e),
            'origine '.$e->getFile().':'.$e->getLine(),
        ];

        foreach (array_slice($e->getTrace(), 0, self::FRAMI) as $n => $frame) {
            // 🔴 **La riga che conta.** Vedi il docblock: senza, qui dentro
            // finiscono gli argomenti **integri** di ogni chiamata sullo stack —
            // password, codici di recupero, oggetti vivi.
            unset($frame['args']);

            $posizione = isset($frame['file'], $frame['line'])
                ? $frame['file'].':'.$frame['line']
                // Funzione interna, `call_user_func`, closure di un handler: il
                // frame non ha una posizione, e dirlo è meglio che saltarlo —
                // altrimenti la numerazione mentirebbe sulla profondità.
                : '[interno]';

            $chiamata = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '').'()';

            unset($frame['file'], $frame['line'], $frame['class'], $frame['type'], $frame['function']);

            // Il residuo: vuoto su ogni versione di PHP conosciuta, e **pieno di
            // argomenti** se qualcuno togliesse l'`unset()` qui sopra. È ciò che
            // rende quella riga una guardia invece di un'intenzione.
            $residuo = $frame === [] ? '' : ' '.self::json($frame);

            $righe[] = '#'.$n.' '.$posizione.' '.$chiamata.$residuo;
        }

        // ⚠️ **La radice si toglie dalla stringa intera, non percorso per
        // percorso**, e la ragione è che i percorsi assoluti non stanno solo in
        // `file`. Verificato su PHP 8.4: il nome di una closure è
        // `{closure:/percorso/assoluto/File.php:21}` e quello di una classe
        // anonima `class@anonymous/percorso/assoluto/File.php:12$0` — cioè la
        // directory di deploy rientra dalla chiave `function` e dalla chiave
        // `class`, dove nessuno la cercherebbe. Su Laravel Cloud ogni release
        // vive in una directory diversa, quindi senza questa riga metà dei frame
        // di ogni trace sarebbe illeggibile il giorno dopo.
        return self::testo(str_replace(base_path().'/', '', implode("\n", $righe))) ?? '';
    }

    /**
     * L'input della richiesta, ripulito dalle chiavi sensibili **in scrittura**.
     *
     * 🔴 **`input()` e non `all()`**, e i due differiscono in un punto che conta:
     * `all()` è `input() + allFiles()`, quindi porta dentro gli `UploadedFile`.
     * Un oggetto attraversato dalla ripulitura ricorsiva si apre nei propri campi
     * **privati**, e PHP prefissa le chiavi dei campi privati con un byte **NUL**
     * — che su Postgres non è un carattere qualunque: `text` e `json` lo
     * rifiutano o lo troncano, e la riga intera diventa insalvabile. Il contenuto
     * di un file caricato non è comunque contesto: è il file.
     *
     * ⚠️ **`input()` include la query string**, ed è voluto: è da lì che
     * `signature` ed `expires` di una rotta firmata rientrano dopo essere state
     * escluse dal percorso. La denylist le prende — ed è la ragione per cui il
     * test asserisce **sul valore** e non sulla presenza della chiave.
     *
     * @return array<array-key, mixed>|null
     */
    private static function input(Request $richiesta): ?array
    {
        $pulito = ChiaviSensibili::ripulisci($richiesta->input());

        if (! is_array($pulito) || $pulito === []) {
            return null;
        }

        // ⚠️ **Un byte UTF-8 malformato rende insalvabile l'INTERA riga**, non
        // solo la colonna: il cast `array` di Eloquent serializza con
        // `json_encode()` nudo, che su input non valido lancia
        // `JsonEncodingException` — e a raccoglierla c'è il catch di `cattura()`,
        // che scarterebbe **tutto** il contesto (stack trace compreso) per un
        // byte arrivato da un client. Il giro di andata e ritorno normalizza qui,
        // dove si può ancora scegliere cosa perdere.
        $json = self::json($pulito);

        // `\u0000` non è UTF-8 malformato — è legale in JSON — ma su Postgres il
        // NUL è comunque rifiutato in estrazione. Si toglie dalla forma
        // serializzata, che è l'unico punto in cui è un pattern e non un byte.
        $riletto = json_decode(str_replace('\\u0000', '', $json), true);

        return is_array($riletto) && $riletto !== [] ? $riletto : null;
    }

    /**
     * JSON che non lancia mai, per un gestore di eccezioni.
     *
     * `JSON_INVALID_UTF8_SUBSTITUTE` sostituisce i byte malformati con `U+FFFD`
     * invece di far fallire l'intera codifica, e `JSON_PARTIAL_OUTPUT_ON_ERROR`
     * copre ciò che resta (ricorsione, `INF`/`NAN`): meglio un contesto monco che
     * nessun contesto.
     *
     * @param  array<array-key, mixed>  $valore
     */
    private static function json(array $valore): string
    {
        return (string) json_encode(
            $valore,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Chi ha subito l'errore, e chi stava **davvero** agendo.
     *
     * 🔴 `user_id` è l'utente autenticato — cioè, durante un'impersonazione,
     * l'**impersonato** — e `impersonato_da` è l'impersonatore. È lo stesso verso
     * del timbro che `AppServiceProvider` mette su ogni riga di audit
     * (`properties.impersonato_da`), e invertirlo attribuirebbe al cliente un
     * errore incontrato da EasyLab: un dato falso su una persona, non un dettaglio
     * di rendering.
     *
     * ⚠️ **Niente `rescue()`**, per la regola 1 del docblock di classe: `rescue()`
     * chiama `report()`. Il `try` nudo qui dentro serve perché la sessione può non
     * esserci affatto (console, coda) e perché `auth()` durante un guasto
     * dell'autenticazione è l'ultimo posto da cui farsi rilanciare un'eccezione:
     * senza il nome di chi c'era il contesto vale ancora, senza il contesto no.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private static function attori(): array
    {
        try {
            $utente = auth()->id();

            $impersonatore = app()->bound('impersonate') && app('impersonate')->isImpersonating()
                ? app('impersonate')->getImpersonatorId()
                : null;

            return [$utente, $impersonatore];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * `http` | `console` | `coda`. Dice **come leggere** le colonne accanto: fuori
     * da `http`, `percorso` è il comando e `codice_http` è null.
     *
     * ⚠️ **`runningInConsole()` da sola non basta, e la ragione morderebbe proprio
     * i test di questo blocco.** Legge il SAPI, e sotto PHPUnit il SAPI è `cli`
     * **anche per una richiesta HTTP simulata**: `$this->post(...)` risulterebbe
     * `console`, cioè ogni test che prova la sanificazione dell'input starebbe
     * provando il ramo sbagliato — e sarebbe verde, perché in quel ramo l'input
     * non si salva affatto. La seconda condizione è la **rotta risolta**: esiste
     * solo se il router ha instradato una richiesta vera.
     *
     * ⚠️ Limite dichiarato: un'eccezione lanciata **prima** dell'instradamento
     * (middleware globale) dentro la suite si classifica `console`. In esecuzione
     * vera no — là `runningInConsole()` è già falso — quindi il limite vive solo
     * nei test, dove si nota.
     */
    private static function ambiente(): string
    {
        if (! app()->runningInConsole()) {
            return 'http';
        }

        if (app()->runningConsoleCommand('queue:work', 'queue:listen')) {
            return 'coda';
        }

        return request()->route() !== null ? 'http' : 'console';
    }

    /**
     * Il comando in esecuzione, per le occorrenze fuori da HTTP.
     *
     * 🔴 **Il nome del comando, mai i suoi argomenti.** `easylab:abbona --token=…`
     * scriverebbe un segreto in `percorso`, che è una colonna di testo libero su
     * cui la denylist dell'input non passa: la riga di comando è un canale che
     * porta credenziali per mestiere. Si tiene la sola prima parola, e la si
     * scarta se comincia per `-` (flag globale, nessun comando).
     */
    /**
     * Il percorso della richiesta, **senza i valori dei parametri di rotta**.
     *
     * 🔴 Chiudere `fullUrl()` e mettere `signature` nella denylist non bastava:
     * i segreti stanno anche **dentro il percorso**. Questo progetto ha davvero
     * `reset-password/{token}` (Fortify), `q/{token}` (QR, ADR-003) e
     * `email/verify/{id}/{hash}`: con `path()` un'eccezione su quelle pagine
     * scriveva un **gettone vivo in chiaro** in una colonna conservata mesi,
     * dietro il gate che nessuno può ispezionare tranne il Developer — cioè
     * esattamente la fuga che questo blocco esiste per impedire, per la strada
     * che nessuno aveva provato.
     *
     * Si salva perciò lo **schema** della rotta (`reset-password/{token}`) e non
     * il percorso risolto. Non è solo più sicuro: raggruppa meglio, perché mille
     * gettoni diversi diventano una riga sola invece di mille.
     *
     * Il fallback su `path()` vale quando **nessuna rotta ha corrisposto** — un
     * 404, o un'eccezione prima del routing: lì non c'è uno schema da usare, e
     * il percorso grezzo è ciò che resta. È anche il caso in cui un segreto
     * nell'URL è meno probabile, perché nessuna rotta lo ha dichiarato.
     */
    private static function percorso(?Request $richiesta): string
    {
        if ($richiesta === null) {
            return self::comando();
        }

        return $richiesta->route()?->uri() ?? $richiesta->path();
    }

    /**
     * Il messaggio dell'eccezione, **senza i valori dei binding**.
     *
     * 🔴 `QueryException` costruisce il proprio messaggio interpolando i binding
     * nell'SQL (`Str::replaceArray('?', $bindings, $sql)`): **ogni valore di ogni
     * query fallita** finisce nel messaggio, e il messaggio non passa da nessuna
     * denylist. Verificato con una query su `recovery_code`: il codice usciva in
     * tre colonne, fra cui `errori.messaggio`, che si scrive una volta sola, non
     * si campiona e vive quanto la issue.
     *
     * Non si taglia la coda `, SQL: …`, perché quella è la cosa più utile che un
     * tracker di errori possa dire: si **ricostruisce** dall'SQL coi segnaposto,
     * che `getSql()` restituisce già senza valori. Diagnostica intatta, segreti
     * fuori.
     */
    private static function messaggio(Throwable $e): string
    {
        if (! $e instanceof QueryException) {
            return $e->getMessage();
        }

        return sprintf(
            '%s (Connection: %s, SQL: %s)',
            $e->getPrevious()?->getMessage() ?? 'Query fallita',
            $e->getConnectionName(),
            $e->getSql(),
        );
    }

    private static function comando(): string
    {
        $primo = $_SERVER['argv'][1] ?? null;

        return is_string($primo) && $primo !== '' && ! str_starts_with($primo, '-')
            ? $primo
            : 'artisan';
    }

    /**
     * Il codice di stato dell'occorrenza.
     *
     * `500` di default perché un'eccezione **riportata** è, per la lista ereditata
     * da `shouldntReport()`, ciò che non è una `HttpException`. L'`instanceof`
     * resta per le eccezioni riportate a mano da un `try/catch` applicativo, che
     * possono benissimo portare un codice proprio.
     */
    private static function codice(Throwable $e): int
    {
        return $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
    }

    /**
     * Testo pronto per una colonna di Postgres.
     *
     * ⚠️ **Il byte NUL non è un carattere qualunque**: Postgres lo rifiuta in
     * `text` (`invalid byte sequence for encoding "UTF8": 0x00`), e sotto SQLite
     * tronca in silenzio il valore al primo NUL. Ci arriva dai nomi delle classi
     * anonime (`class@anonymous\0/percorso/File.php:12$0`), che in uno stack trace
     * capitano eccome — ogni closure di un provider, ogni `new class` di un test.
     * Su un percorso che gira dentro il gestore delle eccezioni il guasto
     * sarebbe muto: il catch di `cattura()` lo inghiottirebbe e la issue
     * resterebbe senza contesti, indistinguibile dal campionamento.
     */
    private static function testo(?string $valore): ?string
    {
        return $valore === null ? null : str_replace("\0", '', $valore);
    }
}
