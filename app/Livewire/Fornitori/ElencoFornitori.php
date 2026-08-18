<?php

namespace App\Livewire\Fornitori;

use App\Models\Fornitore;
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

        $validato = $this->validate([
            'form.ragione_sociale' => ['required', 'string', 'max:255'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.telefono' => ['nullable', 'string', 'max:50'],
            'form.note' => ['nullable', 'string', 'max:1000'],
        ])['form'];

        if ($this->editingId !== null) {
            Fornitore::findOrFail($this->editingId)->update($validato);
        } else {
            // `tenant_id` lo scrive BelongsToTenant in `creating`: passarlo dal
            // form significherebbe fidarsi di un valore che arriva dal browser.
            Fornitore::create($validato);
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
        $this->reset(['editingId', 'form']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.fornitori.elenco-fornitori', [
            // `withCount`: la colonna «macchine» serve a capire perché un
            // fornitore non si può cancellare, senza una query per riga.
            'fornitori' => Fornitore::withCount('strumenti')->orderBy('ragione_sociale')->get(),
        ]);
    }
}
