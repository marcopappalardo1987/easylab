<?php

namespace App\Livewire\Piattaforma;

use App\Models\Account;
use App\Models\Piano;
use App\Support\Listino\CatalogoPiani;
use App\Support\Listino\GovernoListino;
use App\Support\Listino\LinkDiPagamento;
use App\Support\Piani;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 🔴 Il listino dei piani commerciali — la quinta pagina di piattaforma
 * (🔗 ADR-035, ADR-002 sul Free, ADR-032 sul tetto di Enti).
 *
 * Fino al 27 Ago 2026 il listino era `config('easylab.piani.catalogo')`:
 * cambiare una cifra voleva dire un commit, un deploy e uno sviluppatore. Da
 * qui si crea un piano, se ne cambia il prezzo, lo si archivia — e **lo stesso
 * gesto crea o aggiorna il prodotto su Stripe**, che è la decisione di prodotto
 * («opzione A») presa insieme ad ADR-035.
 *
 * ## Cosa questa pagina NON fa, e va letto prima del resto
 *
 * ⚠️ **Non migra nessuno.** Su Stripe un Price è **immutabile**: cambiare cifra
 * ne crea uno nuovo e archivia il vecchio, mentre le subscription in essere
 * continuano a fatturare su quello vecchio. Chi è già abbonato **resta al suo**,
 * ed è precisamente ciò che rende necessario lo storico di `prezzi_piano` —
 * senza, `Piani::perPrice()` smetterebbe di riconoscere il piano di ogni
 * cliente precedente e `accounts.piano` non si riallineerebbe più, in silenzio.
 * Migrare i clienti esistenti è un altro gesto e un'altra schermata.
 *
 * ⚠️ **Non cancella un piano, lo archivia.** `accounts.piano` referenzia il
 * piano per **stringa senza FK**: cancellarlo produrrebbe di colpo N clienti
 * «fuori catalogo», che nell'MRR di `MetrichePiattaforma` valgono 0 €.
 * `attivo` governa **solo** l'offribilità, mai l'esistenza.
 *
 * ⚠️ **Non ripara le divergenze da sola, in nessuna delle due direzioni.** Il
 * confronto con Stripe è un **bottone**, non una riga di `render()`: vedi più
 * sotto.
 *
 * ## Perché il confronto con Stripe è un gesto e non parte del render
 *
 * 🔴 È la trappola che ADR-035 nomina per prima. Una conciliazione dentro
 * `render()` significherebbe una chiamata di rete per piano a ogni apertura
 * della pagina — e una **pagina di piattaforma che muore perché un fornitore
 * esterno è giù**, o perché le chiavi Stripe non sono configurate. È la stessa
 * forma di difetto per cui `MetrichePiattaforma` non lascia esplodere un piano
 * fuori catalogo, ed è congelata da un test che conta le chiamate alla porta
 * finta e pretende **zero** finché nessuno preme «Confronta con Stripe».
 *
 * L'altra metà della stessa decisione: la divergenza, una volta trovata, **si
 * vede** — coi due valori affiancati, nella stessa forma del marcatore
 * «personalizzato» di `/piattaforma/ruoli`. Là il pericolo era che il database
 * e `config/rbac.php` divergessero in silenzio; qui è che divergano il listino
 * e ciò che il cliente paga davvero. Un marcatore che dicesse solo «diverge»
 * obbligherebbe ad aprire la dashboard di Stripe per sapere **di quanto**, cioè
 * a fare a mano metà del confronto che questa pagina esiste per togliere.
 *
 * ⚠️ **Il confronto si butta via dopo ogni scrittura.** Un cambio di prezzo
 * rende falsi i marcatori calcolati un istante prima — mostrerebbero una
 * divergenza contro un valore locale che non esiste più. Meglio dire «non
 * confrontato» che dire una cifra sbagliata.
 *
 * ## Il permesso, e perché il gate è scritto in testa a OGNI azione
 *
 * `billing.manage_global` è già a catalogo, è già dei soli Developer e
 * Superadmin, ed è già nel **set bloccato** (ADR-016): l'editor permessi di
 * runtime non può regalarlo a un ruolo cliente. ADR-035 rifiuta esplicitamente
 * un permesso nuovo — sarebbe un ottavo permesso bloccato e un riseeding per
 * dire la stessa cosa, cioè l'errore già commesso e corretto con
 * `system.errors.view`.
 *
 * ⚠️ **Alla porta di tenancy non si aggiunge un `piani()`.** `piani` e
 * `prezzi_piano` sono tabelle **globali**, senza tenancy: non c'è alcuno scope
 * da togliere, e un metodo in più in `VistaPiattaforma` sarebbe il «bypass
 * finto» che il suo docblock rifiuta per nome — la scelta già fatta da
 * `MatriceRuoli`. La conseguenza va accettata e scritta: questo componente
 * **non eredita** il guardrail «nessuna scrittura concatenata alla porta»,
 * quindi il `Gate::authorize()` va ripetuto **in testa a ogni azione** oltre che
 * in `render()`. Senza, `Livewire::test()` — che disabilita i middleware — non
 * incontrerebbe nessuna guardia, e un'azione che un domani guadagnasse
 * `skipRender()` scriverebbe **prima** che `render()` possa rispondere 403.
 *
 * 🔴 **Ma questa pagina legge anche `accounts`, e QUELLA lettura la porta la
 * copre.** Le colonne «Clienti» e il conteggio di chi finirebbe sopra il tetto
 * sono conteggi di **tutti gli account della piattaforma**: si prendono da
 * `VistaPiattaforma::accounts()` e mai da un `Account::query()` a mano, che
 * salterebbe il `Gate::authorize('tenants.view_all')` — la sola guardia che
 * esiste per quel dato. Oggi i due permessi stanno sugli stessi due ruoli, e
 * `AccessoListinoTest` congela il fatto; ma è precisamente la divergenza che la
 * decisione di **non** metterli in AND mette in conto, e il giorno in cui
 * arrivasse un ruolo con `billing.manage_global` e senza `tenants.view_all`
 * quella persona leggerebbe il censimento dei clienti di tutta la piattaforma
 * senza attraversare nessuna porta. Il meta-test dei bypass nudi non lo
 * vedrebbe: guarda `withoutGlobalScopes()`, e qui non ce n'è nessuno.
 *
 * ⚠️ Di conseguenza `accounts()` **esclude EasyLab stessa** (`di_piattaforma`),
 * ed è la lettura giusta: la colonna si intitola «Clienti».
 *
 * ⚠️ **Il file sta in `app/Livewire/Piattaforma/`, non in una sottocartella
 * propria**: `LocalizzazioneTest` deriva il proprio universo da un `glob()` con
 * doppio asterisco, che in PHP **non è ricorsivo** e si ferma a profondità 2. A
 * profondità 3 questo file sfuggirebbe al meta-test in silenzio.
 */
#[Layout('components.layouts.app')]
class Listino extends Component
{
    /**
     * Non una stringa ribattuta nelle viste e nelle rotte: creare un piano è
     * **fissare un prezzo**, e questo permesso è già del solo Developer e
     * Superadmin ed è nel set bloccato (ADR-016) — l'editor di runtime non può
     * regalarlo a un ruolo cliente.
     */
    public const PERMESSO = 'billing.manage_global';

    /** Il form di creazione è chiuso finché non lo si apre: la pagina è un listino, non un form. */
    public bool $creazioneAperta = false;

    /**
     * @var array<string, string|bool>
     *
     * ⚠️ `prezzo_mensile_cent` è in **centesimi interi** e non in euro, e il
     * campo lo dice. Un euro-float su decine di clienti accumula errore
     * nell'MRR, ed è la riga che nessuno rilegge (`Piani::prezzoMensileCent()`).
     */
    public array $nuovo = [
        'codice' => '',
        'etichetta' => '',
        'max_enti' => '',
        'prezzo_mensile_cent' => '0',
        'gratuito' => false,
        'ordine' => '',
    ];

    /** L'id del piano aperto in modifica, o `null`. Arriva dal browser: si risolve, non ci si fida. */
    public ?int $pianoInModifica = null;

    /** @var array<string, string> */
    public array $modifica = [
        'etichetta' => '',
        'max_enti' => '',
        'prezzo_mensile_cent' => '',
        'ordine' => '',
    ];

    /**
     * Cosa deve dire la modale di conferma, o `[]` se non c'è niente in attesa.
     *
     * ⚠️ **Non è una guardia, ed è la cosa importante da capire.** Abbassare il
     * tetto di Enti sotto il numero di sedi di un cliente **è permesso**, non
     * cestina nulla e non blocca nessuno: chi ci finisce sotto tiene tutte le
     * sue sedi e semplicemente non ne apre altre (grandfathering, ADR-032 —
     * `slotEntiResidui()` diventa negativo, ed è uno stato legittimo e
     * documentato). Ciò che manca a chi clicca è **saperlo prima**, con il
     * numero davanti: è una decisione da far prendere a una persona, non un
     * rifiuto da scrivere in `GovernoListino`.
     *
     * @var array<string, int|string|null>
     */
    public array $conferma = [];

    /** Il piano per cui si sta agganciando un price già esistente su Stripe. */
    public ?int $pianoInAggancio = null;

    public string $priceId = '';

    /**
     * Se il confronto con Stripe è stato **chiesto** in questa sessione di
     * pagina. Distinto da `$divergenze === []`, che significa «confrontato, e
     * coincidono»: le due frasi mandano a fare cose opposte.
     */
    public bool $confrontato = false;

    /**
     * @var list<array{codice: string, campo: string, locale: string, remoto: string}>
     *
     * Array e non oggetti `DivergenzaListino`: è **stato pubblico di Livewire**,
     * cioè viaggia nello snapshot fra due richieste, e ciò che attraversa quel
     * confine dev'essere serializzabile senza cerimonie.
     */
    public array $divergenze = [];

    // ─── Creare un piano ─────────────────────────────────────────────────────

    public function apriCreazione(): void
    {
        Gate::authorize(self::PERMESSO);

        $this->reset('nuovo');
        $this->resetErrorBag();
        $this->creazioneAperta = true;
    }

    public function chiudiCreazione(): void
    {
        $this->creazioneAperta = false;
    }

    /**
     * Crea il piano **e** lo sincronizza su Stripe: è un gesto solo.
     *
     * È l'«opzione A» decisa con ADR-035. L'alternativa — creare la riga e
     * lasciare la sincronizzazione a un secondo click — produce come stato
     * normale un piano offribile che su Stripe non esiste: la prima
     * sottoscrizione fallirebbe, e il difetto si scoprirebbe dal lato del
     * cliente.
     *
     * ⚠️ **Le due chiamate restano due, e in quest'ordine.** La scrittura locale
     * ha la sua transazione (dentro `GovernoListino::crea()`), la chiamata di
     * rete sta **fuori** da qualunque transazione, e se Stripe rifiuta la riga
     * locale resta: il tetto di Enti e l'etichetta governano il provisioning, non
     * la fatturazione, e devono valere anche a fornitore giù. Il fallimento non è
     * silenzioso — finisce in `stripe_ultimo_errore`, che questa pagina stampa
     * per esteso.
     */
    public function crea(): void
    {
        Gate::authorize(self::PERMESSO);

        $piano = GovernoListino::crea([
            'codice' => $this->nuovo['codice'],
            'etichetta' => $this->nuovo['etichetta'],
            'max_enti' => $this->nuovo['max_enti'],
            'gratuito' => (bool) $this->nuovo['gratuito'],
            'prezzo_mensile_cent' => (int) $this->nuovo['prezzo_mensile_cent'],
            'ordine' => (int) ($this->nuovo['ordine'] === '' ? 0 : $this->nuovo['ordine']),
        ]);

        // Un piano gratuito non ha nulla su Stripe **per definizione** (ADR-002):
        // `sincronizza()` lo sa e torna subito, ma chiamarla comunque tiene un
        // solo percorso invece di due — e la definizione del Free in un posto
        // solo, che è dove sta già.
        GovernoListino::sincronizza($piano);

        $this->creazioneAperta = false;
        $this->reset('nuovo');
        $this->dimenticaIlConfronto();
    }

    // ─── Modificare un piano ─────────────────────────────────────────────────

    public function apriModifica(int $piano): void
    {
        Gate::authorize(self::PERMESSO);

        $modello = Piano::query()->findOrFail($piano);

        $this->pianoInModifica = $modello->id;
        $this->modifica = [
            'etichetta' => (string) $modello->etichetta,
            // `null` = illimitato, e in un campo numerico si scrive **vuoto**.
            // Uno zero direbbe «nessuna sede», che è un piano inutilizzabile.
            'max_enti' => $modello->max_enti === null ? '' : (string) $modello->max_enti,
            'prezzo_mensile_cent' => (string) $modello->prezzo_mensile_cent,
            'ordine' => (string) $modello->ordine,
        ];
        $this->conferma = [];
        $this->resetErrorBag();
    }

    public function chiudiModifica(): void
    {
        $this->pianoInModifica = null;
        $this->conferma = [];
    }

    /**
     * Salva l'anagrafica e, se cambiato, il prezzo — fermandosi a chiedere
     * quando il tetto di Enti scende sotto le sedi di qualcuno.
     */
    public function salva(): void
    {
        Gate::authorize(self::PERMESSO);

        $modello = $this->pianoInLavorazione();

        $nuovoMax = self::maxEntiDalForm($this->modifica['max_enti']);

        // 🔴 **Due condizioni, non una: il tetto deve SCENDERE, e sotto qualcuno
        // deve esserci.** Guardare solo la seconda è la forma di difetto che il
        // grandfathering stesso fabbrica: appena si abbassa `saas` da 5 a 2 con
        // un cliente a 3 Enti, quel cliente resta **sopra il tetto per sempre** —
        // quindi ogni salvataggio successivo, anche il solo cambio di etichetta
        // con `max_enti` lasciato com'è, ritroverebbe «1 account oltre» e si
        // fermerebbe a chiedere. La modale direbbe «il tetto passa da 2 a 2», e
        // l'etichetta non si salverebbe al primo click. Col tetto in **salita**
        // sarebbe peggio: stessa modale, intitolata «Abbassare il tetto» mentre
        // lo si alza, cioè un'informazione falsa davanti a un pulsante.
        $scende = $nuovoMax !== null
            && ($modello->max_enti === null || $nuovoMax < (int) $modello->max_enti);

        $oltre = $scende ? self::accountOltreIlTetto($modello, $nuovoMax) : 0;

        // Si chiede **solo** se qualcuno ci finisce davvero sotto. Una modale che
        // comparisse comunque sarebbe un ostacolo, non una decisione — e il
        // prezzo di un ostacolo è che lo si preme senza leggerlo.
        if ($oltre > 0) {
            $this->conferma = [
                'codice' => $modello->codice,
                'da' => $modello->max_enti === null ? 'illimitato' : (string) $modello->max_enti,
                'a' => $this->modifica['max_enti'] === '' ? 'illimitato' : (string) (int) $this->modifica['max_enti'],
                'account' => $oltre,
            ];

            return;
        }

        $this->applica($modello);
    }

    /** Il pulsante di conferma della modale. Non scrive nulla se non c'era niente in attesa. */
    public function procedi(): void
    {
        Gate::authorize(self::PERMESSO);

        if ($this->conferma === []) {
            return;
        }

        $this->applica($this->pianoInLavorazione());
    }

    public function annulla(): void
    {
        $this->conferma = [];
    }

    // ─── Stripe ──────────────────────────────────────────────────────────────

    /** Riprova l'allineamento a Stripe di un piano che non lo è (o non lo è più). */
    public function sincronizza(int $piano): void
    {
        Gate::authorize(self::PERMESSO);

        GovernoListino::sincronizza(Piano::query()->findOrFail($piano));

        $this->dimenticaIlConfronto();
    }

    public function apriAggancio(int $piano): void
    {
        Gate::authorize(self::PERMESSO);

        $this->pianoInAggancio = Piano::query()->findOrFail($piano)->id;
        $this->priceId = '';
        $this->resetErrorBag();
    }

    public function chiudiAggancio(): void
    {
        $this->pianoInAggancio = null;
        $this->priceId = '';
    }

    /**
     * Aggancia un price **già esistente** su Stripe.
     *
     * La via d'uscita per il caso che la migration di backfill mette in conto:
     * su Laravel Cloud la config è cachata in **build** e la migration gira
     * dopo, quindi se `STRIPE_PRICE_SAAS` mancasse al deploy il piano `saas`
     * nascerebbe senza price id. Ricrearne uno duplicherebbe il prodotto su
     * Stripe e lascerebbe **due price attivi** per lo stesso piano.
     */
    public function aggancia(): void
    {
        Gate::authorize(self::PERMESSO);

        $modello = Piano::query()->findOrFail($this->pianoInAggancio);

        GovernoListino::agganciaPrezzo($modello, $this->priceId);

        $this->chiudiAggancio();
        $this->dimenticaIlConfronto();
    }

    /**
     * 🔴 Il confronto col listino di Stripe, **su richiesta**.
     *
     * Vedi il docblock di classe: qui dentro ci sono chiamate di rete, e
     * `render()` non deve averne nemmeno una.
     */
    public function confrontaConStripe(): void
    {
        Gate::authorize(self::PERMESSO);

        $this->divergenze = array_map(fn ($divergenza) => [
            'codice' => $divergenza->codicePiano,
            'campo' => $divergenza->campo,
            'locale' => $divergenza->locale,
            'remoto' => $divergenza->remoto,
        ], GovernoListino::concilia());

        $this->confrontato = true;
    }

    // ─── Archiviare ──────────────────────────────────────────────────────────

    public function archivia(int $piano): void
    {
        Gate::authorize(self::PERMESSO);

        GovernoListino::archivia(Piano::query()->findOrFail($piano));

        $this->dimenticaIlConfronto();
    }

    public function riattiva(int $piano): void
    {
        Gate::authorize(self::PERMESSO);

        GovernoListino::riattiva(Piano::query()->findOrFail($piano));

        $this->dimenticaIlConfronto();
    }

    // ─── Il render ───────────────────────────────────────────────────────────

    public function render(): View
    {
        // La guardia gira **a ogni render**, prima di qualunque lettura: è ciò
        // che rende il 403 provabile senza middleware. Il `can:` di rotta resta
        // la guardia larga, e la sola che regge su un'azione con `skipRender()`.
        Gate::authorize(self::PERMESSO);

        // ⚠️ **Nessuna chiamata alla porta Stripe qui dentro.** Vedi il docblock
        // di classe: è la riga che tiene in piedi la pagina quando Stripe è giù.
        $piani = app(CatalogoPiani::class)->tutti();

        $clientiPerPiano = self::clientiPerPiano();

        return view('livewire.piattaforma.listino', [
            'piani' => $piani,
            // Il piano con cui nasce ogni account e a cui si torna dopo una
            // disdetta: è quello che `archivia()` rifiuta, e la pagina lo dice
            // **prima** invece di far scoprire il rifiuto con un click.
            'predefinito' => Piani::predefinito(),
            'clientiPerPiano' => $clientiPerPiano,
            // 🔴 I piani che esistono **solo** su `accounts.piano`: vedi
            // `fuoriCatalogo()`. Costano zero query — sono la differenza fra due
            // insiemi già in mano.
            'fuoriCatalogo' => self::fuoriCatalogo($clientiPerPiano, $piani),
            // Raggruppate per codice qui e non nel Blade: è una trasformazione,
            // e una trasformazione dentro una vista è logica scritta dove non si
            // può provare.
            'divergenzePerPiano' => collect($this->divergenze)->groupBy('codice')->all(),
            'quanteDivergenze' => count($this->divergenze),
            // Il link **al modulo pubblico** col piano già scelto, non un
            // Payment Link di Stripe: il perché sta in `LinkDiPagamento`, e non
            // è una preferenza — un plink incassa senza far nascere l'account.
            // 🔴 Il **Payment Link di Stripe**, preso da `prezzi_piano` senza
            // nessuna chiamata di rete: vedi `LinkDiPagamento`, dove sta anche
            // il perché un plink oggi crea davvero l'account.
            'linkDiPagamento' => LinkDiPagamento::perPiano(),
        ]);
    }

    /**
     * Quanti clienti stanno oggi su ciascun piano.
     *
     * ⚠️ **Una query sola e raggruppata**, non una per riga: la stessa
     * disciplina del ciclo Blade della cabina, dove `Piani` esiste memoizzato
     * proprio per non fare N query per pagina.
     *
     * Include i piani **archiviati**, perché è precisamente su quelli che il
     * numero conta: un piano ritirato con dodici clienti sopra non è un piano
     * morto, è un piano che vale ancora il suo MRR.
     *
     * @return array<string, int>
     */
    private static function clientiPerPiano(): array
    {
        return VistaPiattaforma::accounts()
            ->selectRaw('piano, count(*) as quanti')
            ->groupBy('piano')
            ->pluck('quanti', 'piano')
            ->map(fn ($quanti) => (int) $quanti)
            ->all();
    }

    /**
     * 🔴 I piani che vivono **solo** su `accounts.piano`, col conteggio di chi ci
     * sta sopra.
     *
     * `accounts.piano` è una stringa **senza FK e senza CHECK**: la scrivono il
     * webhook di Stripe e i comandi di console, per codice. Un account può
     * quindi restare su un codice che il listino non conosce (più) — ed è uno
     * stato che ADR-035 dichiara già esistente: `ElencaClienti::FUORI_CATALOGO`
     * lo filtra in cabina, e `MetrichePiattaforma` lo conta in
     * `pianiSconosciuti`, **a 0 € di MRR**.
     *
     * ⚠️ **Questa è l'unica schermata da cui quel dato si ripara**, e senza la
     * striscia non ne mostrerebbe traccia: chi apre il listino per capire perché
     * l'MRR non torna vedrebbe un catalogo perfettamente sano. È la stessa forma
     * della striscia degli orfani in fondo a `/piattaforma/ruoli`, e per la
     * stessa ragione sta **fuori dalla tabella**: mescolare quei codici alle
     * righe vere li legittimerebbe come piani.
     *
     * Costa **zero query**: è la differenza fra i codici già contati e i codici
     * già caricati.
     *
     * @param  array<string, int>  $clientiPerPiano
     * @param  array<string, Piano>  $piani
     * @return array<string, int>
     */
    private static function fuoriCatalogo(array $clientiPerPiano, array $piani): array
    {
        $fuori = array_diff_key($clientiPerPiano, $piani);

        // Ordine per codice: a parità di conteggio l'ordine di un `array_diff_key`
        // è quello della query, cioè una proprietà del motore — la stessa
        // ragione per cui ogni `orderBy` paginato vuole il suo tie-break.
        ksort($fuori);

        return $fuori;
    }

    /**
     * Quanti account finirebbero **sopra** il tetto nuovo.
     *
     * ⚠️ `Account::enti()` toglie gli scope **per nome** apposta — `TenantScope`
     * e `DepartmentScope`, non un `withoutGlobalScopes()` nudo che porterebbe
     * via anche `SoftDeletingScope`: le sedi **cestinate** non devono contare, o
     * una sede chiusa mesi fa occuperebbe uno slot del piano per sempre. È un
     * difetto già pagato una volta dal progetto, e qui riemergerebbe come una
     * modale che avvisa di clienti che non hanno alcun problema.
     *
     * `null` (illimitato) non mette nessuno sopra il tetto: non c'è tetto.
     */
    private static function accountOltreIlTetto(Piano $piano, ?int $nuovoMax): int
    {
        if ($nuovoMax === null) {
            return 0;
        }

        return VistaPiattaforma::accounts()
            ->where('piano', $piano->codice)
            ->withCount('enti')
            ->get()
            ->filter(fn (Account $account) => (int) $account->enti_count > $nuovoMax)
            ->count();
    }

    /**
     * Il piano su cui si sta lavorando, risolto **dal database**.
     *
     * `$pianoInModifica` arriva dal browser: è un id, non una promessa. Si
     * rilegge a ogni azione invece di tenere il model in una property — che
     * dovrebbe attraversare lo snapshot e tornare indietro fidandosi di ciò che
     * torna.
     */
    private function pianoInLavorazione(): Piano
    {
        return Piano::query()->findOrFail($this->pianoInModifica);
    }

    /**
     * Scrive l'anagrafica e, **solo se è cambiato**, il prezzo.
     *
     * ⚠️ Il prezzo si tocca solo quando la cifra è davvero diversa, e non è
     * un'ottimizzazione: `cambiaPrezzo()` azzera `stripe_sincronizzato_at`, cioè
     * marca il piano «da risincronizzare». Chiamarlo a ogni salvataggio
     * dell'etichetta metterebbe in stato di divergenza dei piani perfettamente
     * allineati.
     */
    private function applica(Piano $modello): void
    {
        GovernoListino::aggiornaAnagrafica($modello, [
            'etichetta' => $this->modifica['etichetta'],
            'max_enti' => $this->modifica['max_enti'],
            'ordine' => (int) ($this->modifica['ordine'] === '' ? 0 : $this->modifica['ordine']),
        ]);

        $prezzo = (int) $this->modifica['prezzo_mensile_cent'];

        if ($prezzo !== (int) $modello->prezzo_mensile_cent) {
            GovernoListino::cambiaPrezzo($modello, $prezzo);
        }

        $this->pianoInModifica = null;
        $this->conferma = [];
        $this->dimenticaIlConfronto();
    }

    /**
     * ⚠️ **Dopo ogni scrittura il confronto va buttato via.** I marcatori sono
     * stati calcolati contro un valore locale che la scrittura ha appena
     * cambiato: lasciarli in pagina significherebbe mostrare una divergenza
     * inventata, ed è peggio di non mostrarne nessuna — chi legge crederebbe a
     * una cifra che nessuno ha più.
     */
    private function dimenticaIlConfronto(): void
    {
        $this->confrontato = false;
        $this->divergenze = [];
    }

    /** Una stringa vuota è `null`, cioè «illimitato»: è così che si scrive «nessun tetto» in un campo numerico. */
    private static function maxEntiDalForm(string $grezzo): ?int
    {
        return trim($grezzo) === '' ? null : (int) $grezzo;
    }
}
