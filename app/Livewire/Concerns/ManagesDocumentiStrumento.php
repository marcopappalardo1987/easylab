<?php

namespace App\Livewire\Concerns;

use App\Actions\Documenti\CaricaDocumento;
use App\Enums\TipoDocumento;
use App\Models\Documento;
use App\Models\Intervento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\WithFileUploads;

/**
 * Stato e azioni del tab Documenti (🔗 ADR-009/025/026).
 *
 * Trait per la ragione di `ManagesRicambiStrumento`: la vista campo del tecnico
 * (blocco 10) dovrà allegare il report di fine lavoro, e due copie sarebbero
 * due punti di autorizzazione da tenere allineati.
 */
trait ManagesDocumentiStrumento
{
    use WithFileUploads;

    public bool $showDocumentoForm = false;

    public $fileDocumento;

    public string $tipoDocumento = '';

    /** Intervento a cui allegare; null = il documento è della macchina. */
    public ?int $documentoInterventoId = null;

    public ?int $deletingDocumentoId = null;

    public function openCaricaDocumento(?int $interventoId = null): void
    {
        $this->authorize('documenti.upload');
        $this->reset(['fileDocumento', 'tipoDocumento']);
        $this->documentoInterventoId = $interventoId;

        // Preselezione onesta: si allega a una taratura quasi solo il
        // certificato, e a una macchina quasi solo un manuale. Resta
        // cambiabile — è un default, non un vincolo.
        $this->tipoDocumento = $interventoId !== null
            ? TipoDocumento::CertificatoTaratura->value
            : TipoDocumento::Manuale->value;
        $this->showDocumentoForm = true;
    }

    public function closeDocumentoForm(): void
    {
        $this->reset(['showDocumentoForm', 'fileDocumento', 'tipoDocumento', 'documentoInterventoId']);
    }

    public function salvaDocumento(): void
    {
        $this->authorize('documenti.upload');

        $this->validate([
            // 20 MB e i soli formati che un laboratorio allega davvero: ADR-026
            // avverte che con video o allegati pesanti la scelta del download
            // mediato andrebbe rivista con numeri alla mano (l'egress di B2 è
            // gratuito fino a 3× lo storage medio, poi si paga).
            'fileDocumento' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
            'tipoDocumento' => ['required', 'string'],
        ]);

        // Il soggetto si risolve DALLA relazione, non dall'id nudo: `findOrFail`
        // sulla relazione riapplica gli scope e vincola `strumento_id`, quindi
        // un id di un altro strumento dà 404 invece di allegare altrove.
        $soggetto = $this->documentoInterventoId !== null
            ? $this->strumento->interventi()->findOrFail($this->documentoInterventoId)
            : $this->strumento;

        app(CaricaDocumento::class)->esegui(
            $soggetto,
            $this->fileDocumento,
            TipoDocumento::from($this->tipoDocumento),
        );

        $this->closeDocumentoForm();
    }

    public function openEliminaDocumento(int $id): void
    {
        $this->authorize('documenti.delete');
        $this->documentiDelloStrumento()->findOrFail($id);
        $this->deletingDocumentoId = $id;
    }

    public function eliminaDocumento(): void
    {
        $this->authorize('documenti.delete');

        // Soft delete: il file resta nel bucket. Cancellarlo subito renderebbe
        // il ripristino una bugia — una riga che torna e punta al nulla.
        $this->documentiDelloStrumento()->findOrFail($this->deletingDocumentoId)->delete();

        $this->deletingDocumentoId = null;
    }

    /** @return Builder<Documento> */
    protected function documentiDelloStrumento()
    {
        return Documento::where('strumento_id', $this->strumento->id);
    }

    public function documentiMostrati()
    {
        if (! Gate::allows('documenti.view')) {
            return collect();
        }

        return $this->documentiDelloStrumento()
            ->with(['documentabile', 'caricatoBy'])
            ->orderByDesc('created_at')
            ->get();
    }

    /** Gli interventi a cui si può allegare: serve al select del form. */
    public function interventiAllegabili()
    {
        return $this->showDocumentoForm && Gate::allows('interventi.view')
            ? $this->strumento->interventi()->get(['id', 'descrizione', 'data_scadenza'])
            : collect();
    }

    /**
     * Il certificato di ogni taratura, indicizzato per intervento.
     *
     * Serve alla riga della taratura nel tab Interventi: **il certificato è la
     * prova legale del lavoro, e il posto in cui l'utente lo cerca è la riga
     * della taratura**, non un archivio piatto. Un tab Documenti generico
     * soddisfa la lettera di ADR-009 e non il suo scopo.
     *
     * Una query sola per l'intera lista: `keyBy` sul soggetto, e la vista
     * legge dall'array. Con un `has()` per riga sarebbe una N+1 su una tabella
     * che cresce con lo storico.
     *
     * @return Collection<int, Documento>
     */
    public function certificatiPerIntervento()
    {
        if (! Gate::allows('documenti.view')) {
            return collect();
        }

        return $this->documentiDelloStrumento()
            ->where('documentabile_type', Intervento::class)
            ->where('tipo', TipoDocumento::CertificatoTaratura->value)
            ->orderByDesc('created_at')
            ->get()
            ->keyBy('documentabile_id');
    }

    /** @return array<string,string> */
    public function tipiDocumento(): array
    {
        return collect(TipoDocumento::cases())
            ->mapWithKeys(fn (TipoDocumento $t) => [$t->value => $t->label()])
            ->all();
    }
}
