<?php

namespace App\Livewire\Strumenti;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
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

        if ($this->ubicazioneId !== null) {
            $query->whereIn('unita_organizzativa_id', $this->sottoAlbero($this->ubicazioneId));
        }

        $strumenti = $query->orderBy($sortBy, $sortDir)->paginate(20);

        $nodi = UnitaOrganizzativa::where('tipo', '!=', TipoUnitaOrganizzativa::Ente->value)
            ->orderBy('nome')
            ->get();

        return view('livewire.strumenti.elenco-strumenti', [
            'strumenti' => $strumenti,
            'nodi' => $nodi,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }
}
