<?php

namespace App\Livewire\Piattaforma;

use App\Enums\TipoIntervento;
use App\Livewire\Piattaforma\Concerns\OffreImpersonazione;
use App\Support\Piani;
use App\Support\Piattaforma\ParcoClienti;
use App\Support\Piattaforma\Perimetro;
use App\Support\Piattaforma\ScadenzarioParco;
use App\Support\Semaforo;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Parco clienti — scheda «Scadenzario»: **cosa scade su tutto il parco**, in
 * SOLA LETTURA (🔗 ADR-037).
 *
 * È la vista che risponde alla domanda per cui il committente ha chiesto questa
 * funzione: «mi occupo della strumentazione di molti Enti e voglio vedere la
 * situazione a colpo d'occhio». Le stesse tre partizioni del scadenzario di un
 * Ente solo (`App\Livewire\Interventi\Scadenzario`) e le stesse regole — scaduto
 * da `Intervento::scopeScadute()`, soglia da `Semaforo` — con due colonne in
 * più che sono la ragione per cui la vista è accettabile: **cliente** e **sede**,
 * su ogni riga.
 *
 * 🔴 Tre componenti e non tre schede dentro uno solo: così i tre blocchi si
 * costruiscono su file disgiunti, e nessuno riscrive il lavoro dell'altro. La
 * fila di schede è un partial condiviso (`x-parco.nav`).
 *
 * ## Il confine, che è la ragione per cui la scheda esiste
 *
 * Da qui si **guarda** oltre il proprio Ente, non si scrive: nessuna azione di
 * scrittura, nessuna chiusura di intervento, nessun `skipRender()`. Ogni
 * modifica passa dall'impersonazione, che è per cliente e lascia una riga di
 * audit con dentro chi agiva e per conto di chi (🔗 ADR-018, ADR-027) — il
 * contesto che una scrittura cross-cliente perderebbe proprio dove serve di più.
 * Per questo accanto a ogni riga c'è il tasto «impersona»: è ciò che rende quel
 * confine rapido invece che fastidioso.
 *
 * ⛔ **Si legge da `ParcoClienti`, mai da `VistaPiattaforma`**: i builder della
 * cabina non sono filtrati per cliente (servono a *contare* tutti i clienti, non
 * a *elencare* le righe di quelli scelti), quindi usarli qui mostrerebbe «tutti»
 * mentre il filtro in cima alla pagina dice altro. `ParcoBypassGuardrailTest` lo
 * rende rosso. `VistaPiattaforma::PERMESSO` resta lecito: è una costante, non
 * una query, ed è ciò che la rotta dichiara.
 *
 * ## Il perimetro arriva dal browser
 *
 * `modo`, `clientiScelti` e `piano` sono property pubbliche, cioè input non
 * fidato a ogni update. Non si validano qui: si passano a
 * `Perimetro::daRichiesta()`, che normalizza e in caso di dubbio torna
 * `nessuno()` — mai «tutti» — e da lì a `ParcoClienti`, che **interseca** gli id
 * con l'insieme legittimo. Le due metà stanno in due posti apposta (forma e
 * appartenenza), e nessuna delle due vive in questo file.
 *
 * ## Costo
 *
 * Dieci statement **costanti** rispetto al numero di righe: 1 count del
 * paginatore + 1 pagina + 1 macchine + 2 titolari (sedi e clienti) + 2 per i
 * candidati all'impersonazione (pivot e ruoli) + 3 contatori. Si parte da
 * `Intervento` e non da `Strumento`, quindi nessuna sottoquery correlata per
 * riga e nessun ordinamento su colonna derivata.
 *
 * L'undicesimo — la tendina dei clienti selezionabili — si esegue **solo nel
 * modo in cui la tendina è in pagina**: negli altri due era una lettura
 * dell'intera tabella account scartata a ogni render.
 *
 * ⚠️ **Macchina, cliente e sede NON si prendono dalle relazioni.**
 * `$intervento->strumento` e la risalita al nodo passano da modelli scopati sul
 * tenant di chi guarda: cross-cliente tornerebbero `null`, cioè una tabella di
 * trattini — plausibile e muta. Si rileggono dalla porta, che gli scope li
 * toglie per nome. La ragione per esteso è nel docblock di
 * `ScadenzarioParco::titolari()`.
 */
#[Layout('components.layouts.app')]
#[Title('Scadenzario del parco — Easy Lab')]
class ParcoScadenzario extends Component
{
    use OffreImpersonazione, WithPagination;

    public const PERMESSO = VistaPiattaforma::PERMESSO;

    /** Su quali clienti si sta guardando: uno dei tre modi di `Perimetro`. */
    #[Url]
    public string $modo = Perimetro::TUTTI;

    /**
     * Gli account scelti a mano, quando `modo` è `scelti`.
     *
     * ⛔ Il nome non è `clienti`: Livewire condivide con la vista le property
     * pubbliche **dopo** i dati passati a `view()`, quindi una chiave omonima
     * verrebbe sovrascritta dal valore grezzo della query string. È la stessa
     * trappola per cui la direzione dell'ordinamento si chiama `direzione` in
     * vista e `sortDir` qui.
     *
     * @var array<int|string>
     */
    #[Url]
    public array $clientiScelti = [];

    /** Il piano commerciale, quando `modo` è `piano`. */
    #[Url]
    public ?string $piano = null;

    #[Url]
    public string $search = '';

    /** Partizione selezionata: valori ammessi in `ScadenzarioParco::STATI`. */
    #[Url]
    public ?string $stato = null;

    /** Filtro per tipo di attività (ADR-021), validato con `tryFrom()`. */
    #[Url]
    public ?string $tipo = null;

    #[Url]
    public string $sortDir = 'asc';

    /**
     * Righe per pagina. Arriva dalla query string, quindi si rivalida sempre
     * contro `PER_PAGE`: un `?perPage=999999` chiederebbe al DB l'intero
     * scadenzario di tutti i clienti della piattaforma.
     */
    #[Url]
    public int $perPage = self::PER_PAGE_DEFAULT;

    private const PER_PAGE = [20, 50, 100];

    private const PER_PAGE_DEFAULT = 20;

    public function updatingModo(): void
    {
        $this->resetPage();
    }

    public function updatingClientiScelti(): void
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

    public function updatingTipo(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * Chiude ogni modale della pagina.
     *
     * `OffreImpersonazione::apriScelta()` la chiama per tenere l'invariante
     * «una modale alla volta»; qui la modale è una sola, e il metodo esiste
     * perché il concern è condiviso con la cabina — che di modali ne ha cinque.
     * Il componente è il solo posto che sa quali modali ha.
     */
    public function chiudiOgniModale(): void
    {
        $this->sceltaImpersonazione = null;
    }

    /**
     * Le tre tile in cima indirizzano l'elenco, come nella dashboard.
     *
     * `null` («Tutti gli aperti») è un valore legittimo; qualunque altra cosa
     * ricade su `null` invece di produrre un elenco che non corrisponde a
     * nessuna etichetta.
     */
    public function filtra(?string $stato): void
    {
        $this->stato = in_array($stato, ScadenzarioParco::STATI, true) ? $stato : null;
        $this->resetPage();
    }

    /**
     * Inverte la direzione dell'ordinamento.
     *
     * ⛔ Si inverte a partire dalla direzione **effettivamente applicata**, mai
     * dal valore grezzo: `sortDir` arriva dalla query string e può valere
     * qualunque cosa. Con due copie della whitelist un `?sortDir=inventato`
     * renderebbe 'asc' e al primo clic riscriverebbe 'asc' — l'elenco non si
     * muove e la freccia non cambia. Stessa ragione di `Scadenzario` e di
     * `RegistroAudit::inverti()`.
     */
    public function invertiOrdine(): void
    {
        $this->sortDir = $this->direzione() === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
    }

    /**
     * Almeno un filtro è attivo, cioè l'elenco è un SOTTOINSIEME degli aperti
     * del perimetro.
     *
     * 🔴 Esiste come metodo perché **la vista non deve riscrivere questa
     * lista**: quando la condizione viveva in Blade nominava due filtri su
     * cinque e mandava a togliere filtri che il componente aveva già scartato.
     * Ogni ramo usa **lo stesso predicato con cui il filtro è applicato**: un
     * `?tipo=inventato` non è un filtro applicato, quindi non è un filtro da
     * annunciare.
     *
     * ⚠️ Il **perimetro** non è qui dentro: non è un filtro dell'elenco, è il
     * suo confine, e ha un messaggio suo quando è vuoto.
     */
    public function haFiltriAttivi(): bool
    {
        return filled($this->search)
            || $this->tipoValido() !== null
            || in_array($this->stato, ScadenzarioParco::STATI, true);
    }

    /**
     * Opzioni del select delle righe per pagina.
     *
     * @return list<int>
     */
    public function opzioniPerPage(): array
    {
        return self::PER_PAGE;
    }

    /**
     * Il perimetro come arriva dal browser — normalizzato **fuori di qui**.
     *
     * Un `modo` sconosciuto, un piano fuori catalogo o un id malformato cadono
     * su `nessuno()`, mai su «tutti»: fra i due errori possibili, mostrare più
     * di quanto è stato chiesto è quello che non si vede.
     */
    private function perimetro(): Perimetro
    {
        return Perimetro::daRichiesta($this->modo, $this->clientiScelti, $this->piano);
    }

    /**
     * La direzione **effettivamente applicata**, e l'unico posto in cui la
     * whitelist di `sortDir` è scritta.
     *
     * `mb_strtolower()` perché un `?sortDir=DESC` incollato a mano è la stessa
     * direzione, non un valore da scartare.
     */
    private function direzione(): string
    {
        return mb_strtolower(trim($this->sortDir)) === 'desc' ? 'desc' : 'asc';
    }

    /** Il tipo selezionato se è un valore dell'enum, altrimenti nulla (ADR-021). */
    private function tipoValido(): ?TipoIntervento
    {
        return $this->tipo === null ? null : TipoIntervento::tryFrom($this->tipo);
    }

    public function render(): View
    {
        // Rivalidati a ogni render e non solo negli hook: i valori arrivano
        // dalla query string senza passare da `updating*`.
        $perimetro = $this->perimetro();
        $direzione = $this->direzione();
        $perPage = in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : self::PER_PAGE_DEFAULT;
        $stato = in_array($this->stato, ScadenzarioParco::STATI, true) ? $this->stato : null;

        $base = fn () => ScadenzarioParco::base($perimetro, $this->search, $this->tipoValido());

        $pagina = fn () => ScadenzarioParco::ordinata(
            ScadenzarioParco::partiziona($base(), $stato),
            $direzione
        )->paginate($perPage)->onEachSide(1);

        $interventi = $pagina();

        // ⚠️ `page` sta nella query string e nessuno lo riconduce da sé: una
        // pagina oltre l'ultima esistente torna ZERO righe mentre le tile
        // restano corrette, e la tabella direbbe «Nessun intervento aperto»
        // davanti a un elenco che ne ha cinque una pagina più indietro. Si
        // rimbalza sull'ULTIMA pagina esistente, che è quella più vicina a dove
        // si stava guardando, e solo con righe da mostrare.
        if ($interventi->total() > 0 && $interventi->currentPage() > $interventi->lastPage()) {
            $this->setPage($interventi->lastPage());
            $interventi = $pagina();
        }

        $righe = $interventi->getCollection();

        [
            'titolari' => $titolari,
            'clienti' => $clienti,
        ] = ScadenzarioParco::titolari($perimetro, $righe->pluck('tenant_id')->filter()->unique()->values()->all());

        // I contatori portano ricerca e tipo ma **non** la partizione
        // selezionata: applicandola anche a loro, due tile su tre direbbero zero
        // appena se ne clicca una, e non si potrebbe più passare dall'una
        // all'altra leggendo i numeri. Passano dalla stessa `base()` delle
        // righe, quindi non possono divergere da ciò che l'elenco mostra —
        // compreso il perimetro, che è la superficie che si dimentica: un
        // conteggio fuori dal filtro direbbe il numero di clienti che non sono
        // in pagina, senza mostrarne una riga.
        $contatori = [
            'scaduti' => ScadenzarioParco::partiziona($base(), 'scaduti')->count(),
            'in_scadenza' => ScadenzarioParco::partiziona($base(), 'in_scadenza')->count(),
            'oltre' => ScadenzarioParco::partiziona($base(), 'oltre')->count(),
        ];

        return view('livewire.piattaforma.parco-scadenzario', [
            'interventi' => $interventi,
            'titolari' => $titolari,
            'macchine' => ScadenzarioParco::macchine(
                $perimetro,
                $righe->pluck('strumento_id')->filter()->unique()->values()->all()
            ),
            // I candidati all'impersonazione degli account in pagina: due query
            // fisse (pivot e ruoli) invece di due per riga, e nessuna affatto
            // per chi non potrà mai impersonare.
            'candidati' => $this->candidatiDellaPagina($clienti),
            'contatori' => $contatori,
            // ⛔ Il nome è `direzione` e non `sortDir`: quest'ultima è la
            // property pubblica, cioè il valore grezzo, e Livewire la condivide
            // con la vista DOPO i dati di `view()`. La freccia deve indicare
            // l'ordine applicato — indicare il contrario è una bugia piccola, e
            // per questo credibile.
            'direzione' => $direzione,
            'statoAttivo' => $stato,
            // La soglia si mostra e non si riscrive: viene da `Semaforo`, cioè
            // dallo stesso posto da cui la legge la query.
            'soglia' => Semaforo::giorniImminente(),
            'tipi' => TipoIntervento::cases(),
            // La tendina del filtro: l'insieme **legittimo**, cioè lo stesso
            // contro cui `ParcoClienti::clienti()` interseca gli id scelti.
            // Ordinata con il tie-break sull'id come ogni altro elenco.
            //
            // ⚠️ Si legge SOLO nel modo in cui la tendina è in pagina, e la
            // condizione è la stessa che la vista usa per disegnarla (`$modo`,
            // la property pubblica): negli altri due modi — «tutti» è il
            // default, quindi il caso normale — era una lettura dell'intera
            // tabella account buttata via, ripetuta a ogni render, cioè a ogni
            // pausa di digitazione nella ricerca.
            'selezionabili' => $this->modo === Perimetro::SCELTI
                ? ParcoClienti::selezionabili()
                    ->orderBy('accounts.ragione_sociale')
                    ->orderBy('accounts.id')
                    ->get(['accounts.id', 'accounts.ragione_sociale'])
                : collect(),
            'piani' => Piani::codici(),
            // ⛔ «Nessun cliente selezionato» e «questi clienti non hanno
            // scadenze» sono due fatti diversi, e una tabella vuota li
            // confonderebbe — che è la stessa distinzione fra un 403 e un
            // builder vuoto che la porta fa sul permesso.
            'perimetroVuoto' => $perimetro->eNessuno(),
        ]);
    }
}
