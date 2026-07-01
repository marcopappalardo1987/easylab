<?php

namespace App\Livewire\Anagrafica;

use App\Enums\TipoSpostamento;
use App\Enums\TipoUnitaOrganizzativa;
use App\Livewire\Concerns\ManagesStrumentoForm;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Anagrafica gerarchica con navigazione drill-down (S2 punto 4/5).
 * Si entra in un nodo alla volta: breadcrumb in alto, card dei sotto-nodi e
 * lista strumenti del nodo corrente. CRUD nodi + create strumento in modale.
 * Isolamento e sotto-albero garantiti dai global scope (findOrFail fuori
 * scope → 404).
 */
#[Layout('components.layouts.app')]
class Albero extends Component
{
    use ManagesStrumentoForm;

    /** Nodo in cui siamo "dentro" (null = livello radice: elenco Enti/root). */
    public ?int $currentId = null;

    // Modale form nodo
    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $parentId = null;

    public string $tipo = '';

    public string $nome = '';

    public ?string $note = null;

    // Conferma eliminazione nodo
    public ?int $deletingId = null;

    // Modale create strumento
    public bool $showStrumentoForm = false;

    // Provenienza esterna opzionale (ente off-platform) alla creazione.
    public ?string $provenienza = null;

    public ?string $notice = null;

    public function mount(): void
    {
        // Se l'utente ha un'unica radice visibile (es. Admin con un Ente,
        // Responsabile con un reparto), entra subito senza far cliccare.
        $roots = $this->visibleRoots();
        if ($roots->count() === 1) {
            $this->currentId = $roots->first()->id;
        }
    }

    protected function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'tipo' => ['required', Rule::in([
                TipoUnitaOrganizzativa::Dipartimento->value,
                TipoUnitaOrganizzativa::Sottolaboratorio->value,
            ])],
        ];
    }

    /** Nodi visibili (già scopati a tenant + eventuale sotto-albero). */
    protected function visibleNodes(): Collection
    {
        return UnitaOrganizzativa::orderBy('nome')->get();
    }

    /** Radici di visualizzazione: nodi senza padre visibile. */
    protected function visibleRoots(): Collection
    {
        $nodes = $this->visibleNodes();
        $ids = $nodes->pluck('id')->all();

        return $nodes->filter(
            fn (UnitaOrganizzativa $n) => $n->parent_id === null || ! in_array($n->parent_id, $ids, true)
        )->values();
    }

    // --- Navigazione ---

    public function open(int $id): void
    {
        $this->currentId = UnitaOrganizzativa::findOrFail($id)->id;
    }

    public function goTo(?int $id): void
    {
        $this->currentId = $id !== null ? UnitaOrganizzativa::findOrFail($id)->id : null;
    }

    // --- CRUD nodi ---

    public function addChild(int $parentId): void
    {
        $this->authorize('unita_organizzativa.create');
        $parent = UnitaOrganizzativa::findOrFail($parentId);

        $this->resetForm();
        $this->parentId = $parent->id;
        $this->tipo = $parent->tipo === TipoUnitaOrganizzativa::Ente
            ? TipoUnitaOrganizzativa::Dipartimento->value
            : TipoUnitaOrganizzativa::Sottolaboratorio->value;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('unita_organizzativa.update');
        $node = UnitaOrganizzativa::findOrFail($id);

        $this->resetForm();
        $this->editingId = $node->id;
        $this->parentId = $node->parent_id;
        $this->tipo = $node->tipo->value;
        $this->nome = $node->nome;
        $this->note = $node->note;
        $this->showForm = true;
    }

    public function save(): void
    {
        if ($this->editingId !== null) {
            // In modifica si cambiano solo nome/note (il tipo non si tocca:
            // validarlo escluderebbe l'Ente).
            $this->authorize('unita_organizzativa.update');

            $validated = $this->validate([
                'nome' => ['required', 'string', 'max:255'],
                'note' => ['nullable', 'string', 'max:1000'],
            ]);

            $node = UnitaOrganizzativa::findOrFail($this->editingId);
            $node->update(['nome' => $validated['nome'], 'note' => $validated['note']]);
        } else {
            $this->authorize('unita_organizzativa.create');

            $validated = $this->validate();

            $parent = UnitaOrganizzativa::findOrFail($this->parentId);
            UnitaOrganizzativa::create([
                'tenant_id' => $parent->tenant_id,
                'parent_id' => $parent->id,
                'tipo' => $validated['tipo'],
                'nome' => $validated['nome'],
                'note' => $validated['note'],
            ]);
        }

        $this->closeForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('unita_organizzativa.delete');
        $this->deletingId = UnitaOrganizzativa::findOrFail($id)->id;
    }

    public function delete(): void
    {
        $this->authorize('unita_organizzativa.delete');
        $node = UnitaOrganizzativa::findOrFail($this->deletingId);

        if ($node->tipo === TipoUnitaOrganizzativa::Ente) {
            $this->notice = 'L\'Ente non può essere eliminato.';
            $this->deletingId = null;

            return;
        }

        if ($node->children()->exists()) {
            $this->notice = 'Elimina prima le unità interne.';
            $this->deletingId = null;

            return;
        }

        if (Strumento::where('unita_organizzativa_id', $node->id)->exists()) {
            $this->notice = 'Sposta o elimina prima gli strumenti.';
            $this->deletingId = null;

            return;
        }

        $parentId = $node->parent_id;
        $node->delete();

        // Se stavamo guardando il nodo eliminato, risali al padre.
        if ($this->currentId === $node->id) {
            $this->currentId = $parentId;
        }
        $this->deletingId = null;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'parentId', 'tipo', 'nome', 'note']);
        $this->resetValidation();
    }

    // --- Strumenti del nodo corrente ---

    public function addStrumento(): void
    {
        $this->authorize('strumenti.create');

        $node = UnitaOrganizzativa::findOrFail($this->currentId);
        if ($node->tipo === TipoUnitaOrganizzativa::Ente) {
            $this->notice = 'Aggiungi gli strumenti a un dipartimento o sotto-laboratorio, non all\'Ente.';

            return;
        }

        $this->provenienza = null;
        $this->resetStrumentoForm();
        $this->showStrumentoForm = true;
    }

    public function saveStrumento(): void
    {
        $this->authorize('strumenti.create');

        $node = UnitaOrganizzativa::findOrFail($this->currentId);
        abort_if($node->tipo === TipoUnitaOrganizzativa::Ente, 422);

        $this->validate([
            ...$this->strumentoFormRules(),
            'provenienza' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($node) {
            $strumento = Strumento::create([
                'tenant_id' => $node->tenant_id,
                'unita_organizzativa_id' => $node->id,
                ...$this->strumentoPayload(),
            ]);

            // Provenienza esterna → registra un movimento di ingresso (ADR-015).
            if (filled($this->provenienza)) {
                SpostamentoStrumento::create([
                    'tenant_id' => $node->tenant_id,
                    'strumento_id' => $strumento->id,
                    'da_esterno' => trim($this->provenienza),
                    'a_nodo_id' => $node->id,
                    'tipo_spostamento' => TipoSpostamento::Ingresso,
                    'data' => now()->toDateString(),
                    'eseguito_da' => auth()->id(),
                ]);
            }
        });

        $this->closeStrumentoForm();
    }

    public function closeStrumentoForm(): void
    {
        $this->provenienza = null;
        $this->resetStrumentoForm();
        $this->showStrumentoForm = false;
    }

    public function render()
    {
        $nodes = $this->visibleNodes();
        $byId = $nodes->keyBy('id');
        $ids = $nodes->pluck('id')->all();
        $childrenByParent = $nodes->groupBy('parent_id');

        $roots = $nodes->filter(
            fn (UnitaOrganizzativa $n) => $n->parent_id === null || ! in_array($n->parent_id, $ids, true)
        )->values();

        $current = $this->currentId ? $byId->get($this->currentId) : null;

        // Breadcrumb: dalla radice visibile fino al nodo corrente.
        $breadcrumb = collect();
        $walk = $current;
        while ($walk !== null) {
            $breadcrumb->prepend($walk);
            $walk = ($walk->parent_id !== null && in_array($walk->parent_id, $ids, true))
                ? $byId->get($walk->parent_id)
                : null;
        }

        $children = $current
            ? $childrenByParent->get($current->id, collect())->values()
            : $roots;

        $strumenti = ($current && $current->tipo !== TipoUnitaOrganizzativa::Ente)
            ? Strumento::where('unita_organizzativa_id', $current->id)->orderBy('nome')->get()
            : collect();

        // Conteggi figli/strumenti per le card.
        $childCounts = $childrenByParent->map->count();
        $strumentiCounts = $children->isEmpty()
            ? collect()
            : Strumento::whereIn('unita_organizzativa_id', $children->pluck('id'))
                ->selectRaw('unita_organizzativa_id, count(*) as c')
                ->groupBy('unita_organizzativa_id')
                ->pluck('c', 'unita_organizzativa_id');

        return view('livewire.anagrafica.albero', [
            'current' => $current,
            'breadcrumb' => $breadcrumb,
            'children' => $children,
            'strumenti' => $strumenti,
            'childCounts' => $childCounts,
            'strumentiCounts' => $strumentiCounts,
        ]);
    }
}
