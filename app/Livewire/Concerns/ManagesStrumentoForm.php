<?php

namespace App\Livewire\Concerns;

use App\Models\Strumento;

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

    protected function strumentoFormRules(): array
    {
        return [
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
        $this->strumentoForm = ['nome' => '', 'modello' => '', 'matricola' => '', 'data_installazione' => ''];
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
            'parametri_tecnici' => $parametri === [] ? null : $parametri,
        ];
    }
}
