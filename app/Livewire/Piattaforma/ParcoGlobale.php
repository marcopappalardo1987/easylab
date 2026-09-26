<?php

namespace App\Livewire\Piattaforma;

use App\Enums\StatoSemaforo;
use App\Livewire\Piattaforma\Concerns\OffreImpersonazione;
use App\Models\Account;
use App\Models\Garanzia;
use App\Models\Strumento;
use App\Support\Piani;
use App\Support\Piattaforma\ParcoClienti;
use App\Support\Piattaforma\Perimetro;
use App\Support\Piattaforma\Preferiti;
use App\Support\Piattaforma\RigheParcoStrumenti;
use App\Support\Piattaforma\StrumentiPerStato;
use App\Support\Piattaforma\TitoloPerimetro;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Il parco **macchine** di tutti i clienti, in sola lettura (🔗 ADR-037).
 *
 * È la stessa domanda di `App\Livewire\Strumenti\ElencoStrumenti` — «che
 * macchine ci sono e come stanno» — allargata oltre il proprio Ente. Filtri,
 * paginazione e semaforo riusano le convenzioni di quell'elenco invece di
 * inventarne di nuove; ciò che si aggiunge è il **perimetro**, e ciò che si
 * toglie è ogni azione che scriva.
 *
 * ## ⛔ Da qui si GUARDA. Si scrive impersonando.
 *
 * Nessun bottone di questa pagina modifica alcunché: ogni modifica passa
 * dall'impersonazione, che è **per cliente** e lascia nel registro di audit chi
 * agiva e per conto di chi (🔗 ADR-018, ADR-027). Una scrittura cross-cliente
 * perderebbe quel contesto proprio dove serve di più. Il tasto «Impersona»
 * accanto a ogni riga è quindi la **funzione** della pagina e non un ornamento:
 * è ciò che rende quel confine rapido invece che fastidioso.
 *
 * ⚠️ Il tasto riusa `OffreImpersonazione`, lo stesso trait della cabina: la
 * scelta del membro quando i membri sono più d'uno, il Developer mai
 * impersonabile, «chi sta già impersonando non impersona nessuno» sono decisioni
 * che vivono là e non vanno riscritte qui.
 *
 * ## Di CHI sono i dati: la colonna che non si può togliere
 *
 * 🔴 Cliente e sede stanno **su ogni riga**, ed è il primo requisito posto dal
 * committente. Non è cosmesi: su una vista cross-cliente il rischio non è
 * sbagliare macchina, è sbagliare **cliente** — e chi impersona dalla riga
 * sbagliata entra in casa di qualcun altro. Un test lo congela.
 *
 * I due nomi arrivano da due `leftJoin` e non da un `with()`: `Strumento::tenant`
 * e `Strumento::unita` puntano a `UnitaOrganizzativa`, che porta `TenantScope` —
 * un eager load li risolverebbe **a NULL** per ogni cliente che non è il
 * proprio, cioè per quasi tutta la pagina, e la colonna direbbe «—» senza
 * lamentarsi. La join è `left` e non `inner` di proposito: **non filtra**, e non
 * deve poterlo fare. Il perimetro l'ha già applicato la porta
 * (`whereIn strumenti.tenant_id`), le due `ON` sono su chiave primaria — quindi
 * non duplicano — e una `inner` potrebbe solo far **sparire** righe in silenzio,
 * che su una vista di sorveglianza è il guasto peggiore.
 *
 * ## Il semaforo: si MOSTRA riga per riga, si FILTRA in SQL
 *
 * ⛔ `Strumento::conStato()`, `ordinaPerStato()` e `obsoleti()` sono la forma SQL
 * della regola e compongono sottoquery **scopate per tenant**: su questo elenco
 * classificherebbero come verdi le macchine altrui. Il semaforo si **mostra**
 * quindi con `Semaforo::calcola()` sulle sole righe in pagina, via
 * `RigheParcoStrumenti` — tre query costanti, la regola resta una sola.
 *
 * ⚠️ Ma mostrare e filtrare sono due domande diverse, e la seconda non si può
 * rispondere sulla pagina: filtrare in PHP **dopo** `paginate()` darebbe pagine
 * di dimensione variabile, un totale che conta anche le righe scartate e pagine
 * vuote nella barra in fondo. I due filtri di stato chiesti da Marco il 29 Ago
 * 2026 — il semaforo (🔗 ADR-005) e l'obsolescenza (🔗 ADR-014), che sono due
 * **assi diversi** e restano due controlli distinti — vivono perciò in
 * `StrumentiPerStato`, che costruisce le tre fonti dell'arancione **non
 * scopate** e dentro il perimetro. Il debito che ne nasce — la composizione
 * esiste in due copie — è dichiarato là, e legato da due test differenziali.
 *
 * ## Il perimetro «i miei preferiti» (🔗 ADR-037)
 *
 * 🔴 Il modo «quelli che scelgo» **non esiste più a schermo**. Era una
 * `<select multiple>` che non ricordava nulla fra una visita e l'altra: i
 * clienti che si guardano spesso sono pochi e sempre gli stessi, e ricomporli a
 * mano a ogni apertura era il costo dell'unica funzione per cui questa pagina
 * esiste. Al suo posto c'è un insieme **durevole e per-persona**, che si segna
 * con la ★ dall'elenco Clienti della cabina e si riusa da qui.
 *
 * ⛔ Ne discende che gli id del perimetro **non arrivano più dal browser**:
 * `Perimetro::daRichiesta()` non sa costruire il modo preferiti apposta, e
 * l'unico costruttore è `Preferiti::perimetro()`, che è gato. `perimetro()` qui
 * sotto è quindi l'unico punto in cui i due mondi si incontrano.
 *
 * ⚠️ **Il modo `scelti` resta valido nel dominio e non lo è più nel controllo.**
 * `Perimetro::nessuno()` è ancora `scelti([])`, cioè l'insieme vuoto su cui cade
 * ogni input incompreso; ma una `<select>` legata a un valore che non
 * corrisponde a nessuna `<option>` non resta vuota — il browser evidenzia la
 * **prima**, cioè «Tutti i clienti», sopra una tabella che tutti i clienti non
 * li mostra, e da lì non si esce perché riselezionare la prima voce non emette
 * nessun evento. Il perché per esteso sta in `ParcoRicambi`, che ha pagato per
 * primo quel difetto. Qui la normalizzazione porta a `preferiti` e non a
 * `tutti`: è fail-closed (l'insieme può essere vuoto) e manda a un controllo che
 * è davvero a schermo.
 *
 * ## Il costo
 *
 * Query **costanti** al crescere delle righe: la pagina, il suo conteggio, le
 * tre fonti del semaforo sulle sole righe in pagina, gli account della pagina
 * (per l'impersonazione) e — **solo nel modo che li disegna** — i preferiti.
 * L'elenco di *tutti* gli account non si legge più: serviva alla tendina
 * scomparsa, e girava a ogni render, anche nel modo «tutti» dove nessun
 * controllo lo mostrava. Nessuna sottoquery correlata
 * per riga — l'elenco per-Ente ne ha tre nell'ordinamento per stato e per
 * prossima scadenza, ed è il debito che la roadmap gli imputa: qui quelle due
 * colonne **non sono ordinabili**, così il debito non viene moltiplicato per il
 * numero di clienti. Un test conta le query e le confronta fra una pagina da
 * poche righe e una da molte.
 *
 * 🔗 ADR-005 (semaforo), ADR-004/ADR-020 (le due fonti garanzia), ADR-018
 * (nessun bypass di tenancy), ADR-037 (la porta del parco).
 */
#[Layout('components.layouts.app')]
class ParcoGlobale extends Component
{
    use OffreImpersonazione, WithPagination;

    /**
     * Il permesso è `tenants.view_all`, lo stesso della cabina: significa
     * letteralmente «vedi oltre il tuo Ente», è già del solo Developer/Superadmin
     * ed è nel set bloccato (🔗 ADR-016). Nessun permesso nuovo, nessun riseeding.
     */
    public const PERMESSO = VistaPiattaforma::PERMESSO;

    /**
     * Il perimetro, in due proprietà che arrivano dal **browser**.
     *
     * ⛔ Non sono input fidati e non vengono usate mai direttamente: passano da
     * `Perimetro::daRichiesta()`, che normalizza la forma, e poi da
     * `ParcoClienti::clienti()`, che interseca gli id con l'insieme legittimo.
     * Un modo che non esiste o un piano fuori catalogo non allargano: cadono su
     * `nessuno()`.
     *
     * 🔴 E **nessuna lista di id**: dal 29 Ago 2026 la scelta a mano non ha più
     * un controllo, quindi la property che la portava è sparita invece di
     * restare inerte. Un `#[Url]` che nessun ramo può onorare è un link che
     * promette un filtro e non lo dà — e, peggio, è l'unico filo per cui il
     * browser potrebbe tornare a dettare un `whereIn`: la sua innocuità
     * dipenderebbe per intero dal fatto che la normalizzazione del modo non
     * abbia mai un buco. Tolto il filo, la garanzia è strutturale.
     */
    #[Url]
    public string $modo = Perimetro::TUTTI;

    #[Url]
    public ?string $piano = null;

    #[Url]
    public string $search = '';

    /**
     * Filtro semaforo (🔗 ADR-005): `verde` | `arancione` | `rosso`.
     *
     * Arriva dalla query string come tutto il resto, quindi non si usa mai
     * grezzo: passa da `statoScelto()`, che è **l'unico** punto in cui la
     * stringa diventa un `StatoSemaforo` — e lo stesso punto da cui
     * `haFiltriAttivi()` decide se annunciarlo. Un valore ignoto non filtra e
     * non viene annunciato: le due risposte non possono divergere perché sono
     * la stessa chiamata. Nell'elenco per-Ente sono due liste scritte a mano,
     * ed erano già divergute (`?stato=giallo` mandava a togliere un filtro che
     * il componente aveva già scartato).
     */
    #[Url]
    public ?string $stato = null;

    /**
     * Filtro obsolescenza (🔗 ADR-014): asse **diverso** dal semaforo, non un
     * suo quarto valore. Una macchina vecchia e in regola è verde e obsoleta, e
     * fonderli in una tendina sola renderebbe quella coppia inesprimibile —
     * oltre a contraddire il badge ⏳, che nel Design System §4 convive col
     * pallino invece di sostituirlo. Due controlli, quindi, componibili.
     */
    #[Url]
    public bool $soloObsoleti = false;

    #[Url]
    public string $sortBy = 'cliente';

    #[Url]
    public string $sortDir = 'asc';

    /**
     * Righe per pagina. Arriva dalla query string, quindi è input dell'utente e
     * va validato contro `PER_PAGE`: un `?perPage=999999` chiederebbe al DB il
     * parco intero di ogni cliente in una volta sola.
     */
    #[Url]
    public int $perPage = self::PER_PAGE_DEFAULT;

    /** Scelte ammesse: il massimo è 100 righe per pagina. */
    private const PER_PAGE = [20, 50, 100];

    private const PER_PAGE_DEFAULT = 20;

    /**
     * Le colonne ordinabili, e sono **tutte colonne vere**.
     *
     * ⚠️ `stato` e `prossima scadenza` non ci sono: sono derivate, e ordinarle
     * richiederebbe le sottoquery correlate per riga di `ElencoStrumenti`
     * (scopate, quindi sbagliate qui) o una loro copia non-scopata, cioè una
     * seconda regola del semaforo. `cliente` e `sede` sono ordinabili perché
     * la join le rende colonne, non sottoquery.
     */
    private const SORTABLE = ['cliente', 'sede', 'nome', 'modello', 'matricola'];

    private const SORT_DEFAULT = 'cliente';

    /**
     * La macchina della riga da cui la scelta del membro è stata aperta, o
     * `null` se l'apertura non ne nominava nessuna.
     *
     * ⚠️ **Non è un'autorizzazione, ed è importante che non lo sembri.** Arriva
     * dal browser come ogni property, e un id forgiato non allarga niente: la
     * rotta di atterraggio ha le proprie guardie (le quattro del pacchetto più
     * il permesso del parco) e, se dopo l'impersonazione quella macchina non è
     * visibile all'impersonato, atterra in dashboard **dicendolo** invece di
     * dare un 404. Il peggio che un valore inventato ottiene è quindi il
     * messaggio «quella macchina non è di questa sede» — e i sette test di
     * `ImpersonaVersoStrumentoTest` lo congelano.
     */
    public ?int $macchinaScelta = null;

    /**
     * Chiude ogni modale della pagina.
     *
     * Richiesto da `OffreImpersonazione::apriScelta()`, che lo chiama per
     * mantenere l'invariante «una modale alla volta». Qui la modale è una sola —
     * la scelta del membro — perché da questa pagina non si amministra nulla:
     * la sola azione è entrare in casa di un cliente, e quella passa da un GET.
     */
    public function chiudiOgniModale(): void
    {
        $this->sceltaImpersonazione = null;

        // 🔴 Azzerata **qui** e non solo in `apriSceltaSuMacchina()`, e non è
        // simmetria: `OffreImpersonazione::apriScelta()` chiama questo metodo
        // prima di aprire, quindi il `call('apriScelta', …)` — l'apertura che
        // non nomina nessuna macchina — riparte da `null` invece di ereditare
        // quella della riga precedente.
        //
        // ⛔ **Ma questa riga da sola non basta, e per un anno di lettura ha
        // dichiarato di bastare.** La modale ha TRE strade di apertura e solo
        // questa passa di qui: `updatingSceltaImpersonazione()` — la property
        // spinta dal browser, che il trait documenta come reale e difende col
        // Gate — chiede il permesso, rilegge l'account e ritorna, senza toccare
        // la macchina. Le altre due sono chiuse da `updatedSceltaImpersonazione()`
        // e da `chiudiScelta()` qui sotto.
        $this->macchinaScelta = null;
    }

    /**
     * La macchina si dimentica anche quando il cliente cambia **dal browser**.
     *
     * 🔴 `$wire.set('sceltaImpersonazione', …)` è la seconda strada di apertura
     * della modale, dichiarata e difesa da `OffreImpersonazione`, e non passa da
     * `apriScelta()` — quindi non passa da `chiudiOgniModale()`. Senza questo
     * gancio la modale si ridisegnava col titolo e i membri del cliente B
     * tenendosi la macchina del cliente A: il link «Entra» portava a impersonare
     * un membro di B **verso una macchina di A**.
     *
     * Non è una falla di autorizzazione — la rotta di atterraggio se ne accorge
     * e manda in dashboard dicendolo — ma è una destinazione sbagliata offerta
     * **da noi**, cioè un messaggio d'errore che sembra un difetto prodotto da
     * un link che questa pagina ha appena disegnato.
     *
     * ⚠️ `updated` e non `updating`: l'hook `updating` di quella property vive
     * nel trait, che è condiviso con la cabina di regia — dove `macchinaScelta`
     * non esiste. Qui si aggiunge, non si riscrive.
     */
    public function updatedSceltaImpersonazione(): void
    {
        $this->macchinaScelta = null;
    }

    /**
     * Chiudere la modale dimentica anche la macchina.
     *
     * La terza strada verso lo stesso stato: si chiude col tasto — che nel
     * trait azzera il solo cliente — e si riapre dalla property. La macchina
     * della riga di prima sopravviveva a entrambi i gesti. Qui «chiudere la
     * scelta» e «chiudere ogni modale» sono la stessa cosa, perché la modale è
     * una sola.
     */
    public function chiudiScelta(): void
    {
        $this->chiudiOgniModale();
    }

    /**
     * I tre modi che la tendina del perimetro sa disegnare.
     *
     * ⚠️ **Non** è la terna del `match` di `Perimetro::daRichiesta()`, e le due
     * liste rispondono a domande diverse: là si chiede «quale perimetro», e
     * `preferiti` non ci compare apposta perché i suoi id non vengono dal
     * browser; qui si chiede «questo modo ha una `<option>`?», e `scelti` non ci
     * compare più perché la sua `<select multiple>` non esiste. I nomi restano
     * quelli di `Perimetro`: qui si duplica l'insieme, mai le stringhe.
     */
    private const MODI = [Perimetro::TUTTI, Perimetro::PER_PIANO, Perimetro::PREFERITI];

    /**
     * 🔴 Le due property che **disegnano un controllo** si normalizzano subito.
     *
     * `modo` non finisce solo nella query: finisce in una `<select
     * wire:model.live>`, e una `<select>` legata a un valore che non corrisponde
     * a nessuna `<option>` non resta vuota — il browser evidenzia la **prima**.
     * Con un `?modo=scelti` salvato da prima della ★, o con un `?modo=tuttissimi`
     * scritto a mano, il perimetro cadeva correttamente su `nessuno()` e la
     * pagina mostrava zero righe, ma la tendina diceva «Tutti i clienti»: un
     * controllo che mente sopra una tabella vuota. Peggio, da lì non si usciva —
     * riselezionare «Tutti i clienti» non emette nessun evento, perché il valore
     * mostrato è già quello.
     *
     * Il modo che non si è capito diventa `preferiti` e **non** `tutti`: è
     * fail-closed (l'insieme dei preferiti può benissimo essere vuoto) e manda
     * al solo controllo che, in quello stato, è davvero a schermo.
     */
    public function mount(): void
    {
        $this->normalizzaControlli();
    }

    /**
     * ⚠️ `mount()` non rigira sugli update, e le property arrivano dal browser a
     * **ogni** richiesta: senza questi due hook la normalizzazione varrebbe solo
     * per la prima pagina caricata.
     */
    public function updatedModo(): void
    {
        $this->normalizzaControlli();
    }

    public function updatedPiano(): void
    {
        $this->normalizzaControlli();
    }

    /**
     * ⚠️ **La forma è deliberatamente identica sulle tre schede del Parco.** Il
     * difetto è lo stesso — una `<select>` legata a un valore senza `<option>`
     * corrispondente — quindi la risposta non può cambiare a seconda della
     * scheda: tre schermi che normalizzano in tre modi sono tre perimetri, per
     * chi legge.
     */
    private function normalizzaControlli(): void
    {
        if (! in_array($this->modo, self::MODI, true)) {
            $this->modo = Perimetro::PREFERITI;
        }

        // Un piano fuori catalogo è già l'insieme vuoto per `Perimetro`; qui
        // torna a essere «nessun piano scelto» anche per la tendina, che
        // altrimenti terrebbe in `?piano=` un codice che nessuna `<option>`
        // nomina — e la pagina resterebbe con un filtro annunciato nell'URL e
        // nessun controllo che lo mostri.
        //
        // ⚠️ `null` e non `''`: qui la property è nullable e la `<option>`
        // vuota vale `''`, che Livewire riporta come stringa. Entrambi i valori
        // cadono su `nessuno()` in `daRichiesta()`; ricondurli a uno solo serve
        // alla tendina, che così evidenzia «— scegli un piano —».
        if ($this->piano !== null && $this->piano !== '' && ! Piani::esiste($this->piano)) {
            $this->piano = null;
        }
    }

    /**
     * Cambiare perimetro cambia l'insieme: restare a pagina 7 atterrerebbe
     * fuori dall'elenco. Vale per ognuna delle tre proprietà, e per la ricerca.
     */
    public function updatingModo(): void
    {
        $this->resetPage();
    }

    public function updatingPiano(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStato(): void
    {
        $this->resetPage();
    }

    public function updatingSoloObsoleti(): void
    {
        $this->resetPage();
    }

    /**
     * Apre la scelta del membro **ricordando la macchina della riga**.
     *
     * L'ordine conta: `apriScelta()` è la sola porta dell'impersonazione — gata
     * su `utenti.impersonate` e rilegge l'account dalla porta di piattaforma —
     * e passa da `chiudiOgniModale()`, che azzera `macchinaScelta`. La riga qui
     * sotto viene quindi **dopo**, o si azzererebbe da sé.
     *
     * ⚠️ Nessuna guardia aggiuntiva sulla macchina, di proposito: aggiungerne
     * una qui suggerirebbe che sia lei a proteggere qualcosa. La destinazione la
     * valida chi ci atterra, che è l'unico posto in cui si sa chi si è
     * diventati.
     */
    public function apriSceltaSuMacchina(int $accountId, int $strumentoId): void
    {
        $this->apriScelta($accountId);

        $this->macchinaScelta = $strumentoId;
    }

    /** Cambiando la dimensione di pagina, la pagina corrente non ha più senso. */
    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function sort(string $col): void
    {
        if (! in_array($col, self::SORTABLE, true)) {
            return;
        }

        // 🔴 Si confrontano i valori **normalizzati**, non le property grezze.
        //
        // `render()` normalizza (una colonna fuori whitelist ricade su
        // `SORT_DEFAULT`, una direzione ignota su `asc`), quindi con un
        // `?sortBy=` forgiato la freccia in intestazione dice una cosa e queste
        // due righe ne leggevano un'altra: il primo clic sulla colonna che la
        // pagina stava GIÀ mostrando ordinata la reimpostava ad `asc`, cioè non
        // faceva nulla. Segnalato dal correttore dei ricambi, che aveva lo
        // stesso difetto sul proprio `ordina()` e questo file non poteva
        // toccarlo.
        $attualeBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : self::SORT_DEFAULT;
        $attualeDir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        $this->sortBy = $col;
        $this->sortDir = $attualeBy === $col
            ? ($attualeDir === 'asc' ? 'desc' : 'asc')
            : 'asc';

        // Cambiare ordinamento rimescola tutte le righe: restare sulla pagina
        // corrente farebbe atterrare a metà elenco.
        $this->resetPage();
    }

    /**
     * Il perimetro **normalizzato**, unica via per cui le due property entrano
     * in una query.
     *
     * `daRichiesta()` e non i costruttori nominati: è la forma scritta apposta
     * per ciò che arriva dal browser, e in caso di dubbio torna `nessuno()`
     * invece di `tutti()` — su una vista cross-cliente la differenza fra le due
     * risposte a un input incomprensibile è fra zero righe e le righe di tutti.
     *
     * 🔴 L'eccezione è il modo **preferiti**, e non è un'incoerenza: i suoi id
     * non arrivano dal browser ma dal database, quindi `daRichiesta()` non sa
     * costruirlo — con `modo='preferiti'` cade su `nessuno()`. L'unico
     * costruttore è `Preferiti::perimetro()`, che chiede lo stesso permesso di
     * questa pagina e rilegge la lista di chi sta guardando. Passare qui una
     * property pubblica significherebbe riaprire, sotto un nome nuovo, la
     * selezione arbitraria che il modo sostituisce.
     */
    public function perimetro(): Perimetro
    {
        if ($this->modo === Perimetro::PREFERITI) {
            return Preferiti::perimetro();
        }

        // ⚠️ La lista di id è **vuota** per costruzione, come sulle altre due
        // schede: la scelta a mano non esiste più in questa pagina, e i due
        // modi rimasti non la leggono. Scriverci una property pubblica
        // rimetterebbe al browser, sotto un altro nome, la lista che i
        // preferiti gli tolgono — ed è la sola strada per cui questa pagina
        // potrebbe tornare ad allargare l'insieme delle righe.
        return Perimetro::daRichiesta($this->modo, [], $this->piano);
    }

    /**
     * Almeno un filtro **oltre al perimetro** è attivo.
     *
     * Serve al messaggio di elenco vuoto, e sta qui e non in Blade per la
     * ragione già pagata su `ElencoStrumenti`: una lista di filtri riscritta in
     * vista diverge dalla lista con cui i filtri vengono applicati, e manda a
     * togliere filtri che non ci sono. Il perimetro non conta come filtro: ha
     * un messaggio suo, perché «nessun cliente selezionato» e «nessuna macchina
     * per questa ricerca» sono due fatti diversi.
     */
    public function haFiltriAttivi(): bool
    {
        return filled($this->search)
            || $this->soloObsoleti
            || $this->statoScelto() !== null;
    }

    /**
     * Lo stato semaforo scelto, **normalizzato una volta sola**.
     *
     * 🔴 Filtro applicato e filtro annunciato passano entrambi di qui, e questa
     * è la ragione per cui il metodo esiste: `?stato=giallo` non filtra nulla,
     * e non deve nemmeno far dire «Nessun risultato per i filtri applicati» su
     * un elenco che filtrato non è — sarebbe mandare a togliere un filtro che
     * il componente ha già scartato. Nell'elenco per-Ente la stessa whitelist è
     * scritta in due posti, ed è divergere che le riesce meglio.
     */
    private function statoScelto(): ?StatoSemaforo
    {
        return StatoSemaforo::tryFrom((string) $this->stato);
    }

    /**
     * Il messaggio dell'elenco vuoto, che deve mandare al controllo **che è a
     * schermo**.
     *
     * 🔴 Non basta distinguere «perimetro vuoto» da «nessun risultato»: il
     * perimetro si svuota in tre modi diversi, e i controlli visibili sono
     * diversi in ognuno. Col modo «per piano» e nessun piano scelto il
     * perimetro è vuoto (`daRichiesta()` cade su `nessuno()`, che è
     * `scelti([])`), ma il multi-select dei clienti **non è renderizzato**:
     * dire «scegline almeno uno dal filtro qui sopra» manda a un controllo che
     * non c'è, cioè fa cercare un difetto. Per questo si guarda anche il modo
     * **grezzo**: è quello che decide quali filtri la vista disegna.
     *
     * ⚠️ Il conteggio è dei clienti **veri** — l'intersezione con l'insieme
     * legittimo — e non della lunghezza di una lista di id: un preferito verso
     * EasyLab o verso un account cestinato dopo la segnatura è un id che esiste
     * e non è un cliente, quindi la risposta giusta resta «non hai clienti
     * preferiti» e non «questi clienti non hanno macchine».
     *
     * 🔴 E nel modo preferiti il rimando **non è a un filtro di questa pagina**:
     * i preferiti si segnano con la ★ nell'elenco Clienti della cabina. Mandare
     * a «scegline almeno uno dal filtro qui sopra» sarebbe la stessa forma di
     * bugia del caso «per piano», con l'aggravante che qui il controllo non
     * esiste in nessuna schermata del Parco.
     */
    private function messaggioElencoVuoto(Perimetro $perimetro, int $clientiScelti): string
    {
        // ⚠️ `PREFERITI` accanto a `SCELTI`: l'insieme può essere **non vuoto**
        // di id e vuoto di clienti — tre preferiti tutti cestinati — e
        // `eNessuno()` da solo non lo vede. È lo stesso caso che l'id di EasyLab
        // produceva col multi-select.
        $nessunCliente = in_array($perimetro->modo, [Perimetro::SCELTI, Perimetro::PREFERITI], true)
            && $clientiScelti === 0;

        if ($perimetro->eNessuno() || $nessunCliente) {
            return match ($this->modo) {
                Perimetro::PER_PIANO => 'Nessun piano selezionato: scegline uno dal filtro qui sopra.',
                Perimetro::PREFERITI => 'Non hai ancora clienti preferiti: segnali con la ★ nell\'elenco Clienti.',
                default => 'Perimetro non valido: scegli i clienti dal filtro qui sopra.',
            };
        }

        return $this->haFiltriAttivi()
            ? 'Nessun risultato per i filtri applicati.'
            : 'Nessuna macchina per i clienti nel perimetro.';
    }

    /**
     * Il titolo dice **su chi** si sta guardando.
     *
     * 🔴 Un titolo statico «Strumenti di tutti i clienti» sopra una tabella
     * filtrata su un cliente solo dice il falso, e lo dice proprio sulla
     * scheda il cui primo requisito è sapere di CHI sono le righe. Il rischio
     * di questa pagina non è sbagliare macchina: è impersonare dalla riga del
     * cliente sbagliato, e ogni testo che allarga il perimetro a parole ci
     * lavora contro.
     */
    private function titolo(Perimetro $perimetro, int $clientiScelti): string
    {
        return TitoloPerimetro::componi('Strumenti', $perimetro, $clientiScelti);
    }

    /** Opzioni del select. @return list<int> */
    public function opzioniPerPage(): array
    {
        return self::PER_PAGE;
    }

    /**
     * I clienti preferiti da mostrare, e **solo nel modo che li disegna**.
     *
     * ⚠️ Qui c'era `selezionabili()`, cioè l'intera tabella `accounts` letta e
     * idratata a **ogni** render — anche nel modo «tutti», dove la tendina non
     * si disegnava; anche a ogni battuta nella casella di ricerca, che gira in
     * `wire:model.live.debounce`. Serviva al multi-select, che non c'è più:
     * `ParcoScadenzario` aveva già isolato la stessa lettura al proprio modo, e
     * qui la lettura sparisce del tutto.
     *
     * `Preferiti::clienti()` e non la relazione nuda: è già **intersecata** con
     * `VistaPiattaforma::accounts()`, quindi un preferito verso EasyLab o verso
     * un account cestinato dopo la segnatura non compare in questo elenco — che
     * è lo stesso insieme su cui la tabella qui sotto è costruita. Due
     * definizioni diverse di «preferito» darebbero un conteggio che non
     * corrisponde a nessuna riga.
     *
     * @return Collection<int, Account>
     */
    private function preferiti(): Collection
    {
        return $this->modo === Perimetro::PREFERITI
            ? Preferiti::clienti()
            : new Collection;
    }

    /**
     * La query della pagina: le macchine del perimetro, coi nomi di cliente e
     * sede attaccati.
     *
     * @return Builder<Strumento>
     */
    private function macchine(Perimetro $perimetro): Builder
    {
        $query = ParcoClienti::strumenti($perimetro)
            // ⚠️ `strumenti.*` e non `*`: con due join in mezzo, `*` porterebbe
            // in dote le colonne omonime di `unita_organizzativa` e `accounts`
            // (`id`, `nome`, `created_at`) sovrascrivendo quelle della macchina.
            ->select([
                'strumenti.*',
                'sede.nome as sede_nome',
                // ⏳ La soglia di obsolescenza viaggia **con la riga** (🔗 ADR-014).
                // Il badge non può chiederla al model: `sogliaObsolescenza()`
                // passa da `$this->tenant`, relazione scopata che cross-cliente
                // risolve a NULL e ricade sul default 10 — direbbe «oltre la
                // soglia di 10 anni» proprio sulle sedi che ne hanno scelta
                // un'altra, cioè contraddirebbe il filtro sulla stessa riga. La
                // join la ha già in tavola: costa una colonna, non una query.
                'sede.soglia_obsolescenza_anni as sede_soglia',
                'cliente.id as cliente_id',
                'cliente.ragione_sociale as cliente_nome',
            ])
            ->leftJoin('unita_organizzativa as sede', 'sede.id', '=', 'strumenti.tenant_id')
            ->leftJoin('accounts as cliente', 'cliente.id', '=', 'sede.account_id')
            // `forcedBy` è un `User`, che non ha global scope di tenancy: si può
            // caricare cross-cliente e serve al tooltip della pill «forzato».
            ->with('forcedBy');

        if (filled($this->search)) {
            // Colonne QUALIFICATE: con `sede.nome` in gioco, un `nome` nudo è
            // un'ambiguità che Postgres rifiuta e SQLite risolve a caso.
            // `%` e `_` scritti dall'utente sono letterali, come nelle altre ricerche (ESCAPE).
            $like = '%'.addcslashes(mb_strtolower(trim($this->search)), '%_\\').'%';

            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw("LOWER(strumenti.nome) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(strumenti.modello, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(strumenti.matricola, '')) LIKE ? ESCAPE '\\'", [$like]);
            });
        }

        // ⛔ I due filtri di stato si applicano **prima** di `paginate()`, e
        // passano da `StrumentiPerStato` invece che da `Strumento::conStato()` /
        // `->obsoleti()`: quelli compongono sottoquery scopate per tenant e su
        // un builder cross-cliente direbbero «verde» per le macchine altrui.
        // Il perché per esteso sta nel docblock di quella classe.
        $stato = $this->statoScelto();

        if ($stato !== null) {
            StrumentiPerStato::semaforo($query, $stato, $perimetro);
        }

        if ($this->soloObsoleti) {
            StrumentiPerStato::obsoleti($query, $perimetro);
        }

        return $query;
    }

    /**
     * Applica l'ordinamento scelto, **col tie-break sull'id**.
     *
     * 🔴 L'ultima chiave è sempre `strumenti.id`, e non è pignoleria: a parità
     * di chiave l'ordine fra due pagine è una proprietà del motore — SQLite
     * scansiona in modo stabile, Postgres può riordinare i pari fra la query di
     * pagina 1 e quella di pagina 2, e una riga esce da **entrambe**. Qui pesa
     * più che altrove: ordinando per «cliente» i pari sono tutte le macchine di
     * uno stesso cliente, cioè quasi tutta la pagina.
     *
     * @param  Builder<Strumento>  $query
     */
    private function applicaOrdinamento(Builder $query, string $sortBy, string $sortDir): void
    {
        $colonna = match ($sortBy) {
            'cliente' => 'cliente.ragione_sociale',
            'sede' => 'sede.nome',
            default => 'strumenti.'.$sortBy,
        };

        $query->orderBy($colonna, $sortDir)->orderBy('strumenti.id');
    }

    public function render(): View
    {
        $perimetro = $this->perimetro();

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : self::SORT_DEFAULT;
        $sortDir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        $query = $this->macchine($perimetro);
        $this->applicaOrdinamento($query, $sortBy, $sortDir);

        // Ri-validato a ogni render e non solo all'update: il valore può
        // arrivare dalla query string senza passare da `updatingPerPage()`.
        $perPage = in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : self::PER_PAGE_DEFAULT;

        $strumenti = $query->paginate($perPage)->onEachSide(1);

        // Gli account **delle sole righe in pagina**, riletti dalla porta: da
        // qui escono i candidati all'impersonazione. Il `cliente_id` della join
        // basterebbe per il nome, non per un `Account` con i suoi membri.
        $idClienti = $strumenti->getCollection()->pluck('cliente_id')->filter()->unique()->values()->all();

        // ⚠️ Su una pagina vuota **non si interroga**: un `whereIn` su un array
        // vuoto è un giro dal database per sapere una cosa che si sa già.
        $clienti = $idClienti === []
            ? new Collection
            : ParcoClienti::clienti($perimetro)->whereIn('accounts.id', $idClienti)->get();

        $preferiti = $this->preferiti();

        // I clienti nel perimetro **veri**, cioè quelli che la tabella qui sotto
        // può davvero mostrare.
        //
        // ⚠️ Non `count($perimetro->accountIds)`: quella è la lista grezza dei
        // preferiti. Un preferito verso EasyLab, o verso un cliente cestinato
        // dopo la segnatura, conterebbe uno e non porterebbe nessuna riga — e il
        // numero a schermo non corrisponderebbe a niente. `Preferiti::clienti()`
        // ha già intersecato, quindi il conteggio è la sua lunghezza e costa
        // zero query in più.
        $clientiScelti = $preferiti->count();

        return view('livewire.piattaforma.parco-globale', [
            'strumenti' => $strumenti,
            'righe' => RigheParcoStrumenti::perPagina($strumenti->getCollection(), $perimetro),
            'candidatiPerAccount' => $this->candidatiDellaPagina($clienti),
            'preferiti' => $preferiti,
            'piani' => Piani::codici(),
            'perimetro' => $perimetro,
            'titolo' => $this->titolo($perimetro, $clientiScelti),
            'messaggioVuoto' => $this->messaggioElencoVuoto($perimetro, $clientiScelti),
            // ⚠️ **Non** `sortBy`/`sortDir`: Livewire passa alla vista le
            // property pubbliche del componente *e* i dati di `render()`, e in
            // caso di omonimia vince la property — cioè il valore GREZZO
            // arrivato dal browser. La freccia dell'intestazione avrebbe
            // indicato una colonna che la query non ha usato. Nomi diversi, e
            // il conflitto non è più esprimibile.
            'ordinaPer' => $sortBy,
            'ordinaDir' => $sortDir,
            // Il DETTAGLIO della colonna scadenza degrada senza
            // `garanzie.ricambio.view` (🔗 ADR-020); il pallino, che è
            // l'aggregato, non cambia per nessuno.
            'vedeGaranzieRicambio' => Gate::allows('view', Garanzia::class),
        ]);
    }
}
