<?php

namespace App\Livewire\Strumenti;

use App\Enums\TipoSpostamento;
use App\Enums\TipoUnitaOrganizzativa;
use App\Livewire\Concerns\ManagesStrumentoForm;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Support\Facades\DB;
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

    // Modale "Sposta" (movimento interno nodo→nodo)
    public bool $showMoveForm = false;

    public ?int $destinazioneId = null;

    public ?string $dataSpostamento = null;

    public ?string $notaSpostamento = null;

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

    // --- Spostamento interno (nodo → nodo) ---

    public function openMove(): void
    {
        $this->authorize('strumenti.move');
        $this->reset(['destinazioneId', 'notaSpostamento']);
        $this->dataSpostamento = now()->toDateString();
        $this->showMoveForm = true;
    }

    public function move(): void
    {
        $this->authorize('strumenti.move');

        $this->validate([
            'destinazioneId' => ['required', 'integer', 'different:strumento.unita_organizzativa_id'],
            'dataSpostamento' => ['required', 'date'],
            'notaSpostamento' => ['nullable', 'string', 'max:1000'],
        ]);

        // In scope (tenant + eventuale sotto-albero); fuori scope → 404.
        $destinazione = UnitaOrganizzativa::findOrFail($this->destinazioneId);
        if ($destinazione->tipo === TipoUnitaOrganizzativa::Ente) {
            $this->addError('destinazioneId', 'Non puoi spostare uno strumento su un Ente.');

            return;
        }

        DB::transaction(function () use ($destinazione) {
            SpostamentoStrumento::create([
                'tenant_id' => $this->strumento->tenant_id,
                'strumento_id' => $this->strumento->id,
                'da_nodo_id' => $this->strumento->unita_organizzativa_id,
                'a_nodo_id' => $destinazione->id,
                'tipo_spostamento' => TipoSpostamento::Interno,
                'data' => $this->dataSpostamento,
                'eseguito_da' => auth()->id(),
                'nota' => $this->notaSpostamento,
            ]);

            $this->strumento->update(['unita_organizzativa_id' => $destinazione->id]);
        });

        $this->closeMove();
    }

    public function closeMove(): void
    {
        $this->reset(['destinazioneId', 'dataSpostamento', 'notaSpostamento']);
        $this->showMoveForm = false;
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

        // Destinazioni possibili: nodi non-Ente del proprio Ente (già scopati),
        // escluso il nodo attuale.
        $nodiDestinazione = UnitaOrganizzativa::where('tipo', '!=', TipoUnitaOrganizzativa::Ente->value)
            ->where('id', '!=', $this->strumento->unita_organizzativa_id)
            ->orderBy('nome')
            ->get();

        return view('livewire.strumenti.scheda-strumento', [
            'percorso' => $percorso->implode(' › '),
            'spostamenti' => $this->strumento->spostamenti()->with(['daNodo', 'aNodo', 'eseguitoBy'])->get(),
            'nodiDestinazione' => $nodiDestinazione,
        ]);
    }
}
