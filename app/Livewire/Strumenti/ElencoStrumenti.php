<?php

namespace App\Livewire\Strumenti;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Support\Collection;
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

    #[Url]
    public string $sortBy = 'nome';

    #[Url]
    public string $sortDir = 'asc';

    private const SORTABLE = ['nome', 'modello', 'matricola', 'data_installazione'];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingUbicazioneId(): void
    {
        $this->resetPage();
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

    public function render()
    {
        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'nome';
        $sortDir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        $query = Strumento::query()->with('unita');

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

        $strumenti = $query->orderBy($sortBy, $sortDir)->paginate(20);

        $tuttiNodi = UnitaOrganizzativa::orderBy('nome')->get();
        $enti = $tuttiNodi->where('tipo', TipoUnitaOrganizzativa::Ente)->values();

        // Le ubicazioni selezionabili seguono l'Ente scelto (filtro a cascata).
        $nodi = $tuttiNodi
            ->reject(fn (UnitaOrganizzativa $n) => $n->tipo === TipoUnitaOrganizzativa::Ente)
            ->when($this->enteId !== null, fn ($c) => $c->where('tenant_id', $this->enteId))
            ->values();

        return view('livewire.strumenti.elenco-strumenti', [
            'strumenti' => $strumenti,
            'enti' => $enti,
            'nodi' => $nodi,
            'percorsi' => $this->percorsi($tuttiNodi),
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }
}
