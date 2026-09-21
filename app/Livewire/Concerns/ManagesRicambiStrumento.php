<?php

namespace App\Livewire\Concerns;

use App\Models\Garanzia;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Scopes\GaranziaRicambioPrivacyScope;
use App\Rules\NomeRicambio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Lettura e correzione dei pezzi montati: lo stato e le azioni del tab Ricambi
 * (🔗 ADR-008/022, sopra 🔗 ADR-020 e 🔗 ADR-029).
 *
 * **Trait e non metodi in `SchedaStrumento`** per la ragione di
 * `ManagesStrumentoForm`, non perché quel file sia lungo: la **vista campo del
 * tecnico** (blocco 10) dovrà correggere le stesse righe, e un trait è ciò che
 * glielo fa ereditare invece di riscriverlo. Due copie della stessa correzione
 * sarebbero due punti di autorizzazione da tenere allineati.
 *
 * ⚠️ **Le ability sono quelle della Policy (`view`/`manage` su `Garanzia`), mai
 * i permessi nudi** (🔗 ADR-029): `spatie` registra un `Gate::before` che
 * concede appena il permesso esiste sul ruolo, quindi `garanzie.ricambio.manage`
 * scavalcherebbe l'impostazione dell'Ente. È lo stesso errore già trovato in
 * `_panoramica.blade.php` il 15 Ago, e la roadmap lo suggerisce ancora nella
 * voce di questo blocco: qui si fa il contrario di quanto lì scritto.
 */
trait ManagesRicambiStrumento
{
    public bool $showRicambioForm = false;

    public ?int $editingUtilizzoId = null;

    /** @var array{nome:string,quantita:int,data:?string,scadenza_garanzia:?string} */
    public array $ricambioForm = [
        'nome' => '',
        'quantita' => 1,
        'data' => null,
        'scadenza_garanzia' => null,
    ];

    public ?int $deletingUtilizzoId = null;

    /**
     * Chi può correggere una riga già montata.
     *
     * Tre permessi e non uno: correggere il nome tocca il catalogo, la riga
     * tocca l'utilizzo, la scadenza tocca la garanzia. Mostrare il pulsante a
     * chi poi prende 403 è peggio che non mostrarlo — stessa scelta di
     * `puoRegistrareRicambi()`.
     */
    public function puoCorreggereRicambi(): bool
    {
        return Gate::allows('ricambio_utilizzo.update')
            && Gate::allows('manage', Garanzia::class);
    }

    public function openCorreggiRicambio(int $id): void
    {
        $this->authorize('ricambio_utilizzo.update');

        // Dalla relazione: riapplica TenantScope e livello 2 e vincola
        // `strumento_id` — 404 per una riga di un'altra macchina.
        $utilizzo = $this->strumento->ricambiUtilizzati()->with('ricambio')->findOrFail($id);

        $garanzia = $utilizzo->garanzia()
            ->withoutGlobalScope(GaranziaRicambioPrivacyScope::class)
            ->first();

        $this->editingUtilizzoId = $utilizzo->id;
        $this->ricambioForm = [
            'nome' => $utilizzo->ricambio->nome,
            'quantita' => $utilizzo->quantita,
            'data' => $utilizzo->data?->toDateString(),
            // La scadenza si mostra solo a chi ha titolo a vederla: per gli
            // altri il campo non esiste, e al salvataggio non viene toccata.
            'scadenza_garanzia' => Gate::allows('view', Garanzia::class)
                ? $garanzia?->data_scadenza_effettiva?->toDateString()
                : null,
        ];

        $this->showRicambioForm = true;
    }

    public function closeRicambioForm(): void
    {
        $this->reset(['showRicambioForm', 'editingUtilizzoId', 'ricambioForm', 'suggerimenti', 'ricambioAttivo']);
    }

    /**
     * Selezione dal combobox della correzione.
     *
     * Handler proprio e non `scegliRicambio()` del form intervento: là l'indice
     * identifica una riga del repeater, qui la riga è una sola e l'indice 0 non
     * significherebbe niente. Condividere il metodo avrebbe richiesto un ramo
     * dentro, cioè due comportamenti in una firma sola.
     */
    public function scegliRicambioCorrezione(int $index, string $nome): void
    {
        $this->ricambioForm['nome'] = $nome;
        $this->suggerimenti = [];
        $this->ricambioAttivo = null;
    }

    /** Azzera la data: «il pezzo non è ancora stato montato», che è diverso da un campo svuotato per sbaglio. */
    public function segnaNonMontato(): void
    {
        $this->ricambioForm['data'] = null;
    }

    public function salvaRicambio(): void
    {
        $this->authorize('ricambio_utilizzo.update');
        $this->authorize('manage', Garanzia::class);

        $utilizzo = $this->strumento->ricambiUtilizzati()->findOrFail($this->editingUtilizzoId);

        $validato = $this->validate([
            'ricambioForm.nome' => ['required', 'string', 'max:255', new NomeRicambio],
            // Tetto (T1cB-8): la colonna è `integer`, e 3 miliardi sono un 500 su Postgres.
            'ricambioForm.quantita' => ['required', 'integer', 'min:1', 'max:100000'],
            'ricambioForm.data' => ['nullable', 'date'],
            'ricambioForm.scadenza_garanzia' => ['nullable', 'date'],
        ])['ricambioForm'];

        // T1a (S7): un nome che non c'è a catalogo diventa una voce nuova in
        // `collegaOCrea()`, quindi vuole lo stesso permesso del form intervento.
        $esisteGia = Ricambio::query()
            ->where('tenant_id', $utilizzo->tenant_id)
            ->where('nome_normalizzato', Ricambio::normalizzaNome($validato['nome']))
            ->exists();
        if (! $esisteGia) {
            $this->authorize('ricambi.create');
        }

        // Fuori dalla transazione: un `return` dentro la closure COMMITTA
        // (trappola nota), quindi validazione e authorize stanno prima.
        DB::transaction(function () use ($utilizzo, $validato): void {
            $ricambio = Ricambio::collegaOCrea($validato['nome'], (int) $utilizzo->tenant_id);

            $utilizzo->update([
                'ricambio_id' => $ricambio->id,
                'quantita' => $validato['quantita'],
            ]);

            // `fissaMontaggio` e non un update diretto: porta con sé
            // l'allineamento della garanzia e la marcatura `data_manuale`, che
            // è ciò che impedisce alla chiusura dell'intervento di riscrivere
            // questa correzione.
            $utilizzo->fissaMontaggio(
                $validato['data'] ? Carbon::parse($validato['data']) : null,
                manuale: true,
            );

            if (filled($validato['scadenza_garanzia'])) {
                $garanzia = $utilizzo->garanzia()
                    ->withoutGlobalScope(GaranziaRicambioPrivacyScope::class)
                    ->first();

                $garanzia?->fissaScadenzaDichiarata($validato['scadenza_garanzia'])->save();
            }
        });

        $this->closeRicambioForm();
    }

    public function openRimuoviRicambio(int $id): void
    {
        $this->authorize('ricambio_utilizzo.delete');
        $this->strumento->ricambiUtilizzati()->findOrFail($id); // 404 fuori scope

        $this->deletingUtilizzoId = $id;
    }

    public function rimuoviRicambio(): void
    {
        $this->authorize('ricambio_utilizzo.delete');
        $this->authorize('manage', Garanzia::class);

        $this->strumento->ricambiUtilizzati()
            ->findOrFail($this->deletingUtilizzoId)
            ->cestinaConGaranzia();

        $this->deletingUtilizzoId = null;
    }

    /** Le righe del tab, con quel che serve a mostrarle senza N+1. */
    public function ricambiMontati()
    {
        if (! Gate::allows('ricambio_utilizzo.view')) {
            return collect();
        }

        $righe = $this->strumento->ricambiUtilizzati()->with(['ricambio', 'intervento'])->get();

        // Le garanzie in UNA query, e solo per chi ha titolo a vederle: il
        // `with('garanzia')` passerebbe dal privacy scope riga per riga.
        $garanzie = Gate::allows('view', Garanzia::class)
            ? Garanzia::whereIn('ricambio_utilizzo_id', $righe->modelKeys())->get()->keyBy('ricambio_utilizzo_id')
            : collect();

        return $righe->each(fn (RicambioUtilizzo $r) => $r->setRelation('garanzia', $garanzie->get($r->id)));
    }
}
