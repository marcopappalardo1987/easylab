<?php

namespace App\Livewire\Strumenti;

use App\Livewire\Concerns\ManagesStrumentoForm;
use App\Models\Strumento;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Scheda strumento a tab (S2 punto 5). Tab Anagrafica popolato; Interventi/
 * Ricambi/Documenti/Garanzie sono placeholder (S3/S4). View + edit + delete del
 * singolo strumento; isolamento via route-model binding scopato (404 fuori Ente).
 */
#[Layout('components.layouts.app')]
class SchedaStrumento extends Component
{
    use ManagesStrumentoForm;

    public Strumento $strumento;

    public bool $showForm = false;

    public bool $confirmingDelete = false;

    public function mount(Strumento $strumento): void
    {
        $this->strumento = $strumento;
    }

    public function edit(): void
    {
        $this->authorize('strumenti.update');
        $this->fillStrumentoForm($this->strumento);
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('strumenti.update');
        $this->validate($this->strumentoFormRules());

        $this->strumento->update($this->strumentoPayload());

        $this->showForm = false;
    }

    public function closeForm(): void
    {
        $this->resetStrumentoForm();
        $this->showForm = false;
    }

    public function delete(): void
    {
        $this->authorize('strumenti.delete');
        $this->strumento->delete();

        $this->redirectRoute('anagrafica.index', navigate: true);
    }

    public function render()
    {
        // Ubicazione come catena di nodi (Ente › Dipartimento › Sotto-lab).
        $percorso = collect();
        $node = $this->strumento->unita;
        while ($node !== null) {
            $percorso->prepend($node->nome);
            $node = $node->parent;
        }

        return view('livewire.strumenti.scheda-strumento', [
            'percorso' => $percorso->implode(' › '),
        ]);
    }
}
