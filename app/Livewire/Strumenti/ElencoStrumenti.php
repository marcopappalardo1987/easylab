<?php

namespace App\Livewire\Strumenti;

use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Semaforo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tabella globale filtrabile degli strumenti dell'Ente (S2 punto 7).
 * Ricerca testo + filtro per ubicazione (nodo + discendenti) + ordinamento +
 * paginazione. Tenant-scoped e sotto-albero via i global scope del modello.
 */
#[Layout('components.layouts.app')]
class ElencoStrumenti extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /**
     * Filtro Ente. Oggi ogni utente ne vede uno solo (ADR-018) → il select è
     * nascosto; diventerà operativo quando un utente potrà gestire più Enti
     * (Rivenditori, V1.1).
     */
    #[Url]
    public ?int $enteId = null;

    #[Url]
    public ?int $ubicazioneId = null;

    /**
     * Filtro semaforo (ADR-005): 'verde' | 'arancione'. Lo stato è derivato,
     * non una colonna: il filtro si applica in SQL con una subquery sugli
     * interventi (vedi render), altrimenti filtrare dopo la paginazione darebbe
     * pagine incomplete. Include 'rosso', che esiste solo come forzatura
     * manuale (punto 5).
     */
    #[Url]
    public ?string $stato = null;

    /**
     * Filtro obsolescenza (ADR-014): mostra solo gli strumenti oltre la soglia
     * di età del proprio Ente.
     */
    #[Url]
    public bool $soloObsoleti = false;

    #[Url]
    public string $sortBy = 'nome';

    #[Url]
    public string $sortDir = 'asc';

    /**
     * Righe per pagina. Arriva dalla query string, quindi è input dell'utente:
     * va sempre validato contro PER_PAGE (un `?perPage=999999` chiederebbe al
     * DB l'intero elenco con le sue sottoquery per riga).
     */
    #[Url]
    public int $perPage = self::PER_PAGE_DEFAULT;

    /** Scelte ammesse: il massimo è 100 righe per pagina. */
    private const PER_PAGE = [20, 50, 100];

    private const PER_PAGE_DEFAULT = 20;

    /**
     * Data "più avanti di qualunque scadenza reale", usata al posto del NULL
     * nell'ordinamento per prossima scadenza (vedi applicaOrdinamento). Un
     * intervento datato davvero al 31/12/9999 finirebbe in fondo insieme a
     * quelli senza scadenza: è un'ipotesi dichiarata, non una dimenticanza.
     */
    private const SENTINELLA_SCADENZA = '9999-12-31';

    /**
     * Colonne ordinabili. Le prime quattro sono colonne di `strumenti`; le
     * ultime tre sono DERIVATE e vengono ordinate con sottoquery correlate
     * (vedi applicaOrdinamento) — mai in PHP, che ordinerebbe solo la pagina
     * corrente dando un ordine sbagliato fra una pagina e l'altra.
     */
    private const SORTABLE = [
        'nome', 'modello', 'matricola', 'data_installazione',
        'ubicazione', 'stato', 'prossima_scadenza',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingUbicazioneId(): void
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

    /** Cambiando la dimensione di pagina, la pagina corrente non ha più senso. */
    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * Almeno un filtro è attivo, cioè l'elenco che si sta guardando è un
     * SOTTOINSIEME e non il parco.
     *
     * 🔴 **Esiste come metodo perché la vista non deve riscrivere questa lista.**
     * La condizione del messaggio di vuoto viveva in Blade e nominava due filtri
     * su cinque: era la terza forma della stessa regola, e infatti divergeva —
     * `?ubicazioneId=0` mostrava «Nessuno strumento.» su un elenco filtrato (lo
     * zero è falsy), `?enteId=` non era nominato affatto, e `?stato=giallo`
     * mostrava «Nessun risultato per i filtri applicati» su un elenco **non**
     * filtrato, mandando a togliere un filtro che il componente aveva già
     * scartato. Una lista scritta a mano diverge di nuovo alla prossima
     * `#[Url]`.
     *
     * ⚠️ Ogni ramo usa **lo stesso predicato con cui il filtro viene applicato**
     * in `render()`: `!== null` dove là c'è `!== null`, la whitelist dove là
     * c'è la whitelist. È ciò che rende «filtro applicato» e «filtro annunciato»
     * la stessa cosa invece che due cose che si somigliano.
     */
    public function haFiltriAttivi(): bool
    {
        return filled($this->search)
            || $this->enteId !== null
            || $this->ubicazioneId !== null
            || $this->soloObsoleti
            || in_array($this->stato, array_map(fn (StatoSemaforo $c) => $c->value, StatoSemaforo::cases()), true);
    }

    /** Opzioni del select, esposte alla view. @return list<int> */
    public function opzioniPerPage(): array
    {
        return self::PER_PAGE;
    }

    /** Cambiando Ente cambia l'elenco delle ubicazioni → il filtro va azzerato. */
    public function updatingEnteId(): void
    {
        $this->ubicazioneId = null;
        $this->resetPage();
    }

    public function sort(string $col): void
    {
        if (! in_array($col, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $col;
            $this->sortDir = 'asc';
        }

        // Cambiare ordinamento rimescola tutte le righe: restare sulla pagina
        // corrente farebbe atterrare a metà elenco. Come per i filtri, si torna
        // alla prima pagina.
        $this->resetPage();
    }

    /**
     * Nodo selezionato + tutti i discendenti (nell'Ente scopato).
     *
     * @return list<int>
     */
    protected function sottoAlbero(int $rootId): array
    {
        $childrenByParent = [];
        UnitaOrganizzativa::get(['id', 'parent_id'])->each(function ($n) use (&$childrenByParent) {
            $childrenByParent[$n->parent_id][] = (int) $n->id;
        });

        $ids = [];
        $queue = [$rootId];
        while ($queue !== []) {
            $id = (int) array_shift($queue);
            if (isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            foreach ($childrenByParent[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Percorso gerarchico completo di ogni nodo, Ente incluso:
     * id → ['Ente', 'Dipartimento', 'Sotto-laboratorio']. L'Ente serve quando un
     * utente potrà vedere più Enti (Rivenditori, V1.1). Costruito in una sola
     * passata per evitare N+1 sulle righe della tabella.
     *
     * @param  Collection<int, UnitaOrganizzativa>  $nodi
     * @return array<int, list<string>>
     */
    protected function percorsi($nodi): array
    {
        $byId = $nodi->keyBy('id');
        $percorsi = [];

        foreach ($nodi as $nodo) {
            if ($nodo->tipo === TipoUnitaOrganizzativa::Ente) {
                continue; // gli strumenti non stanno mai sull'Ente
            }

            $catena = [];
            $corrente = $nodo;
            // Risale fino alla radice visibile (per il Responsabile: il suo reparto).
            while ($corrente !== null) {
                array_unshift($catena, $corrente->nome);
                $corrente = $corrente->parent_id !== null ? $byId->get($corrente->parent_id) : null;
            }

            $percorsi[$nodo->id] = $catena;
        }

        return $percorsi;
    }

    /**
     * Garanzie dei pezzi montati sullo strumento della riga corrente (ADR-020),
     * pronta per essere aggregata: chi chiama aggiunge la sola select.
     *
     * Doppio salto, bypass del privacy scope e correlazione vivono tutti in
     * `Garanzia::scopeDeiPezziMontatiSullaRiga()` — qui non si riscrive nulla
     * di quella regola.
     *
     * @return Builder<Garanzia>
     */
    private function garanzieRicambiDellaRiga(): Builder
    {
        return Garanzia::query()->deiPezziMontatiSullaRiga();
    }

    /**
     * Applica l'ordinamento, incluse le tre colonne derivate.
     *
     * Le derivate diventano sottoquery correlate selezionate come alias, così
     * l'ORDER BY resta lato DB e la paginazione è corretta. Nessun `orderByRaw`
     * con la regola del semaforo riscritta a mano: `stato` usa lo stesso
     * `Intervento::apertiEntroSoglia()` del filtro (unica forma SQL della
     * regola), e `prossima_scadenza` lo stesso criterio "aperti, scadenza
     * minima" del calcolo per-model.
     *
     * 🔴 **Ogni ordinamento finisce con un tie-break sull'id, e non è
     * pignoleria: senza, la paginazione PERDE righe.** A parità di chiave
     * l'ordine fra due pagine è una proprietà del motore — su SQLite la
     * scansione è stabile, su Postgres i pari possono riordinarsi fra la query
     * di pagina 1 e quella di pagina 2, e una riga esce da entrambe. È lo stesso
     * difetto già pagato sul registro di audit, e qui pesa di più: la colonna
     * `stato` ha **tre** valori distinti su tutto il parco, quindi i pari sono
     * quasi tutte le righe.
     *
     * Trovato il 25 Ago 2026 girando la suite su Postgres: 46 righe raccolte su
     * 47, con SQLite verde. Nessun dato di prova l'avrebbe mostrato in locale.
     *
     * @param  Builder<Strumento>  $query
     */
    protected function applicaOrdinamento($query, string $sortBy, string $sortDir): void
    {
        $this->applicaCriterio($query, $sortBy, $sortDir);

        // L'ultimo criterio, sempre e per ogni colonna: l'id è unico, quindi da
        // qui in poi l'ordine è totale e la pagina 2 comincia dove finisce la 1.
        $query->orderBy('strumenti.id');
    }

    /**
     * Il criterio scelto dall'utente, senza il tie-break.
     *
     * @param  Builder<Strumento>  $query
     */
    private function applicaCriterio($query, string $sortBy, string $sortDir): void
    {
        if ($sortBy === 'ubicazione') {
            // Per l'utente "ubicazione" è il nodo in cui sta lo strumento:
            // si ordina per il nome del nodo, non per l'intero percorso
            // (che richiederebbe una CTE ricorsiva) né per la FK.
            $query->orderBy(
                UnitaOrganizzativa::select('nome')->whereColumn('id', 'strumenti.unita_organizzativa_id')->limit(1),
                $sortDir
            );

            return;
        }

        if ($sortBy === 'stato') {
            // Stessa regola del filtro, in forma ordinale: entrambe passano da
            // `Strumento::fontiArancione()`, quindi non possono dire cose
            // diverse sulla stessa riga.
            $query->ordinaPerStato($sortDir);

            return;
        }

        if ($sortBy === 'prossima_scadenza') {
            // Il minimo fra TRE fonti: interventi aperti, garanzia macchina
            // (ADR-004) e garanzie dei pezzi montati (ADR-020). Subquery scalari
            // con MIN aggregato: su zero righe danno NULL, che è esattamente
            // "nessuna scadenza".
            $minIntervento = Intervento::query()->selectRaw('min(data_scadenza)')
                ->whereColumn('strumento_id', 'strumenti.id')
                ->where('stato', StatoIntervento::NonFatto->value);
            $minGaranzia = Garanzia::query()->selectRaw('min(data_scadenza_effettiva)')
                ->whereColumn('strumento_id', 'strumenti.id');
            $minGaranziaRicambio = $this->garanzieRicambiDellaRiga()
                ->selectRaw('min(garanzie.data_scadenza_effettiva)');

            $i = '('.$minIntervento->toSql().')';
            $g = '('.$minGaranzia->toSql().')';
            $r = '('.$minGaranziaRicambio->toSql().')';
            $bi = $minIntervento->getBindings();
            $bg = $minGaranzia->getBindings();
            $br = $minGaranziaRicambio->getBindings();

            // min NULL-safe di tre fonti. `LEAST` non esiste su SQLite e su
            // Postgres tratta i NULL in modo diverso da quanto serve qui,
            // quindi resta un CASE — ma il CASE a due vie che c'era prima usava
            // ogni sottoquery 4 volte, e annidarlo per la terza le avrebbe
            // portate a 16. Con una SENTINELLA al posto del NULL il confronto
            // torna totale e bastano 3+3+2 occorrenze.
            //
            // La sentinella regge su entrambi i driver: su Postgres il
            // letterale è castato a date, su SQLite le date sono stringhe e il
            // confronto lessicografico mette comunque '9999-…' in coda a
            // qualunque 'YYYY-MM-DD 00:00:00'.
            $si = "coalesce({$i}, '".self::SENTINELLA_SCADENZA."')";
            $sg = "coalesce({$g}, '".self::SENTINELLA_SCADENZA."')";
            $sr = "coalesce({$r}, '".self::SENTINELLA_SCADENZA."')";

            // ⚠️ I binding seguono l'ordine TESTUALE dei placeholder:
            //    A, B, A, C, A, B, C, B, C
            $minExpr = "case when {$si} <= {$sg} and {$si} <= {$sr} then {$si}"
                ." when {$sg} <= {$sr} then {$sg}"
                ." else {$sr} end";
            $minBindings = array_merge($bi, $bg, $bi, $br, $bi, $bg, $br, $bg, $br);

            // Gli strumenti senza scadenze ("—") vanno SEMPRE in fondo, in
            // entrambe le direzioni: sono assenza di dato, non un valore. Serve
            // anche a non dipendere dal driver, perché SQLite ordina i NULL per
            // primi e Postgres per ultimi. Si guarda ai valori GREZZI, non alle
            // sentinelle: una sola occorrenza per fonte invece di nove.
            //
            // Nessun alias selezionato: nessuna vista legge "prossima_scadenza",
            // serviva solo all'ORDER BY, e tenerlo raddoppierebbe i binding.
            $query->orderByRaw(
                "case when coalesce({$i}, {$g}, {$r}) is null then 1 else 0 end",
                array_merge($bi, $bg, $br),
            )->orderByRaw("({$minExpr}) {$sortDir}", $minBindings);

            return;
        }

        $query->orderBy($sortBy, $sortDir);
    }

    public function render()
    {
        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'nome';
        $sortDir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        $query = Strumento::query()->select('strumenti.*')->with(['unita', 'forcedBy', 'tenant']);

        if (filled($this->search)) {
            $like = '%'.strtolower(trim($this->search)).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(nome) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(modello, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(matricola, \'\')) LIKE ?', [$like]);
            });
        }

        if ($this->enteId !== null) {
            $query->where('tenant_id', $this->enteId);
        }

        if ($this->ubicazioneId !== null) {
            $query->whereIn('unita_organizzativa_id', $this->sottoAlbero($this->ubicazioneId));
        }

        // Filtro obsolescenza (ADR-014): la forma SQL vive su `Strumento`, dove
        // la soglia è letta PER ENTE — qui c'era una soglia sola per tutte le
        // righe, che per un Tecnico esterno contraddiceva il badge ⏳ della
        // riga accanto.
        if ($this->soloObsoleti) {
            $query->obsoleti();
        }

        // Filtro semaforo (ADR-005): la forma SQL della regola vive in
        // `Strumento::scopeConStato()`, unica nel progetto. Filtrare qui e non
        // dopo la paginazione è l'unico modo di avere pagine e conteggi
        // corretti; il forzato vince, e il verde è il complemento di tutte e
        // tre le fonti dell'arancione.
        if (in_array($this->stato, array_map(fn (StatoSemaforo $c) => $c->value, StatoSemaforo::cases()), true)) {
            $query->conStato(StatoSemaforo::from($this->stato));
        }

        $this->applicaOrdinamento($query, $sortBy, $sortDir);

        // Ri-validato a ogni render, non solo all'update: il valore può arrivare
        // dalla query string senza passare da updatingPerPage().
        $perPage = in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : self::PER_PAGE_DEFAULT;

        // onEachSide(1): con decine di pagine la barra di default ne elencava
        // una dozzina. Così resta «‹ 1 … 4 [5] 6 … 61 ›».
        $strumenti = $query->paginate($perPage)->onEachSide(1);

        // Semaforo (ADR-005) delle sole righe in pagina: UNA query costante,
        // che serve entrambe le colonne derivate. Carica solo gli interventi
        // APERTI (lo storico `fatto` è ciò che cresce senza limite) e cade
        // sull'indice (strumento_id, stato, data_scadenza). Il record minimo
        // porta anche il `tipo`, che serve alla colonna "Prossima scadenza":
        // un aggregato SQL puro non lo darebbe senza un join-back.
        // Partendo da Intervento::query() restano applicati TenantScope,
        // DepartmentThroughStrumentoScope e SoftDeletingScope.
        $prossimi = Intervento::query()
            ->whereIn('strumento_id', $strumenti->getCollection()->modelKeys())
            ->where('stato', StatoIntervento::NonFatto->value)
            ->orderBy('data_scadenza')->orderBy('id')
            ->get(['id', 'strumento_id', 'tipo', 'data_scadenza', 'stato'])
            ->unique('strumento_id') // ordinati asc → il primo per strumento è il minimo
            ->keyBy('strumento_id');

        // Stato EFFETTIVO: il forzato vince (ADR-005). `forced_state` è già sulla
        // riga paginata, quindi costo zero — nessuna query in più.
        // Garanzia più vicina per riga in pagina (ADR-004): seconda query
        // costante, servita dall'indice (strumento_id, data_scadenza_effettiva).
        // Due query separate e non una UNION: ognuna cade sul proprio indice e
        // mantiene i global scope del proprio model (privacy ricambio inclusa).
        $garanzieMin = Garanzia::query()
            ->whereIn('strumento_id', $strumenti->getCollection()->modelKeys())
            ->orderBy('data_scadenza_effettiva')->orderBy('id')
            ->get(['id', 'strumento_id', 'data_scadenza_effettiva'])
            ->unique('strumento_id')
            ->keyBy('strumento_id');

        // Terza query costante (ADR-020): la garanzia più vicina fra i pezzi
        // MONTATI su ogni riga in pagina, letta senza il privacy scope perché
        // il pallino è un aggregato dovuto a tutti. Solo id e data: il nome del
        // pezzo non entra nemmeno nel result set.
        //
        // Alias `montato_su_id` e non `strumento_id`: su una riga
        // `soggetto = ricambio` la colonna `garanzie.strumento_id` è NULL per
        // invariante, e sovrascriverla con l'id della macchina che monta il
        // pezzo metterebbe in circolo model che mentono su sé stessi.
        $garanzieRicambioMin = Garanzia::query()->deiPezziMontati()
            ->whereIn('ricambio_utilizzo.strumento_id', $strumenti->getCollection()->modelKeys())
            ->orderBy('garanzie.data_scadenza_effettiva')->orderBy('garanzie.id')
            ->get([
                'garanzie.id',
                'garanzie.data_scadenza_effettiva',
                'ricambio_utilizzo.strumento_id as montato_su_id',
            ])
            ->unique('montato_su_id')
            ->keyBy('montato_su_id');

        $semafori = $strumenti->getCollection()->mapWithKeys(fn (Strumento $s) => [
            $s->id => $s->forced_state ?? Semaforo::calcola(
                $prossimi->get($s->id)?->data_scadenza,
                $garanzieMin->get($s->id)?->data_scadenza_effettiva,
                $garanzieRicambioMin->get($s->id)?->data_scadenza_effettiva,
            ),
        ]);

        $tuttiNodi = UnitaOrganizzativa::orderBy('nome')->get();
        $enti = $tuttiNodi->where('tipo', TipoUnitaOrganizzativa::Ente)->values();

        // Le ubicazioni selezionabili seguono l'Ente scelto (filtro a cascata).
        $nodi = $tuttiNodi
            ->reject(fn (UnitaOrganizzativa $n) => $n->tipo === TipoUnitaOrganizzativa::Ente)
            ->when($this->enteId !== null, fn ($c) => $c->where('tenant_id', $this->enteId))
            ->values();

        return view('livewire.strumenti.elenco-strumenti', [
            'strumenti' => $strumenti,
            'semafori' => $semafori,
            'prossimi' => $prossimi,
            'garanzieMin' => $garanzieMin,
            'garanzieRicambioMin' => $garanzieRicambioMin,
            // La colonna "Prossima scadenza" è un DETTAGLIO: senza il permesso
            // l'etichetta non nomina la fonte (ADR-020 + wireframe §1). Il
            // pallino, che è l'aggregato, non cambia per nessuno.
            'vedeGaranzieRicambio' => Gate::allows('view', Garanzia::class),
            'enti' => $enti,
            'nodi' => $nodi,
            'percorsi' => $this->percorsi($tuttiNodi),
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }
}
