<?php

namespace App\Livewire\Fornitori;

use App\Models\Fornitore;
use App\Models\UnitaOrganizzativa;
use App\Support\Fornitori\RegoleFornitore;
use App\Support\Tenancy\CurrentTenant;
use App\Support\Tenancy\SediSeguite;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Anagrafica fornitori dell'Ente: elenco e CRUD (🔗 ADR-023, ERD §7.3).
 *
 * **Non è un extra del blocco, è la sua precondizione**: il fornitore è
 * obbligatorio nel form dello strumento, e senza una schermata da cui popolare
 * l'anagrafica `strumenti.create` sarebbe inutilizzabile per chiunque — un
 * campo obbligatorio che nessuno può compilare. I permessi `fornitori.*`
 * esistono a catalogo da S1 e non avevano mai avuto un consumatore.
 *
 * Autorizzazione su due livelli come ovunque nel progetto: `can:fornitori.view`
 * sulla rotta per entrare, `authorize()` in ogni azione per agire. Il primo
 * senza il secondo lascerebbe scrivere a chi può solo leggere.
 */
#[Layout('components.layouts.app')]
class ElencoFornitori extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array{ragione_sociale:string,email:?string,telefono:?string,note:?string} */
    public array $form = ['ragione_sociale' => '', 'email' => null, 'telefono' => null, 'note' => null];

    /**
     * La sede del fornitore **nuovo**, per chi ne segue più di una (🔗 ADR-046).
     *
     * `mixed` perché arriva dal browser: la valida `save()` contro le sedi
     * davvero in vista, e fuori da quelle non scrive.
     */
    public mixed $sedeId = null;

    private ?Collection $sediCache = null;

    public ?int $deletingId = null;

    public ?string $notice = null;

    public function nuovo(): void
    {
        $this->authorize('fornitori.create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('fornitori.update');
        $fornitore = Fornitore::findOrFail($id); // 404 fuori Ente: lo scope filtra

        $this->editingId = $fornitore->id;
        $this->form = [
            'ragione_sociale' => $fornitore->ragione_sociale,
            'email' => $fornitore->email,
            'telefono' => $fornitore->telefono,
            'note' => $fornitore->note,
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize($this->editingId === null ? 'fornitori.create' : 'fornitori.update');

        // Le stesse del selettore che crea un fornitore al volo (ADR-051).
        $regole = RegoleFornitore::per('form');

        // 🔴 ADR-046: il catalogo fornitori è per sede, e un fornitore nuovo non
        // ha un genitore da cui ereditarla. Chi ne segue più di una la dichiara;
        // la regola si aggiunge solo allora, così per tutti gli altri `sedeId`
        // resta una property che nessuno legge.
        $vaScelta = $this->editingId === null && $this->sedi()->count() > 1;

        if ($vaScelta) {
            $regole['sedeId'] = ['required', Rule::in($this->sedi()->modelKeys())];
        }

        $validato = $this->validate($regole, attributes: ['sedeId' => 'sede'])['form'];

        if ($this->editingId !== null) {
            Fornitore::findOrFail($this->editingId)->update($validato);
        } else {
            // Per chi ha un Ente proprio `tenant_id` lo scrive BelongsToTenant
            // in `creating`, e ciò che arriva dal browser non conta. Per chi
            // segue più sedi è quella scelta, già validata qui sopra.
            Fornitore::create($validato + $this->sedeDelNuovo($vaScelta));
        }

        $this->closeForm();
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function confermaElimina(int $id): void
    {
        $this->authorize('fornitori.delete');
        Fornitore::findOrFail($id);
        $this->deletingId = $id;
    }

    public function elimina(): void
    {
        $this->authorize('fornitori.delete');

        try {
            Fornitore::findOrFail($this->deletingId)->delete();
        } catch (RuntimeException $e) {
            // La guardia vive nel model (vale anche per import e console): qui
            // si traduce in un avviso invece di una pagina di errore.
            $this->notice = $e->getMessage();
        }

        $this->deletingId = null;
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'form', 'sedeId']);
        $this->resetValidation();
    }

    /**
     * Le sedi in vista, lette una volta per richiesta — e **solo** per chi può
     * seguirne più di una. Per tutti gli altri è una collezione vuota e zero
     * query: lo tiene fermo il budget di `QueryAltrePagineTest`.
     *
     * @return Collection<int,UnitaOrganizzativa>
     */
    private function sedi(): Collection
    {
        return $this->sediCache ??= SediSeguite::piuClienti() ? SediSeguite::elenco() : collect();
    }

    /**
     * Il `tenant_id` del fornitore nuovo, o nulla se lo fissa `BelongsToTenant`.
     *
     * - più sedi in vista → quella scelta (già validata);
     * - un Ente proprio → vuoto: lo scrive il trait, come sempre;
     * - nessun Ente e una sede sola in vista (il Gestore) → quella;
     * - nessun Ente e nessuna sede → 403: non c'è un catalogo in cui scrivere.
     *
     * @return array{tenant_id?: int}
     */
    private function sedeDelNuovo(bool $scelta): array
    {
        if ($scelta) {
            return ['tenant_id' => (int) $this->sedeId];
        }

        if (CurrentTenant::id() !== null) {
            return [];
        }

        abort_if($this->sedi()->count() !== 1, 403);

        return ['tenant_id' => (int) $this->sedi()->first()->id];
    }

    public function render()
    {
        return view('livewire.fornitori.elenco-fornitori', [
            // `withCount`: la colonna «macchine» serve a capire perché un
            // fornitore non si può cancellare, senza una query per riga.
            // «Ricambi» conta i pezzi montati comprati da lui (ADR-051): è
            // l'altra ragione per cui un fornitore non si può cancellare.
            'fornitori' => Fornitore::withCount(['strumenti', 'ricambiMontati'])->orderBy('ragione_sociale')->get(),
            // Vuota per chi lavora su una sede sola: niente colonna, niente tendina.
            'sedi' => $this->sedi()->count() > 1 ? $this->sedi()->keyBy('id') : collect(),
        ]);
    }
}
