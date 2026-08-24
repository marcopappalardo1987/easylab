<?php

namespace App\Models;

use App\Support\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una **issue** dell'error tracker interno (S6 — 🔗 `docs/Architettura/Error
 * Tracker Interno (piano).md`; ERD: tabella di piattaforma, fuori dagli Enti).
 *
 * Un errore raggruppato per punto d'origine: `impronta` è la chiave del
 * raggruppamento, `occorrenze` quante volte è successo, `contesti` quante prove
 * se ne sono conservate in `occorrenze_errore`. Le due cifre non sono la stessa
 * cosa e non vanno mai mostrate come se lo fossero.
 *
 * **Modello di PIATTAFORMA: niente `BelongsToTenant`**, come `Account`
 * (ADR-032). Un'eccezione PHP non appartiene a un Ente — nasce anche in console
 * e in coda, dove un tenant corrente non esiste — e scoparla al tenant corrente
 * significherebbe che la pagina del Developer non mostra nulla. L'esenzione è
 * dichiarata in `TenantScopeGuardrailTest::NON_TENANT_MODELS` con la sua
 * ragione; la porta d'accesso non è `VistaPiattaforma`, che qui sarebbe un
 * «bypass finto» (non c'è nessuno scope da togliere).
 *
 * **Niente `AuditsDomainWrites`** (🔗 ADR-027): il trait scriverebbe una riga di
 * audit **a ogni incremento del contatore**, cioè l'audit di un log — la stessa
 * ragione per cui ne è esente `AvvisoScadenza`. L'esenzione è dichiarata in
 * `AuditCoverageGuardrailTest::ESENZIONI`. ⚠️ Non è il ramo del trait a coprire
 * questo modello: senza la voce là dentro il meta-test è rosso, ed è giusto che
 * lo sia — la scelta va fatta, non dimenticata.
 *
 * ## 🔴 I tre gesti stanno QUI, e non in un service
 *
 * `risolvi()`, `ignora()` e `riapri()` sono metodi **di questo model**, e la
 * ragione è meccanica prima che stilistica: il meta-test dei soggetti di audit
 * (`SoggettiAuditTest::covers every model the codebase can write as a subject`)
 * trova chi scrive audit a mano **leggendo il sorgente dei model**
 * (`str_contains(file_get_contents(app_path('Models/....php')), 'activity(')`).
 * Le stesse tre righe scritte in un `App\Support\Errori\GestisceErrori`
 * sarebbero **invisibili** a quel meta-test: resterebbe verde, `Errore` potrebbe
 * non comparire in `SoggettiAudit::SOGGETTI` e l'etichetta del registro
 * degraderebbe a «Errore · #12» senza che nulla lo dica. È esattamente il buco
 * che `Role` aveva, trovato la settimana scorsa e chiuso a mano.
 *
 * Mettendo la scrittura qui, invece, la rete si arma da sé: togliere `Errore`
 * da `SoggettiAudit::SOGGETTI` rende rosso il meta-test **senza che nessuno
 * debba ricordarsene**.
 *
 * ⚠️ **`forceFill()->save()` e mai `update()` di massa**: `$fillable` elenca
 * solo ciò che il tracker scrive alla *nascita* di una issue, quindi `stato`,
 * `contesti` e i timestamp di chiusura verrebbero **scartati in silenzio** da
 * un'assegnazione di massa — il gesto tornerebbe `void` senza aver cambiato
 * niente, e nessuna eccezione lo direbbe.
 *
 * 🔴 **La riapertura automatica (`CatturaErrori::incrementa()`) non scrive
 * audit**: non è il gesto di una persona, e il registro racconta chi ha fatto
 * cosa. Solo i tre gesti umani lasciano una riga.
 */
class Errore extends Model
{
    /**
     * ⚠️ **L'alias non è uno sfoggio: senza, `pruneAll()` qui sotto non
     * compila.** `pruneAll()` arriva da un **trait usato da questa stessa
     * classe**, non da una classe genitore: definendone una versione propria la
     * si sostituisce, e `parent::pruneAll()` finisce su `Model`, che non ce l'ha
     * — quindi cade in `__call()`, viene inoltrato al query builder e muore con
     * «Call to undefined method App\Models\Errore::pruneAll()». Misurato: dieci
     * test rossi, e il messaggio non nomina il trait.
     *
     * Con l'alias l'implementazione originale resta raggiungibile per nome, ed è
     * ciò che la nostra chiama dopo aver oscurato i messaggi.
     */
    use Prunable {
        Prunable::pruneAll as private potaDavvero;
    }

    /**
     * Quanto vive una issue **chiusa** dopo l'ultima volta che è successa.
     *
     * Novanta giorni, cioè **lo stesso orizzonte delle occorrenze**
     * (`OccorrenzaErrore::GIORNI`), e l'uguaglianza è voluta: l'ultima
     * occorrenza di una issue avviene per definizione a `ultima_occorrenza_at`,
     * quindi contenitore e prove attraversano il confine insieme e non resta un
     * guscio senza niente dentro. Un bug che qualcuno ha dichiarato sistemato e
     * che per tre mesi non si è più visto non ha altro da dire.
     */
    private const GIORNI_CHIUSE = 90;

    /**
     * Quanto vive una issue **aperta** dopo l'ultima volta che è successa.
     *
     * Il doppio, perché è lavoro ancora da fare e la riga è la sola traccia che
     * esista di un guasto che nessuno ha guardato.
     *
     * ⚠️ **Conseguenza accettata e dichiarata**: fra i 90 e i 180 giorni una
     * issue aperta e silenziosa resta **senza le proprie prove**, potate al
     * proprio confine. Continua a portare classe, file, riga e il contatore —
     * cioè cosa si rompe e quante volte — e perde il «con quali dati». È il
     * verso giusto in cui sbagliare: le occorrenze sono la parte che contiene
     * dati personali (stack trace, ip, user agent, input), e prolungarne la vita
     * per accompagnare una issue che nessuno apre da tre mesi allargherebbe la
     * conservazione proprio dove va stretta (Privacy §3, T8).
     */
    private const GIORNI_APERTE = 180;

    /**
     * 🔴 **Oltre quanto tempo il `messaggio` di una issue si SVUOTA**, in
     * qualunque stato essa sia (decisione del 24 Ago 2026 — 🔗 ADR-017, Privacy
     * §T8).
     *
     * ## Il buco che chiude
     *
     * Il `messaggio` è **interpolato** («Utente 42 non trovato»): è l'unica
     * colonna di `errori` che possa portare un dato riferito a una persona, e a
     * differenza delle occorrenze — che se ne vanno a 90 giorni — vive quanto la
     * issue. Su una issue `ignorato`, che il blocco 7 non pota **mai**, «quanto
     * la issue» significa **per sempre**: la Privacy lo aveva dichiarato come
     * limite accettato, e questa costante è la decisione che lo toglie.
     *
     * ⚠️ **E vale per OGNI stato, non solo per `ignorato`**, perché il rischio
     * più grande non è quello che si vede: `ultima_occorrenza_at` si rinfresca a
     * ogni avvenimento, quindi **una issue aperta che continua a ripetersi non
     * viene mai potata comunque**, senza che nessuno tocchi niente. Restringere
     * l'oscuramento al solo stato zittito avrebbe coperto la casella più rara
     * lasciando aperta la più frequente. (È la stessa misura già scritta in
     * Privacy §T8: «il delta vero dell'esenzione è la sola casella *zittita e
     * smessa di ripetersi*».)
     *
     * ## Cosa resta, e perché basta
     *
     * `classe`, `file`, `riga`, `impronta`, i contatori e le date. Cioè **cosa**
     * si rompe, **dove** e **quante volte** — che è tutto ciò che serve a
     * riconoscere l'errore e a decidere se guardarlo — e che non riguarda
     * nessuno: un nome di classe e un numero di riga sono fatti sul codice, non
     * su una persona. Il messaggio era comunque etichettato in pagina come
     * **campione** e non come «il» messaggio dell'errore.
     *
     * ⚠️ **180 giorni scritti qui e non `self::GIORNI_APERTE`**, benché la cifra
     * coincida: è un confine di *minimizzazione*, non di conservazione, e i due
     * devono poter divergere. Allungare la vita delle issue aperte a 365 giorni
     * — decisione tecnica plausibile — non deve **allungare in silenzio** la
     * permanenza di un dato personale; una costante condivisa lo farebbe senza
     * che nessuno se ne accorgesse.
     */
    private const GIORNI_MESSAGGIO = 180;

    /**
     * Ciò che prende il posto del messaggio oscurato.
     *
     * 🔴 **Serviva distinguere «oscurato» da «vuoto», e la scelta è una
     * sentinella e non una colonna nuova.** Il vuoto esiste davvero: quando
     * un'eccezione non porta messaggio, `CatturaErrori` scrive `''` — quindi una
     * riga svuotata a `''` sarebbe **indistinguibile** da una issue nata muta, e
     * chi legge la pagina non saprebbe se il messaggio non c'è mai stato o se
     * gli è stato tolto. La differenza conta: nel primo caso non c'è niente da
     * cercare, nel secondo la storia è più lunga della riga.
     *
     * Le alternative pesate:
     *
     * - **`null`** — la colonna è `NOT NULL`, quindi servirebbe una migration; e
     *   `null` significa comunque «non lo so», che è ciò che si vuole evitare;
     * - **una colonna `messaggio_oscurato_at`** — dice anche *quando*, ma costa
     *   una migration (da applicare anche al DB di sviluppo) per un dato che
     *   nessuna schermata userebbe, e lascerebbe comunque aperta la domanda su
     *   cosa scrivere in `messaggio`;
     * - **questa stringa** — nessuna migration, e soprattutto si legge **ovunque
     *   il messaggio si legga già**: nella scheda, in una `psql`, in un dump.
     *   Una colonna a parte sarebbe visibile solo a chi sa di doverla guardare.
     *
     * ⚠️ **È `public` apposta**: la sentinella è un valore di dominio che i test
     * e chiunque legga la scheda devono poter nominare, non un dettaglio
     * privato. Il testo dice **cosa** è successo e **dopo quanto**, perché
     * troverà lettori che questa costante non la leggeranno mai.
     */
    public const MESSAGGIO_OSCURATO = '[messaggio oscurato dopo 180 giorni dall\'ultima occorrenza]';

    /** Plurale italiano: il default di Eloquent direbbe `errores`. */
    protected $table = 'errori';

    /**
     * ⚠️ **`$fillable` e non `$guarded`, e la prima stesura aveva il verso
     * invertito.** Diceva: «nessun `$fillable`, una lista di campi assegnabili
     * in massa sarebbe una porta aperta». È il contrario: il default di Eloquent
     * è `$guarded = ['*']`, cioè **niente** assegnabile in massa; scrivere
     * `['id']` lo **rilassa** — verificato, `isFillable('stato')` tornava `true`.
     * Una whitelist è più stretta del default, non più larga, ed è ciò che fanno
     * tutti e undici gli altri model di questo progetto.
     *
     * L'elenco è **solo ciò che il tracker scrive alla nascita di una issue**:
     * lo stato e i contatori si muovono da metodi espliciti — `risolvi()`,
     * `ignora()`, `riapri()` qui sotto, e `CatturaErrori::incrementa()` per il
     * contatore — non da un array che arriva da chissà dove.
     */
    protected $fillable = [
        'impronta',
        'classe',
        'messaggio',
        'file',
        'riga',
        'prima_occorrenza_at',
        'ultima_occorrenza_at',
    ];

    /**
     * I default in memoria, non solo di colonna: il `default()` della migration
     * si applica all'INSERT, quindi un'istanza appena creata leggerebbe `null`
     * finché qualcuno non la rilegge dal database — ed è la lezione già pagata
     * su `Account::$attributes` (`is_locked`, `piano`, `di_piattaforma`). Qui
     * conta il doppio: il codice che decide se campionare un contesto gira
     * **dentro** il gestore delle eccezioni, e un `null` dove aspetta un intero
     * lancerebbe proprio lì.
     */
    protected $attributes = [
        'stato' => 'aperto',
        'occorrenze' => 1,
        'contesti' => 0,
    ];

    protected function casts(): array
    {
        return [
            'riga' => 'integer',
            'occorrenze' => 'integer',
            'contesti' => 'integer',
            'ultimo_contesto_at' => 'datetime',
            'prima_occorrenza_at' => 'datetime',
            'ultima_occorrenza_at' => 'datetime',
            'riaperto_automaticamente_at' => 'datetime',
            'risolto_at' => 'datetime',
            'alert_inviato_at' => 'datetime',
        ];
    }

    /**
     * 🔴 **La potatura, e i due stati che la subiscono — `ignorato` non è uno di
     * loro.**
     *
     * Passa dal `model:prune` **già schedulato** in `routes/console.php`, che
     * legge `App\Support\Retention::MODELLI`: nessun comando nuovo «da
     * schedulare al deploy», che è la forma esatta del difetto T6 (una retention
     * dichiarata e inerte) già rimproverata al registro di audit.
     *
     * ## Perché una whitelist di stati e non `where('stato', '!=', 'ignorato')`
     *
     * 🔴 **Potare un `ignorato` lo resusciterebbe.** È l'unico interruttore di
     * silenzio del tracker: la issue resta fuori dall'elenco e non manda alert
     * (`CatturaErrori::allerta()` non ha nemmeno un ramo che la raggiunga:
     * `incrementa()` riapre solo i `risolto`). Ma il silenzio vive **nella riga**, non altrove: tolta la
     * riga, il `firstOrCreate` del percorso caldo non la trova più, ne crea una
     * nuova `aperto`, `wasRecentlyCreated` è vero e parte l'alert. L'unica cosa
     * che qualcuno ha chiesto di non sentire più si riaccenderebbe **da sé, a
     * scadenza** — e per giunta il giorno in cui nessuno se lo aspetta.
     *
     * I due rami nominano quindi lo stato che potano, invece di escludere quello
     * che non va potato: è la stessa disciplina fail-closed della tenancy. Uno
     * stato **nuovo** — se un domani ne nascesse un quarto — resterebbe fuori
     * dalla potatura finché qualcuno non decide che ci deve stare, che è il verso
     * in cui si sbaglia senza perdere dati.
     *
     * ## Il confine è `ultima_occorrenza_at`, non `created_at`
     *
     * Una issue nata un anno fa e successa ieri è viva. Il tempo che conta è
     * quello passato dall'ultima volta che il guasto è avvenuto, ed è anche ciò
     * che fa combaciare l'orizzonte delle issue chiuse con quello delle loro
     * occorrenze.
     *
     * ⚠️ `<` e non `<=`: il confine appartiene a chi resta. Su SQLite questi
     * confronti sono lessicografici su stringhe e su Postgres sono date vere,
     * quindi i test dei confini si rieseguono su `easylab_test`.
     *
     * ## `Prunable` e non `MassPrunable`, e la ragione vera
     *
     * ⚠️ La ragione che il piano attribuiva a questa scelta — «`Prunable` salta
     * gli eventi del model» — è **falsa, verificata**: è `MassPrunable` a
     * saltarli (fa una `delete()` di massa sul query builder), mentre
     * `Prunable::pruneAll()` fa `chunkById()` → `$model->prune()` → `delete()`,
     * quindi `deleting`/`deleted` **scattano** riga per riga.
     *
     * La scelta resta `Prunable`, per due ragioni che valgono davvero. La prima:
     * le occorrenze se ne vanno comunque, perché il `cascadeOnDelete` è **nello
     * schema** (`occorrenze_errore.errore_id`) e il database non chiede il
     * permesso a Eloquent — un `MassPrunable` non lascerebbe orfani neanche lui.
     * La seconda, che è quella decisiva: `pruneAll()` isola il guasto di **una**
     * riga (vedi sotto), mentre una delete di massa fallisce o riesce tutta
     * insieme, su una tabella che qui può essere grande.
     *
     * ## 🔴 Un guasto mentre si potano gli errori scrive in `errori`, e va bene
     *
     * `Prunable::pruneAll()` avvolge ogni `$model->prune()` in un
     * `catch (Throwable)` che chiama `report()` — cioè, per questo model, il
     * tracker stesso. Il flag di rientranza di `CatturaErrori` **non copre questo
     * caso** e il suo docblock lo dice: quel `report()` avviene *dopo* che
     * `cattura()` è uscita, quindi il flag non è più sullo stack.
     *
     * **Non si aggiunge una guardia**, ed è una decisione, non una dimenticanza:
     *
     * - **non è una ricorsione.** La riga che `report()` scrive nasce con
     *   `ultima_occorrenza_at = now()`, quindi è per costruzione **fuori** dalla
     *   query qui sotto; e `chunkById()` avanza per `id` crescente, quindi non
     *   torna nemmeno a guardarla. Il giro si chiude da sé, senza flag;
     * - **il verso della guardia sarebbe sbagliato.** Zittire il tracker durante
     *   la potatura significa che il giorno in cui la potatura si rompe — la sola
     *   cosa che tenga sotto controllo la crescita di queste due tabelle — non
     *   resta niente da nessuna parte, perché `laravel.log` su Cloud è effimero e
     *   il cron non ha nessuno che lo guardi. Un guasto della potatura è
     *   esattamente il genere di errore per cui questo tracker esiste.
     *
     * @return Builder<Errore>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $chiuse) => $chiuse
                    ->where('stato', 'risolto')
                    ->where('ultima_occorrenza_at', '<', now()->subDays(self::GIORNI_CHIUSE)))
                ->orWhere(fn (Builder $aperte) => $aperte
                    ->where('stato', 'aperto')
                    ->where('ultima_occorrenza_at', '<', now()->subDays(self::GIORNI_APERTE))));
    }

    /**
     * 🔴 **L'oscuramento dei messaggi, agganciato alla potatura che già gira.**
     *
     * ⚠️ **Qui e non in un comando nuovo**, ed è la lezione del blocco 7 messa
     * in pratica una seconda volta: un `errori:oscura-messaggi` da schedulare al
     * deploy sarebbe la forma esatta del difetto **T6** — una misura dichiarata
     * nel registro dei trattamenti e mai avvenuta, perché nessuno si ricorda di
     * una riga di cron che non è nel repository. `pruneAll()` è il metodo che
     * `model:prune` chiama su ogni modello dell'elenco
     * (`App\Support\Retention::MODELLI`), quindi l'oscuramento eredita **la
     * schedulazione già verificata da due meta-test** e non ne aggiunge una da
     * verificare a parte. Non c'è nessun comando in più da ricordarsi.
     *
     * ⚠️ **Prima l'oscuramento, poi la potatura**, e l'ordine è stato scelto,
     * non subito. Al contrario si risparmierebbero le poche righe aggiornate un
     * istante prima di essere cancellate — ma si legherebbe l'oscuramento alla
     * **riuscita** della potatura: `pruneAll()` scorre la tabella a chunk e
     * cancella riga per riga, cioè è la metà che può rompersi (timeout, tabella
     * grande, una FK), mentre l'oscuramento è **una sola UPDATE**. Nell'ordine
     * scelto il dato personale se ne va anche nella giornata in cui la potatura
     * fallisce; nell'ordine inverso resterebbe in chiaro un altro giorno. Il
     * costo è qualche riga aggiornata invano.
     *
     * ⚠️ **Non si filtra per stato.** `prunable()` qui sotto nomina i due stati
     * che pota — disciplina fail-closed, perché cancellare è irreversibile — ma
     * l'oscuramento è il gesto **opposto**: sbagliarlo per eccesso toglie un
     * messaggio a una issue che poteva tenerselo, sbagliarlo per difetto lascia
     * un dato personale a database. Il verso giusto in cui sbagliare si ribalta
     * insieme alla conseguenza, quindi qui si guarda solo il **tempo**.
     */
    public function pruneAll(int $chunkSize = 1000)
    {
        self::oscuraMessaggiScaduti();

        return $this->potaDavvero($chunkSize);
    }

    /**
     * Svuota il `messaggio` delle issue ferme da oltre `GIORNI_MESSAGGIO`,
     * lasciando in piedi tutto il resto della riga.
     *
     * ⚠️ **Una UPDATE di massa, e non un giro di `save()`**: non è un gesto di
     * dominio (quelli stanno su `risolvi()`/`ignora()`/`riapri()` e scrivono
     * audit), è una misura di minimizzazione che deve costare una query anche su
     * una tabella grande. Non scrive audit per la stessa ragione per cui non lo
     * fa la potatura: nessuno ha fatto niente, è passato del tempo.
     *
     * ⚠️ **`<` e non `<=`**, come `prunable()`: il confine appartiene a chi
     * resta. E il filtro sulla sentinella non è un'ottimizzazione ma la
     * differenza fra un'operazione **idempotente** e una che ogni notte
     * riscrive — e rimuove la data di `updated_at` — le stesse righe già
     * oscurate mesi fa.
     *
     * @return int quante righe sono state oscurate in questa passata
     */
    public static function oscuraMessaggiScaduti(): int
    {
        return static::query()
            ->where('ultima_occorrenza_at', '<', now()->subDays(self::GIORNI_MESSAGGIO))
            ->where('messaggio', '!=', self::MESSAGGIO_OSCURATO)
            ->update(['messaggio' => self::MESSAGGIO_OSCURATO]);
    }

    /**
     * I contesti conservati, che sono un **campione** e non tutte le occorrenze.
     *
     * ⚠️ **Il nome non può essere `occorrenze()`**, per quanto sarebbe quello
     * naturale: `occorrenze` è già la colonna-contatore, e su una collisione fra
     * attributo e relazione vince l'attributo — `$errore->occorrenze` tornerebbe
     * l'intero e la relazione sarebbe raggiungibile solo chiamandola come
     * metodo, cioè un tranello permanente per chi legge. Il nome ripete quello
     * della tabella per non lasciare dubbi su quale delle due cifre si sta
     * chiedendo.
     *
     * @return HasMany<OccorrenzaErrore, $this>
     */
    public function occorrenzeErrore(): HasMany
    {
        return $this->hasMany(OccorrenzaErrore::class);
    }

    /**
     * Chi ha chiuso la issue. `nullOnDelete` sulla colonna, quindi la relazione
     * può essere vuota su una riga risolta: la storia dei bug sopravvive a chi
     * l'ha scritta.
     *
     * @return BelongsTo<User, $this>
     */
    public function risoltoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'risolto_da');
    }

    /**
     * «Credo di averlo sistemato».
     *
     * ⚠️ Non è un'affermazione definitiva, ed è la differenza con `ignora()`: una
     * nuova occorrenza **contraddice** questo gesto, e `CatturaErrori::incrementa()`
     * riapre la issue da sé, azzerando anche il budget dei contesti perché la
     * prova che serve — «l'ho corretto, perché succede ancora?» — è proprio quella
     * successiva al tentativo di correzione.
     */
    public function risolvi(User $chi): void
    {
        $this->cambiaStato('risolto', $chi, 'Errore risolto', [
            'risolto_at' => now(),
            'risolto_da' => $chi->getKey(),
        ]);
    }

    /**
     * «So che c'è e non me ne importa».
     *
     * 🔴 **L'unico interruttore di silenzio del tracker, e l'unico stato che non
     * si riapre mai** (`CatturaErrori::incrementa()` riapre solo i `risolto`). Il
     * contatore continua a crescere — la riga dice ancora la verità su quante
     * volte succede — ma la issue resta fuori dall'elenco di default e, dal
     * blocco 7, fuori dalla potatura: potare un `ignorato` lo **resusciterebbe**,
     * perché il `firstOrCreate` del percorso caldo non troverebbe più la riga e
     * ne creerebbe una nuova, `aperto`.
     *
     * ⚠️ Azzera `risolto_at`/`risolto_da`: «ignorato» e «chiuso da qualcuno» sono
     * due fatti diversi, e lasciare il secondo attaccato al primo attribuirebbe a
     * una persona una chiusura che non ha fatto.
     */
    public function ignora(User $chi): void
    {
        $this->cambiaStato('ignorato', $chi, 'Errore ignorato', [
            'risolto_at' => null,
            'risolto_da' => null,
        ]);
    }

    /**
     * «Non è sistemato»: la issue torna in elenco.
     *
     * 🔴 **Azzera anche il budget dei contesti**, ed è la ragione per cui questo
     * metodo non è un semplice `stato = 'aperto'`. La riapertura *automatica* lo
     * fa già (`CatturaErrori::incrementa()`), ma quel ramo scatta **solo** su una
     * issue `risolto`: dopo una riapertura a mano lo stato è `aperto`, quindi la
     * prossima occorrenza non passerebbe di lì e il budget resterebbe al tetto.
     * Una issue riaperta a mano non catturerebbe **mai più** un contesto — cioè
     * proprio la prova per cui la si sta riaprendo.
     *
     * ⚠️ Azzera anche `riaperto_automaticamente_at`: quella colonna significa
     * «l'ultima riapertura è avvenuta da sé», e la pagina la stampa come «riaperto
     * automaticamente il …». Lasciarla dopo un gesto umano farebbe dire alla
     * scheda una cosa falsa proprio sul fatto che distingue una regressione da una
     * decisione.
     */
    public function riapri(User $chi): void
    {
        $this->cambiaStato('aperto', $chi, 'Errore riaperto', [
            'risolto_at' => null,
            'risolto_da' => null,
            'riaperto_automaticamente_at' => null,
            'contesti' => 0,
            'ultimo_contesto_at' => null,
        ]);
    }

    /**
     * Lo stato che cambia, e la **riga nel registro di audit**.
     *
     * ⚠️ **`forceFill()` e non `update()`**: `stato`, `contesti` e i timestamp di
     * chiusura sono fuori da `$fillable`, quindi un `update()` di massa li
     * scarterebbe **in silenzio** — il metodo tornerebbe senza aver cambiato
     * niente e senza che nulla lo dica.
     *
     * 🔴 **L'etichetta del soggetto è `classe`, MAI `messaggio`**, e la scelta è
     * un confine di privacy, non una preferenza. Il registro di audit si legge con
     * `tenants.view_all` — cioè dal **Superadmin**, che `system.logs.view` non ce
     * l'ha e la pagina degli errori non la può nemmeno aprire. Mettere il
     * messaggio nell'etichetta (`SoggettiAudit::SOGGETTI`) o qui dentro nelle
     * `properties` lo farebbe **filtrare attraverso il gate più stretto del
     * progetto**: i messaggi sono interpolati e portano dati di richiesta reali
     * («Utente 42 non trovato», un identificativo, un indirizzo). Le `properties`
     * portano quindi la sola `classe`, che è anche ciò che tiene la riga leggibile
     * il giorno in cui la retention (blocco 7) si porta via il soggetto e
     * l'etichetta degrada a «Errore · #12».
     *
     * ⚠️ **Niente `->event()`**: la riga è un **atto**, non il diff delle colonne
     * di un model, ed è così che resta raggiungibile dal filtro del registro con
     * la sentinella `__atto` (`RegistroAudit::ATTI`). Un `->event('updated')`
     * scritto per abitudine la farebbe scivolare fra le modifiche di dominio e
     * sparire da lì.
     *
     * `properties.impersonato_da` arriva gratis dall'hook di `AppServiceProvider`:
     * non si scrive qui, o sarebbero due fonti per lo stesso fatto.
     *
     * @param  array<string, mixed>  $altre
     */
    private function cambiaStato(string $a, User $chi, string $descrizione, array $altre): void
    {
        $da = $this->stato;

        $this->forceFill(array_merge($altre, ['stato' => $a]))->save();

        activity(AuditLog::NAME)
            ->causedBy($chi)
            ->performedOn($this)
            ->withProperties([
                // Il vocabolario del registro: `DettaglioAttivita` rende `da`/`a`
                // come elenco chiave/valore, come per la matrice dei permessi.
                'classe' => $this->classe,
                'da' => $da,
                'a' => $a,
            ])
            ->log($descrizione);
    }
}
