<?php

namespace App\Livewire\Strumenti;

use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Semaforo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /** Cambiando la dimensione di pagina, la pagina corrente non ha più senso. */
    public function updatingPerPage(): void
    {
        $this->resetPage();
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
     * Applica l'ordinamento, incluse le tre colonne derivate.
     *
     * Le derivate diventano sottoquery correlate selezionate come alias, così
     * l'ORDER BY resta lato DB e la paginazione è corretta. Nessun `orderByRaw`
     * con la regola del semaforo riscritta a mano: `stato` usa lo stesso
     * `Intervento::apertiEntroSoglia()` del filtro (unica forma SQL della
     * regola), e `prossima_scadenza` lo stesso criterio "aperti, scadenza
     * minima" del calcolo per-model.
     *
     * @param  Builder<Strumento>  $query
     */
    protected function applicaOrdinamento($query, string $sortBy, string $sortDir): void
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
            // Ordinale dello stato EFFETTIVO: 0 = verde, 1 = arancione,
            // 2 = rosso (solo forzato). Il forzato vince, quindi il CASE guarda
            // prima `forced_state` e solo se è NULL ricade sugli interventi —
            // stessa regola di Strumento::statoSemaforoEffettivo().
            //
            // L'EXISTS è inlineato dentro il CASE e non estratto come alias:
            // Postgres non ammette alias di select nelle espressioni dell'ORDER
            // BY (SQLite sì, quindi divergerebbe solo in CI).
            $aperti = Intervento::query()->select(DB::raw('1'))
                ->whereColumn('strumento_id', 'strumenti.id')
                ->apertiEntroSoglia();

            $query->orderByRaw(
                'case'
                .' when strumenti.forced_state = ? then 2'
                .' when strumenti.forced_state = ? then 1'
                .' when strumenti.forced_state = ? then 0'
                .' when exists ('.$aperti->toSql().') then 1'
                .' else 0 end '.$sortDir,
                array_merge(
                    [StatoSemaforo::Rosso->value, StatoSemaforo::Arancione->value, StatoSemaforo::Verde->value],
                    $aperti->getBindings(),
                ),
            );

            return;
        }

        if ($sortBy === 'prossima_scadenza') {
            $prossima = Intervento::select('data_scadenza')
                ->whereColumn('strumento_id', 'strumenti.id')
                ->where('stato', StatoIntervento::NonFatto->value)
                ->orderBy('data_scadenza')->orderBy('id')
                ->limit(1);

            // Gli strumenti senza scadenze ("—") vanno SEMPRE in fondo, in
            // entrambe le direzioni: sono assenza di dato, non un valore. Serve
            // anche a non dipendere dal driver, perché SQLite ordina i NULL per
            // primi e Postgres per ultimi.
            $query->addSelect(['prossima_scadenza' => $prossima])
                ->orderByRaw('case when ('.$prossima->toSql().') is null then 1 else 0 end', $prossima->getBindings())
                ->orderBy('prossima_scadenza', $sortDir);

            return;
        }

        $query->orderBy($sortBy, $sortDir);
    }

    public function render()
    {
        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'nome';
        $sortDir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        $query = Strumento::query()->select('strumenti.*')->with(['unita', 'forcedBy']);

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

        // Filtro semaforo: arancione = ha almeno un intervento aperto scaduto o
        // entro la soglia (scopeApertiEntroSoglia, forma SQL della regola di
        // Semaforo::calcola). Verde = il complemento. Filtrare qui e non dopo la
        // paginazione è l'unico modo di avere pagine e conteggi corretti.
        if (in_array($this->stato, array_map(fn (StatoSemaforo $c) => $c->value, StatoSemaforo::cases()), true)) {
            $conScadenzeRilevanti = Intervento::query()->apertiEntroSoglia()->select('strumento_id');

            // "Forzato se c'è, altrimenti calcolato" anche in SQL: senza il
            // ramo su `forced_state` uno strumento forzato comparirebbe sotto
            // lo stato calcolato, contraddicendo il pallino della sua riga.
            match ($this->stato) {
                StatoSemaforo::Rosso->value => $query->where('forced_state', StatoSemaforo::Rosso->value),
                StatoSemaforo::Arancione->value => $query->where(fn ($q) => $q
                    ->where('forced_state', StatoSemaforo::Arancione->value)
                    ->orWhere(fn ($q) => $q->whereNull('forced_state')->whereIn('id', $conScadenzeRilevanti))),
                StatoSemaforo::Verde->value => $query->where(fn ($q) => $q
                    ->where('forced_state', StatoSemaforo::Verde->value)
                    ->orWhere(fn ($q) => $q->whereNull('forced_state')->whereNotIn('id', $conScadenzeRilevanti))),
            };
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
        $semafori = $strumenti->getCollection()->mapWithKeys(fn (Strumento $s) => [
            $s->id => $s->forced_state ?? Semaforo::calcola($prossimi->get($s->id)?->data_scadenza),
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
            'enti' => $enti,
            'nodi' => $nodi,
            'percorsi' => $this->percorsi($tuttiNodi),
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }
}
