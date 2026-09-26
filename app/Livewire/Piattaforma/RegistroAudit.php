<?php

namespace App\Livewire\Piattaforma;

use App\Models\User;
use App\Support\Audit\SoggettiAudit;
use App\Support\AuditLog;
use App\Support\Tenancy\VistaPiattaforma;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * 🔴 Il registro di audit della piattaforma (S6).
 *
 * `activity_log` ha dieci scrittori e — fino a questa pagina — **zero
 * lettori**: impersonazioni, login falliti, forzature semaforo, lockout,
 * accessi tecnici, scarichi di documenti, cambi di visibilità garanzie, ogni
 * scrittura di dominio del trait, e gli inviti non consegnati. ADR-012 lo
 * dichiara come gap: «nella stessa condizione di `failed_jobs`».
 *
 * **Perché una rotta a sé e non un tab della cabina.** Non è una preferenza:
 * `ElencaClienti` dichiara già `#[Url] search/sortBy/sortDir/perPage` e
 * `WithPagination` ha un `page` solo — due tabelle paginate nello stesso
 * componente **collidono**. In più la proprietà di sicurezza è per-URL: una
 * rotta separata ha il proprio assert strutturale sui middleware e i propri 403.
 *
 * ⚠️ **Gate su `tenants.view_all`, non su `audit.view`**, benché quest'ultimo
 * esista a catalogo e sembri fatto apposta. Due ragioni:
 * 1. `audit.view` **non è nel set bloccato** di `config/rbac.php`, quindi
 *    l'editor permessi di S6 potrà ridistribuirlo a chiunque: gatare una vista
 *    **cross-tenant** su un permesso ridistribuibile è una falla ad attivazione
 *    differita. `tenants.view_all` è nel set bloccato, e per questo regge.
 * 2. `audit.view` ce l'ha già **l'Admin** (`config/rbac.php`, non è fra le sue
 *    eccezioni), e lo Schema Ruoli §157 lo annota come limitato al proprio Ente:
 *    è il permesso della **futura vista per-cliente**, non di questa. Usarlo qui
 *    gliela toglierebbe di mano.
 *
 * Metterli in AND sarebbe peggio di uno solo: non aggiunge protezione (chi passa
 * il primo ha già il secondo) e crea un modo di rompere la pagina — revocare
 * `audit.view` al Superadmin dall'editor darebbe un 403 su una schermata di
 * piattaforma per una ragione che non c'entra col confine.
 *
 * ⚠️ **Attribuzione**: fino a `AuditLog::ATTRIBUZIONE_AFFIDABILE_DA` le righe
 * scritte durante un'impersonazione nominano l'**impersonato** e non chi agiva
 * davvero — lab404 sostituisce l'utente della guard, e `CauserResolver` legge
 * quello. Da quella data le righe scritte **dentro una richiesta** portano
 * `impersonato_da`; quelle differite (code, console, webhook) no, perché lì non
 * c'è una sessione da interrogare. Lo storico non è recuperabile, e la pagina
 * dichiara entrambi i limiti invece di lasciarli dedurre.
 *
 * ⚠️ **Il costo è piatto sulle righe, lineare sui tipi in pagina.** Una query
 * per `activity_log`, una per il conteggio, una per il causer, e **una per ogni
 * tipo di soggetto presente** più quelle delle relazioni annidate: con sei tipi
 * sono dieci query, con dieci circa quindici. È il prezzo del `morphTo`, ed è
 * quello giusto — ma il nome del test («piatto da due righe a quaranta») invita
 * a leggerlo come costante, e non lo è.
 *
 * ⚠️ **Nessun filtro categoriale è costruito su `description`**, e non è una
 * scelta di stile: quella colonna è **testo libero**. Ci sono descrizioni
 * interpolate («Unito il ricambio «X» in «Y»»), un participio che concorda col
 * genere del sostantivo e frasi che sono state riscritte nel tempo — un
 * raggruppamento fondato lì si rompe in silenzio al primo ritocco di una
 * stringa. Il raggruppamento passa da `event` (NULL sugli atti, uno dei quattro
 * verbi sulle scritture del trait) e da `subject_type`. «Cerca» tocca
 * `description`, ma è una **ricerca**, non un raggruppamento: se non trova nulla
 * lo si vede subito.
 */
#[Layout('components.layouts.app')]
class RegistroAudit extends Component
{
    use WithPagination;

    #[Url]
    public string $sortDir = 'desc';

    /**
     * Il verbo dell'evento, o la sentinella degli **atti**.
     *
     * `''` = tutte le righe; `self::ATTI` = le righe senza `event`; altrimenti
     * una delle quattro chiavi di `AuditLog::VERBI`.
     */
    #[Url]
    public string $azione = '';

    /** Il `subject_type`, per FQCN, fra quelli che `SoggettiAudit` sa nominare. */
    #[Url]
    public string $soggetto = '';

    /** Nome o email di chi ha agito. */
    #[Url]
    public string $chi = '';

    /** Estremi del periodo, `Y-m-d`, **inclusivi entrambi**. */
    #[Url]
    public string $dal = '';

    #[Url]
    public string $al = '';

    /** Testo libero su `description`. */
    #[Url]
    public string $cerca = '';

    /** Solo le righe senza utente autenticato: login falliti, console, webhook, coda. */
    #[Url]
    public bool $senzaUtente = false;

    /** L'id della riga espansa, o `null`. Una alla volta: due aprono una lista, non un dettaglio. */
    public ?int $espanso = null;

    /**
     * Un filtro ha davvero ristretto **questo** render?
     *
     * Serve al messaggio del vuoto, che senza accusa i filtri anche quando non
     * ce ne sono: «Nessuna riga con questi filtri» davanti a un registro
     * genuinamente vuoto manda a cercare un filtro da togliere che non esiste.
     *
     * ⚠️ La alza `filtrata()` ramo per ramo, e **non** un secondo controllo sulle
     * property: un valore rifiutato dalla whitelist — un `soggetto` arbitrario
     * dalla query string — non restringe niente, e contarlo come filtro
     * spiegherebbe il vuoto con una causa che non c'è. Due copie della stessa
     * condizione divergono, e il giorno in cui divergono la pagina dà la colpa
     * al posto sbagliato.
     */
    private bool $filtriApplicati = false;

    /**
     * Il valore del filtro Azione che isola gli **atti** — le righe con `event`
     * a NULL.
     *
     * Una sentinella e non `null`, perché `#[Url]` porta la property in query
     * string come stringa: «nessun evento» e «tutti gli eventi» sarebbero
     * entrambi `''` e il filtro non saprebbe distinguerli. Stessa forma di
     * `ElencaClienti::FUORI_CATALOGO`.
     */
    public const ATTI = '__atto';

    private const PER_PAGE = 25;

    public function updatingAzione(): void
    {
        $this->riparti();
    }

    public function updatingSoggetto(): void
    {
        $this->riparti();
    }

    public function updatingChi(): void
    {
        $this->riparti();
    }

    public function updatingDal(): void
    {
        $this->riparti();
    }

    public function updatingAl(): void
    {
        $this->riparti();
    }

    public function updatingCerca(): void
    {
        $this->riparti();
    }

    public function updatingSenzaUtente(): void
    {
        $this->riparti();
    }

    /**
     * Cosa succede quando un filtro cambia.
     *
     * `resetPage()` per la ragione di sempre: restare a pagina 3 di un elenco
     * che ora ne ha una sola mostra il vuoto.
     *
     * ⚠️ La riga espansa si chiude **per cortesia, non per protezione**, e la
     * differenza va detta o la prossima persona crederà che togliere questa riga
     * rompa qualcosa. Una scheda senza la propria intestazione è impossibile
     * comunque: il `<tr>` del dettaglio vive *dentro* il ciclo sulle righe in
     * pagina, quindi una riga filtrata via non lascia un dettaglio orfano —
     * semplicemente non si rende. Ciò che questa riga evita è che il dettaglio
     * si **riapra da solo** togliendo il filtro, su una riga che nessuno ha più
     * chiesto. `inverti()` non la chiama di proposito: cambia l'ordine, non
     * l'insieme, e chi ha aperto una riga se la ritrova aperta.
     */
    private function riparti(): void
    {
        $this->resetPage();
        $this->espanso = null;
    }

    /**
     * Apre o chiude il dettaglio di una riga.
     *
     * ⚠️ **Rilegge dalla porta** benché non usi ciò che rilegge: l'id arriva dal
     * browser, e la rilettura è ciò che rende irraggiungibile una riga di un
     * altro `log_name` — il pavimento di `VistaPiattaforma::audit()` vive lì
     * dentro. Vale la disciplina di `ElencaClienti::espandi()`: ogni azione che
     * accetta un id chiede il permesso per conto proprio, perché il `render()`
     * che oggi gata le letture gira **dopo** l'azione, e con `skipRender()` non
     * girerebbe affatto.
     */
    public function espandi(int $id): void
    {
        VistaPiattaforma::audit()->whereKey($id)->firstOrFail();

        $this->espanso = $this->espanso === $id ? null : $id;
    }

    /**
     * L'altra strada per cambiare riga espansa: la property, che Livewire
     * accetta dal browser **senza passare da `espandi()`**.
     *
     * Si chiude con la stessa rilettura dell'azione, o le due strade direbbero
     * cose diverse sullo stesso gesto.
     */
    public function updatingEspanso(mixed $valore): void
    {
        if ($valore !== null) {
            VistaPiattaforma::audit()->whereKey($valore)->firstOrFail();
        }
    }

    /** Le voci del filtro Azione: la costante `VERBI` non è raggiungibile da Blade. */
    public function verbi(): array
    {
        return AuditLog::VERBI;
    }

    /**
     * I tipi di soggetto offerti dal filtro, FQCN → sostantivo.
     *
     * Dalla mappa di `SoggettiAudit` e non da un `distinct` su `subject_type`:
     * quel `distinct` è una scansione della tabella più grande del progetto a
     * ogni render, e restituirebbe anche i tipi **orfani** — classi cancellate
     * che l'utente non saprebbe interpretare.
     *
     * @return array<class-string, string>
     */
    public function tipiSoggetto(): array
    {
        return collect(SoggettiAudit::tipi())->map(fn (array $v) => $v[0])->sort()->all();
    }

    /**
     * Inverte l'ordine cronologico.
     *
     * È l'**unico** ordinamento offerto, e non è una semplificazione: un
     * registro è cronologico, e ordinarlo per descrizione o per soggetto
     * risponderebbe a una domanda che nessuno si pone davanti a un audit. La
     * direzione invece è una scelta vera — «dal più vecchio» serve a ricostruire
     * una sequenza.
     */
    public function inverti(): void
    {
        $this->resetPage();

        $this->sortDir = $this->direzione() === 'desc' ? 'asc' : 'desc';
    }

    /**
     * La direzione **effettivamente applicata**.
     *
     * La vista non deve leggere `$sortDir`: arriva dalla query string e può
     * valere qualunque cosa, mentre la query ha usato il fallback. Una freccia
     * che indica il contrario dell'ordine applicato è una bugia piccola, e per
     * questo credibile.
     */
    public function direzione(): string
    {
        return mb_strtolower($this->sortDir) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * La porta, coi cinque filtri e la scorciatoia applicati.
     *
     * ⚠️ **Ogni valore arriva dalla query string** — sono tutti `#[Url]`, perché
     * una vista filtrata si manda per link, che è come si chiede aiuto su un
     * caso — quindi nessuno di essi è passato da `updatingXxx()` e nessuno è
     * fidato. Le due select si validano contro le rispettive mappe, i due testi
     * si neutralizzano dai jolly di LIKE, le due date si scartano se non si
     * lasciano leggere. È la lezione di `ElencaClienti`, dove la whitelist copre
     * tutte le property e non solo quelle toccate dai click.
     *
     * @return Builder<Activity>
     */
    private function filtrata(): Builder
    {
        // Azzerato a ogni giro: `filtrata()` gira una volta per render, e questo
        // valore descrive **quel** render.
        $this->filtriApplicati = false;

        // La porta gira **a ogni render** e prima di ogni filtro: è ciò che
        // rende il 403 provabile senza middleware, cioè su `Livewire::test()`.
        $query = VistaPiattaforma::audit();

        // **Azione.** `event` è la chiave di raggruppamento *vera*: si popola
        // solo da `->event()`, che chiama il solo trait, quindi le diciotto
        // righe esplicite sono NULL e quelle di dominio portano i quattro verbi.
        // La sentinella isola le prime — «gli atti» — e senza di lei non ci
        // sarebbe modo di chiederle, perché `''` significa già «tutte».
        if ($this->azione === self::ATTI) {
            $query->whereNull('event');
            $this->filtriApplicati = true;
        } elseif ($this->azione !== '' && array_key_exists($this->azione, AuditLog::VERBI)) {
            $query->where('event', $this->azione);
            $this->filtriApplicati = true;
        }

        // **Soggetto.** `array_key_exists` sulla mappa e non un `where` diretto:
        // il valore finisce in una clausola e viene dalla query string.
        if ($this->soggetto !== '' && array_key_exists($this->soggetto, SoggettiAudit::tipi())) {
            $query->where('subject_type', $this->soggetto);
            $this->filtriApplicati = true;
        }

        if (trim($this->chi) !== '') {
            // **Sottoquery inline** e non un join: `causer` è un `morphTo`, e un
            // join andrebbe scritto con la condizione sul tipo a mano. Il
            // `where` su `causer_type` non è ridondante rispetto alla `whereIn`:
            // senza, un `SpostamentoStrumento` con lo stesso id numerico di un
            // utente omonimo entrerebbe in elenco.
            $query->where('causer_type', (new User)->getMorphClass())
                // ⚠️ `withTrashed()`: da 🔗 ADR-038 una persona si cestina, e
                // il registro di audit è **il** posto in cui deve continuare a
                // essere nominabile. Senza, cercare per nome chi non lavora più
                // qui non troverebbe nulla — cioè proprio la ricerca per cui
                // il registro esiste.
                ->whereIn('causer_id', User::withTrashed()
                    ->where(function ($q) {
                        foreach (['name', 'email'] as $colonna) {
                            // `LOWER(...) LIKE` e non `ILIKE`: quest'ultimo è
                            // solo Postgres e la suite gira anche su SQLite.
                            // `ESCAPE` dichiarato perché SQLite, a differenza di
                            // Postgres, non ne ha uno di default — e senza la
                            // neutralizzazione dei jolly un `%` battuto da solo
                            // restituirebbe **tutte** le righe con un causer,
                            // cioè il contrario di un filtro.
                            //
                            // ⚠️ Sui due driver **non trova le stesse righe**:
                            // vedi `jolly()`.
                            $q->orWhereRaw("LOWER({$colonna}) LIKE ? ESCAPE '\\'", ['%'.$this->jolly($this->chi).'%']);
                        }
                    })
                    ->select('id'));

            $this->filtriApplicati = true;
        }

        // **Dal / Al.** ⚠️ Mai `whereDate()`, e mai `<= $al`. Il primo avvolge la
        // colonna in una funzione (`strftime` su SQLite, `::date` su Postgres):
        // l'indice `(created_at, id)` diventa inutilizzabile e la semantica
        // cambia col driver. Il secondo taglia via l'intera giornata di `$al`
        // tranne la sua mezzanotte, perché `created_at` è un timestamp e
        // `'2026-08-20'` si legge come `2026-08-20 00:00:00`. Il confine giusto
        // è **mezzo aperto**: `>= dal` e `< al + 1 giorno`, che include tutta la
        // giornata finale senza toccare quella dopo.
        if ($confine = $this->giorno($this->dal)) {
            $query->where('created_at', '>=', $confine);
            $this->filtriApplicati = true;
        }

        if ($confine = $this->giorno($this->al)) {
            $query->where('created_at', '<', $confine->copy()->addDay());
            $this->filtriApplicati = true;
        }

        // **Cerca.** ⚠️ È l'**unico predicato non indicizzato** della pagina: un
        // `LIKE '%…%'` su `description` non usa alcun indice e forza una
        // scansione. È accettato di proposito — è una ricerca occasionale su una
        // tabella che si consulta di rado — ma chi un giorno la trovasse lenta
        // sappia che è questa riga, e non i filtri qui sopra, che tutti cadono
        // su colonne indicizzate o su chiavi.
        if (trim($this->cerca) !== '') {
            $query->whereRaw("LOWER(description) LIKE ? ESCAPE '\\'", ['%'.$this->jolly($this->cerca).'%']);
            $this->filtriApplicati = true;
        }

        // **La scorciatoia.** Login falliti, comandi di console, webhook e code:
        // sono le righe che contano di più in un registro di sicurezza, e sono
        // esattamente quelle che nessun filtro per persona può raggiungere.
        // Senza una casella dedicata si trovano solo scorrendo.
        if ($this->senzaUtente) {
            $query->whereNull('causer_id');
            $this->filtriApplicati = true;
        }

        return $query;
    }

    /**
     * Un termine di ricerca reso innocuo per LIKE.
     *
     * Senza, `%` da solo restituisce ogni riga e `_` diventa «un carattere
     * qualunque»: un filtro che si aggira digitando un carattere non è un
     * filtro. Stessa forma di `ElencaClienti`.
     *
     * ⚠️ **La ricerca accentata trova su Postgres e non su SQLite**, ed è la
     * stessa divergenza che `ElencaClienti` documenta. Qui il termine passa da
     * `mb_strtolower()` (UTF-8-aware) e la colonna da `LOWER()` del driver, che
     * su SQLite converte **solo A–Z**: cercare «società» o «perché» trova in
     * produzione e in CI, **non in locale**. Misurato su entrambi i driver con
     * la stessa riga. È la direzione insolita — un test onesto su un accento
     * nascerebbe rosso sulla macchina di sviluppo — e su un progetto in italiano
     * non è un caso di scuola. Chi vorrà chiuderla dovrà normalizzare gli accenti
     * a monte, non cambiare la funzione.
     */
    private function jolly(string $termine): string
    {
        return addcslashes(mb_strtolower(trim($termine)), '%_\\');
    }

    /**
     * La mezzanotte di una data, o `null` se non si lascia leggere.
     *
     * **Si ignora in silenzio invece di lanciare**: il valore arriva dalla query
     * string, e un link con `?dal=ieri` deve mostrare il registro senza quel
     * filtro, non una pagina di errore. Un input `type=date` non produce niente
     * di diverso da `Y-m-d`, quindi il caso non si presenta dall'interfaccia.
     */
    private function giorno(string $valore): ?CarbonImmutable
    {
        if (trim($valore) === '') {
            return null;
        }

        try {
            $giorno = CarbonImmutable::parse(trim($valore))->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }

        // Anni fuori da 1..9999 si ignorano come un valore illeggibile
        // (T1cB-7): Carbon legge «0000-01-01» e «+100000000 years», Postgres
        // no, e un link incollato darebbe un 500 invece dell'elenco.
        return $giorno->year >= 1 && $giorno->year <= 9999 ? $giorno : null;
    }

    public function render(): View
    {
        $direzione = $this->direzione();

        $righe = $this->filtrata()
            ->orderBy('created_at', $direzione)
            // ⚠️ **Il tie-break non è prudenza.** I timestamp si serializzano al
            // secondo, e login e impersonazione vengono scritti nella *stessa*
            // richiesta: i pari sono la norma. Senza, la paginazione perde e
            // ripete righe fra una pagina e l'altra — e su un registro una riga
            // persa è precisamente ciò che non deve succedere.
            ->orderBy('id', $direzione)
            ->paginate(self::PER_PAGE)
            ->onEachSide(1);

        // ⚠️ L'eager load si applica alle **sole righe risolvibili**, e non con
        // un `with()` sulla query: `MorphTo::getEager()` itera i tipi presenti e
        // `createModelByType()` va in **fatal** su una classe che non esiste
        // più. `activity_log` è append-only e sopravvive alle proprie classi
        // (`LetturaContaore` è stata cancellata in S3): una riga orfana
        // basterebbe a far cadere l'intera pagina.
        //
        // Le righe escluse restano con la relazione **non caricata**, ed è per
        // questo che `SoggettiAudit::etichetta()` non tocca mai `->subject`
        // senza aver prima verificato la classe: altrimenti il lazy load
        // rifarebbe esattamente il fatal che questa riga evita.
        SoggettiAudit::righeRisolvibili($righe->getCollection())
            ->load(['subject' => SoggettiAudit::vincolo()]);

        // ⚠️ **Anche il causer**, o la colonna «Chi» fa una query per riga —
        // venticinque per pagina. Non serve togliergli scope (`User` non ne ha,
        // ed è dichiarato in `TenantScopeGuardrailTest::NON_TENANT_MODELS`),
        // serve solo caricarlo. Vale lo stesso filtro del soggetto: `causer` è
        // un `morphTo` come l'altro, e oggi punta sempre a `User`, ma «oggi» non
        // è una garanzia su una tabella che sopravvive alle proprie classi.
        SoggettiAudit::conCauserRisolvibile($righe->getCollection())->load('causer');

        // «per conto di» deve dire un **nome**, non un id: la verifica del piano
        // chiede di leggere «attribuita all'Admin con per conto di il
        // Developer», e «#12» non è quello. Una query sola per pagina, sugli id
        // raccolti — resta O(1).
        // ⚠️ `withTrashed()` per la ragione del filtro «chi» qui sopra: un
        // impersonatore che ha lasciato l'azienda deve restare un nome, o le
        // righe più delicate del registro tornerebbero a dire un id.
        $impersonatori = User::withTrashed()->whereIn(
            'id',
            $righe->getCollection()->map(fn ($r) => $r->properties?->get('impersonato_da'))->filter()->unique()
        )->pluck('name', 'id');

        return view('livewire.piattaforma.registro-audit', [
            'righe' => $righe,
            'direzione' => $direzione,
            'impersonatori' => $impersonatori,
            // Dopo `filtrata()`, che è ciò che la valorizza.
            'filtriApplicati' => $this->filtriApplicati,
        ]);
    }
}
