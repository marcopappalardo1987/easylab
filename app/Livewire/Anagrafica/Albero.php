<?php

namespace App\Livewire\Anagrafica;

use App\Enums\TipoUnitaOrganizzativa;
use App\Livewire\Concerns\ManagesStrumentoForm;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * CRUD anagrafica gerarchica + alberatura navigabile (S2 punto 4).
 * Pagina unica: albero a sinistra, dettaglio a destra; create/edit/delete in
 * modale. Isolamento e sotto-albero sono garantiti dai global scope sul modello
 * (BelongsToTenant + BelongsToOrgNode): findOrFail su id fuori scope → 404.
 */
#[Layout('components.layouts.app')]
class Albero extends Component
{
    use ManagesStrumentoForm;

    public ?int $selectedId = null;

    // Stato modale create strumento (sul nodo selezionato)
    public bool $showStrumentoForm = false;

    // Stato modale form
    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $parentId = null;

    public string $tipo = '';

    public string $nome = '';

    public ?string $note = null;

    // Stato conferma eliminazione
    public ?int $deletingId = null;

    public ?string $notice = null;

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
            // In modifica si cambiano solo nome/note: il tipo non si tocca
            // (validarlo escluderebbe l'Ente, tipo non ammesso in creazione).
            $this->authorize('unita_organizzativa.update');

            $validated = $this->validate([
                'nome' => ['required', 'string', 'max:255'],
                'note' => ['nullable', 'string', 'max:1000'],
            ]);

            $node = UnitaOrganizzativa::findOrFail($this->editingId);
            $node->update(['nome' => $validated['nome'], 'note' => $validated['note']]);
        } else {
            $this->authorize('unita_organizzativa.create');

            $validated = $this->validate(); // rules(): include il tipo

            $parent = UnitaOrganizzativa::findOrFail($this->parentId);
            UnitaOrganizzativa::create([
                'tenant_id' => $parent->tenant_id, // forzato dal trait; esplicito per contesto console
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
            $this->notice = 'Il nodo Ente non può essere eliminato.';
            $this->deletingId = null;

            return;
        }

        if ($node->children()->exists()) {
            $this->notice = 'Elimina prima i nodi figli.';
            $this->deletingId = null;

            return;
        }

        $node->delete();
        if ($this->selectedId === $node->id) {
            $this->selectedId = null;
        }
        $this->deletingId = null;
    }

    public function select(int $id): void
    {
        $this->selectedId = UnitaOrganizzativa::findOrFail($id)->id;
    }

    public function addStrumento(): void
    {
        $this->authorize('strumenti.create');

        $node = UnitaOrganizzativa::findOrFail($this->selectedId);
        if ($node->tipo === TipoUnitaOrganizzativa::Ente) {
            $this->notice = 'Aggiungi gli strumenti a un dipartimento o sotto-laboratorio, non all\'Ente.';

            return;
        }

        $this->resetStrumentoForm();
        $this->showStrumentoForm = true;
    }

    public function saveStrumento(): void
    {
        $this->authorize('strumenti.create');

        $node = UnitaOrganizzativa::findOrFail($this->selectedId);
        abort_if($node->tipo === TipoUnitaOrganizzativa::Ente, 422);

        $this->validate($this->strumentoFormRules());

        Strumento::create([
            'tenant_id' => $node->tenant_id,
            'unita_organizzativa_id' => $node->id,
            ...$this->strumentoPayload(),
        ]);

        $this->closeStrumentoForm();
    }

    public function closeStrumentoForm(): void
    {
        $this->resetStrumentoForm();
        $this->showStrumentoForm = false;
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

    public function render()
    {
        $nodes = UnitaOrganizzativa::orderBy('nome')->get();
        $ids = $nodes->pluck('id')->all();
        $childrenByParent = $nodes->groupBy('parent_id');

        // Radici di visualizzazione: nodo ente, o (per il Responsabile) i nodi
        // il cui padre non è nel set accessibile.
        $roots = $nodes->filter(
            fn (UnitaOrganizzativa $n) => $n->parent_id === null || ! in_array($n->parent_id, $ids, true)
        )->values();

        $selectedNode = $this->selectedId ? $nodes->firstWhere('id', $this->selectedId) : null;

        $strumenti = ($selectedNode && $selectedNode->tipo !== TipoUnitaOrganizzativa::Ente)
            ? Strumento::where('unita_organizzativa_id', $selectedNode->id)->orderBy('nome')->get()
            : collect();

        return view('livewire.anagrafica.albero', [
            'roots' => $roots,
            'childrenByParent' => $childrenByParent,
            'selectedNode' => $selectedNode,
            'strumenti' => $strumenti,
        ]);
    }
}
