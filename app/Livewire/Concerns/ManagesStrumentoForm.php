<?php

namespace App\Livewire\Concerns;

use App\Models\Fornitore;
use App\Models\Strumento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Form condiviso dello Strumento (create in Albero, edit in SchedaStrumento).
 * Usa proprietà array (`strumentoForm`, `parametri`) per non collidere con
 * eventuali proprietà omonime del componente ospite (es. il form-nodo di Albero
 * usa `$nome`). I `parametri_tecnici` sono modellati come righe chiave→valore e
 * persistiti come oggetto JSON.
 */
trait ManagesStrumentoForm
{
    /** @var array{nome:string,modello:?string,matricola:?string,data_installazione:?string} */
    public array $strumentoForm = [
        'nome' => '',
        'modello' => '',
        'matricola' => '',
        'data_installazione' => '',
    ];

    /** @var list<array{chiave:string,valore:string}> */
    public array $parametri = [];

    public function addParametro(): void
    {
        $this->parametri[] = ['chiave' => '', 'valore' => ''];
    }

    public function removeParametro(int $index): void
    {
        unset($this->parametri[$index]);
        $this->parametri = array_values($this->parametri);
    }

    /**
     * Fornitori selezionabili per un Ente (ADR-023): **una sola definizione**,
     * usata dal select del form e dalla validazione al salvataggio.
     *
     * È il precedente di `SchedaStrumento::assegnabili()`, e la ragione è la
     * stessa: se la whitelist del select e il controllo al save fossero due
     * query diverse, un `fornitore_id` forgiato dal browser passerebbe il
     * secondo pur non comparendo nel primo — e le due copie potrebbero
     * divergere alla prima modifica.
     *
     * `$correnteId` riammette il fornitore già associato anche se cestinato:
     * senza, modificare una macchina il cui fornitore è stato cestinato
     * fallirebbe la validazione su un campo che l'utente non ha toccato.
     *
     * @return Builder<Fornitore>
     */
    protected function fornitoriSelezionabili(int $tenantId, ?int $correnteId = null): Builder
    {
        return Fornitore::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where(fn (Builder $q) => $q->whereNull('deleted_at')
                ->when($correnteId !== null, fn (Builder $q) => $q->orWhere('id', $correnteId)))
            ->orderBy('ragione_sociale');
    }

    protected function strumentoFormRules(?int $tenantId = null, ?int $correnteId = null): array
    {
        return [
            // Obbligatorio nel FORM, nullable in schema (ADR-023): l'obbligo
            // riguarda chi inserisce a mano, non le righe storiche né l'import.
            // La regola è condizionata al permesso: un ruolo che il campo non lo
            // vede nemmeno non può essere bloccato da un campo che non ha.
            'strumentoForm.fornitore_id' => Gate::allows('fornitori.view')
                ? ['required', 'integer', Rule::exists('fornitori', 'id')->where(
                    fn ($q) => $q->where('tenant_id', $tenantId)
                )]
                : ['nullable'],
            'strumentoForm.nome' => ['required', 'string', 'max:255'],
            'strumentoForm.modello' => ['nullable', 'string', 'max:255'],
            'strumentoForm.matricola' => ['nullable', 'string', 'max:255'],
            'strumentoForm.data_installazione' => ['nullable', 'date'],
            'parametri' => ['array'],
            'parametri.*.chiave' => ['nullable', 'string', 'max:100'],
            'parametri.*.valore' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function resetStrumentoForm(): void
    {
        $this->strumentoForm = ['nome' => '', 'modello' => '', 'matricola' => '', 'data_installazione' => '', 'fornitore_id' => null];
        $this->parametri = [];
        $this->resetValidation();
    }

    protected function fillStrumentoForm(Strumento $strumento): void
    {
        $this->strumentoForm = [
            'nome' => $strumento->nome,
            'modello' => $strumento->modello,
            'matricola' => $strumento->matricola,
            'data_installazione' => $strumento->data_installazione?->format('Y-m-d') ?? '',
            'fornitore_id' => $strumento->fornitore_id,
        ];

        $this->parametri = collect($strumento->parametri_tecnici ?? [])
            ->map(fn ($valore, $chiave) => ['chiave' => (string) $chiave, 'valore' => (string) $valore])
            ->values()
            ->all();
    }

    /**
     * Payload validato pronto per create/update (parametri righe → oggetto JSON,
     * scartando le righe con chiave vuota).
     */
    protected function strumentoPayload(): array
    {
        $parametri = collect($this->parametri)
            ->filter(fn ($r) => trim($r['chiave'] ?? '') !== '')
            ->mapWithKeys(fn ($r) => [trim($r['chiave']) => $r['valore'] ?? ''])
            ->all();

        return [
            'nome' => $this->strumentoForm['nome'],
            'modello' => $this->strumentoForm['modello'] ?: null,
            'matricola' => $this->strumentoForm['matricola'] ?: null,
            'data_installazione' => $this->strumentoForm['data_installazione'] ?: null,
            'fornitore_id' => $this->strumentoForm['fornitore_id'] ?: null,
            'parametri_tecnici' => $parametri === [] ? null : $parametri,
        ];
    }
}
