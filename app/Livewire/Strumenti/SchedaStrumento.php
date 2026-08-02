<?php

namespace App\Livewire\Strumenti;

use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Enums\TipoIntervento;
use App\Enums\TipoSpostamento;
use App\Enums\TipoUnitaOrganizzativa;
use App\Livewire\Concerns\ManagesStrumentoForm;
use App\Models\Intervento;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Scheda strumento a tab (S2 punto 5). Tab Anagrafica e Interventi (lista +
 * CRUD + spunta "Fatto", S3 punti 2-3) popolati; Ricambi/Documenti/Garanzie
 * sono placeholder (S3/S4). View + edit + delete del singolo strumento;
 * isolamento via route-model binding scopato (404 fuori Ente).
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

    // Modali interventi (S3 punto 3)
    public bool $showInterventoForm = false;

    public ?int $editingInterventoId = null; // null = nuovo

    /** @var array{descrizione:string,tipo:string,data_scadenza:string,tecnico_id:?int,gia_eseguito:bool,data_esecuzione:string} */
    public array $interventoForm = [
        'descrizione' => '',
        'tipo' => '',
        'data_scadenza' => '',
        'tecnico_id' => null,
        'gia_eseguito' => false,
        'data_esecuzione' => '',
    ];

    public bool $showCompletaForm = false;

    public ?int $completingInterventoId = null;

    public string $dataEsecuzione = '';

    public ?int $deletingInterventoId = null; // modale conferma aperta se non null

    // Modale forzatura semaforo (S3 punto 5)
    public bool $showForzaForm = false;

    /** @var array{stato:string,motivo:string} */
    public array $forzaForm = ['stato' => '', 'motivo' => ''];

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

    // --- Interventi (S3 punto 3): CRUD + spunta "Fatto" ---
    //
    // Ogni azione risolve l'intervento con `$this->strumento->interventi()
    // ->findOrFail($id)`: la relazione riapplica TenantScope e
    // DepartmentThroughStrumentoScope e vincola strumento_id, quindi un solo
    // idioma dà 404 per un intervento di un altro tenant, per il Responsabile
    // fuori sotto-albero e per un id di un altro strumento.

    public function openNuovoIntervento(): void
    {
        $this->authorize('interventi.create');
        $this->resetInterventoForm();
        $this->editingInterventoId = null;
        $this->showInterventoForm = true;
    }

    public function openModificaIntervento(int $id): void
    {
        $this->authorize('interventi.update');
        $intervento = $this->strumento->interventi()->findOrFail($id);

        $this->resetInterventoForm();
        $this->interventoForm = [
            'descrizione' => $intervento->descrizione,
            'tipo' => $intervento->tipo->value,
            'data_scadenza' => $intervento->data_scadenza->toDateString(),
            'tecnico_id' => $intervento->tecnico_id,
            'gia_eseguito' => false, // il blocco "già eseguito" esiste solo in create
            'data_esecuzione' => today()->toDateString(),
        ];
        $this->editingInterventoId = $id;
        $this->showInterventoForm = true;
    }

    public function saveIntervento(): void
    {
        $this->authorize($this->editingInterventoId === null ? 'interventi.create' : 'interventi.update');
        $this->validate($this->interventoFormRules());

        $payload = [
            'descrizione' => $this->interventoForm['descrizione'],
            'tipo' => $this->interventoForm['tipo'],
            'data_scadenza' => $this->interventoForm['data_scadenza'],
        ];

        // tecnico_id: mai fidarsi del payload Livewire. Senza `interventi.assign`
        // la chiave non entra nel payload (create → null, update → invariato);
        // con il permesso, l'id deve appartenere alla stessa whitelist del select.
        if (Gate::allows('interventi.assign')) {
            $tecnicoId = $this->interventoForm['tecnico_id'] ?: null;
            if ($tecnicoId !== null && ! $this->assegnabili()->whereKey($tecnicoId)->exists()) {
                $this->addError('interventoForm.tecnico_id', 'Assegnatario non valido.');

                return;
            }
            $payload['tecnico_id'] = $tecnicoId;
        }

        if ($this->editingInterventoId === null) {
            $intervento = new Intervento($payload + [
                'tenant_id' => $this->strumento->tenant_id, // invariante interventi.tenant_id == strumenti.tenant_id
                'strumento_id' => $this->strumento->id,     // reseller_id resta NULL (ADR-002)
            ]);
            if ($this->interventoForm['gia_eseguito']) {
                // Inserimento storico in un passo: l'hook `saving` regge l'invariante.
                $intervento->stato = StatoIntervento::Fatto;
                $intervento->data_esecuzione = $this->interventoForm['data_esecuzione'];
            }
            $intervento->save();
        } else {
            $this->strumento->interventi()->findOrFail($this->editingInterventoId)->update($payload);
        }

        $this->closeInterventoForm();
    }

    public function closeInterventoForm(): void
    {
        $this->resetInterventoForm();
        $this->editingInterventoId = null;
        $this->showInterventoForm = false;
    }

    public function openCompleta(int $id): void
    {
        $this->authorize('interventi.complete');
        $this->strumento->interventi()->findOrFail($id);

        $this->completingInterventoId = $id;
        $this->dataEsecuzione = today()->toDateString();
        $this->resetValidation();
        $this->showCompletaForm = true;
    }

    public function completa(): void
    {
        $this->authorize('interventi.complete');
        $this->validate(['dataEsecuzione' => ['required', 'date', 'before_or_equal:today']]);

        // Sempre il metodo di dominio, mai update by-query (invariante nel model).
        $this->strumento->interventi()->findOrFail($this->completingInterventoId)
            ->segnaFatto(Carbon::parse($this->dataEsecuzione));

        $this->closeCompleta();
    }

    public function closeCompleta(): void
    {
        $this->reset(['completingInterventoId', 'dataEsecuzione']);
        $this->showCompletaForm = false;
    }

    // --- Forzatura semaforo (S3 punto 5, ADR-005) ---
    //
    // A differenza delle azioni sugli interventi, qui non serve un findOrFail
    // scopato: si agisce sullo strumento GIÀ bindato dalla rotta, quindi
    // l'isolamento è il route-model binding (404 fuori Ente/sotto-albero).

    public function openForza(): void
    {
        $this->authorize('semaforo.force');

        $this->forzaForm = [
            // Default Rosso: è il caso d'uso primario dell'ADR ("non idoneo"
            // dopo verifica). Se già forzato, si riparte da quella scelta.
            'stato' => $this->strumento->forced_state?->value ?? StatoSemaforo::Rosso->value,
            'motivo' => $this->strumento->forced_reason ?? '',
        ];
        $this->resetValidation();
        $this->showForzaForm = true;
    }

    public function forza(): void
    {
        $this->authorize('semaforo.force');
        $this->validate($this->forzaFormRules());

        // Lo stato passa da Rule::in nella validazione; il motivo obbligatorio
        // sul rosso è comunque riverificato dall'invariante nel model.
        $this->strumento->forzaSemaforo(
            StatoSemaforo::from($this->forzaForm['stato']),
            $this->forzaForm['motivo'] ?: null,
        );

        $this->closeForza();
    }

    /**
     * Rimozione senza conferma, come riapri(): è simmetrica e ripetibile (la
     * forzatura si ridà in due click), e la conferma resta alle distruttive.
     */
    public function rimuoviForzatura(): void
    {
        $this->authorize('semaforo.force');
        $this->strumento->rimuoviForzatura();

        $this->closeForza();
    }

    public function closeForza(): void
    {
        $this->reset(['forzaForm']);
        $this->resetValidation();
        $this->showForzaForm = false;
    }

    /** Il motivo è obbligatorio solo per il rosso (ADR-005 + decisione S3). */
    protected function forzaFormRules(): array
    {
        return [
            'forzaForm.stato' => ['required', Rule::in(array_map(fn (StatoSemaforo $c) => $c->value, StatoSemaforo::cases()))],
            'forzaForm.motivo' => $this->forzaForm['stato'] === StatoSemaforo::Rosso->value
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Riapertura senza conferma: azione simmetrica e ripetibile (la conferma
     * resta riservata alle azioni distruttive). Stesso permesso della spunta.
     */
    public function riapri(int $id): void
    {
        $this->authorize('interventi.complete');
        $this->strumento->interventi()->findOrFail($id)->riapri();
    }

    public function openEliminaIntervento(int $id): void
    {
        $this->authorize('interventi.delete');
        $this->strumento->interventi()->findOrFail($id);

        $this->deletingInterventoId = $id;
    }

    public function eliminaIntervento(): void
    {
        $this->authorize('interventi.delete');
        $this->strumento->interventi()->findOrFail($this->deletingInterventoId)->delete();

        $this->deletingInterventoId = null;
    }

    protected function interventoFormRules(): array
    {
        return [
            'interventoForm.descrizione' => ['required', 'string', 'max:1000'],
            'interventoForm.tipo' => ['required', Rule::in(array_map(fn (TipoIntervento $c) => $c->value, TipoIntervento::cases()))],
            'interventoForm.data_scadenza' => ['required', 'date'], // passato permesso: lo storico è legittimo (ADR-005)
            'interventoForm.tecnico_id' => ['nullable', 'integer'],
            'interventoForm.gia_eseguito' => ['boolean'],
            'interventoForm.data_esecuzione' => $this->interventoForm['gia_eseguito']
                ? ['required', 'date', 'before_or_equal:today']
                : ['nullable'],
        ];
    }

    protected function resetInterventoForm(): void
    {
        $this->interventoForm = [
            'descrizione' => '',
            'tipo' => TipoIntervento::Manutenzione->value,
            'data_scadenza' => today()->toDateString(),
            'tecnico_id' => null,
            'gia_eseguito' => false,
            'data_esecuzione' => today()->toDateString(),
        ];
        $this->resetValidation();
    }

    /**
     * Assegnatari proponibili, SPECULARE a Intervento::tecnicoLabel(): utenti
     * dello stesso Ente ∪ Tecnici di piattaforma senza tenant (ADR-007).
     * Un'unica definizione per select (render) e validazione (save): non
     * possono divergere. User non ha global scope: la whitelist è esplicita.
     */
    private function assegnabili(): Builder
    {
        return User::query()->where(fn (Builder $q) => $q
            ->where('tenant_id', $this->strumento->tenant_id)
            ->orWhere(fn (Builder $q) => $q
                ->whereNull('tenant_id')
                ->whereHas('roles', fn ($q) => $q->where('name', 'Tecnico'))));
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
            // Semaforo (ADR-005): sempre lo stato "effettivo" (forzato ?? calcolato).
            'semaforo' => $this->strumento->statoSemaforoEffettivo(),
            'interventi' => $this->interventiPerUrgenza(),
            // Solo a modale aperta e con permesso: a modale chiusa zero query
            // extra su users (il test N+1 del punto 2 lo congela).
            'assegnatari' => $this->showInterventoForm && Gate::allows('interventi.assign')
                ? $this->assegnabili()->orderBy('name')->get()
                : collect(),
            'spostamenti' => $this->strumento->spostamenti()->with(['daNodo', 'aNodo', 'eseguitoBy'])->get(),
            'nodiDestinazione' => $nodiDestinazione,
        ]);
    }

    /**
     * Lista del tab Interventi (S3 punto 2), ordinata per urgenza: scaduti-non-
     * fatti, poi pianificati (scadenza più vicina in cima), infine lo storico.
     *
     * L'ordinamento è in PHP e non in SQL di proposito: un `orderByRaw` con un
     * CASE duplicherebbe in SQL la regola di `Intervento::isScaduto()` (due
     * copie del confine `< oggi` che possono divergere), e servirebbero
     * direzioni miste con NULL, il cui ordinamento cambia da un DB all'altro.
     * Qui il set è di un solo strumento: poche decine di righe già caricate.
     *
     * @return Collection<int, Intervento>
     */
    private function interventiPerUrgenza(): Collection
    {
        if (! Gate::allows('interventi.view')) {
            return collect();
        }

        return $this->strumento->interventi()
            ->with('tecnico') // NON `tecnico:id,name`: tecnicoLabel() legge tenant_id
            ->get()
            ->sortBy([
                fn (Intervento $a, Intervento $b) => $this->urgenza($a) <=> $this->urgenza($b),
                fn (Intervento $a, Intervento $b) => $this->urgenza($a) === 2
                    ? $b->data_esecuzione <=> $a->data_esecuzione // storico: eseguiti di recente in cima
                    : $a->data_scadenza <=> $b->data_scadenza,    // aperti: scadenza più vecchia/vicina in cima
            ])
            ->values();
    }

    /** 0 = scaduto-non-fatto · 1 = pianificato · 2 = fatto. */
    private function urgenza(Intervento $intervento): int
    {
        return match (true) {
            $intervento->isScaduto() => 0,
            $intervento->stato === StatoIntervento::NonFatto => 1,
            default => 2,
        };
    }
}
