<?php

namespace App\Livewire\Concerns;

use App\Models\Strumento;
use Illuminate\Support\Facades\Gate;

/**
 * Form condiviso dello Strumento (create in Albero, edit in SchedaStrumento).
 * Usa proprietà array (`strumentoForm`, `parametri`) per non collidere con
 * eventuali proprietà omonime del componente ospite (es. il form-nodo di Albero
 * usa `$nome`). I `parametri_tecnici` sono modellati come righe chiave→valore e
 * persistiti come oggetto JSON.
 */
trait ManagesStrumentoForm
{
    // Il campo fornitore del form è un selettore con ricerca e creazione al
    // volo (ADR-051): la definizione di «selezionabile» vive lì.
    use SceglieFornitore;

    /**
     * I campi vuoti del modulo. Il referente e le note (🔗 ADR-054) sono
     * stringhe vuote e non `null`: sono campi di testo, e un `null` legato a
     * un `wire:model` diventa comunque una stringa al primo tasto.
     */
    private const FORM_VUOTO = [
        'nome' => '',
        'modello' => '',
        'matricola' => '',
        'data_installazione' => '',
        'fornitore_id' => null,
        'referente_nome' => '',
        'referente_cognome' => '',
        'referente_email' => '',
        'referente_cellulare' => '',
        'note' => '',
    ];

    /** @var array{nome:string,modello:?string,matricola:?string,data_installazione:?string,fornitore_id:?int,referente_nome:string,referente_cognome:string,referente_email:string,referente_cellulare:string,note:string} */
    public array $strumentoForm = self::FORM_VUOTO;

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

    protected function strumentoFormRules(?int $tenantId = null, ?int $correnteId = null): array
    {
        return [
            // Obbligatorio nel FORM, nullable in schema (ADR-023): l'obbligo
            // riguarda chi inserisce a mano, non le righe storiche né l'import.
            // La regola è condizionata al permesso: un ruolo che il campo non lo
            // vede nemmeno non può essere bloccato da un campo che non ha.
            // T1a (S7): la stessa lista del selettore — vivi del tenant, più il
            // cestinato già associato. Prima un id cestinato forgiato passava.
            'strumentoForm.fornitore_id' => $this->regolaFornitore((int) $tenantId, $correnteId, obbligatorio: true),
            'strumentoForm.nome' => ['required', 'string', 'max:255'],
            'strumentoForm.modello' => ['nullable', 'string', 'max:255'],
            'strumentoForm.matricola' => ['nullable', 'string', 'max:255'],
            'strumentoForm.data_installazione' => ['nullable', 'date'],
            // 🔗 ADR-054: il referente è facoltativo, campo per campo.
            // L'indirizzo è ciò che decide se le email dello strumento
            // arrivano anche a lui, quindi dev'essere un indirizzo.
            'strumentoForm.referente_nome' => ['nullable', 'string', 'max:255'],
            'strumentoForm.referente_cognome' => ['nullable', 'string', 'max:255'],
            'strumentoForm.referente_email' => ['nullable', 'string', 'email', 'max:255'],
            'strumentoForm.referente_cellulare' => ['nullable', 'string', 'max:50'],
            'strumentoForm.note' => ['nullable', 'string', 'max:2000'],
            'parametri' => ['array'],
            'parametri.*.chiave' => ['nullable', 'string', 'max:100'],
            'parametri.*.valore' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * I nomi dei campi come li legge una persona.
     *
     * ⚠️ Senza questi, il messaggio usciva come «Il campo strumento
     * form.fornitore id è obbligatorio»: Laravel umanizza il percorso della
     * property, e `strumentoForm.fornitore_id` diventa una frase che non nomina
     * nulla di ciò che c'è a schermo. Segnalato da Marco il 29 Ago 2026
     * insieme al difetto del segnaposto — lo stesso errore, letto due volte.
     *
     * @return array<string,string>
     */
    protected function strumentoFormAttributi(): array
    {
        return [
            'strumentoForm.nome' => 'nome',
            'strumentoForm.modello' => 'modello',
            'strumentoForm.matricola' => 'matricola',
            'strumentoForm.data_installazione' => 'data di installazione',
            'strumentoForm.fornitore_id' => 'fornitore',
            'strumentoForm.referente_nome' => 'nome del referente',
            'strumentoForm.referente_cognome' => 'cognome del referente',
            'strumentoForm.referente_email' => 'email del referente',
            'strumentoForm.referente_cellulare' => 'cellulare del referente',
            'strumentoForm.note' => 'note',
            'parametri.*.chiave' => 'nome del parametro',
            'parametri.*.valore' => 'valore del parametro',
        ];
    }

    /**
     * Un indirizzo incollato porta spesso uno spazio in coda: non è un errore
     * di chi scrive, e la regola `email` lo rifiuterebbe.
     */
    public function updatedStrumentoForm(mixed $valore, string $chiave): void
    {
        if ($chiave === 'referente_email' && is_string($valore)) {
            $this->strumentoForm['referente_email'] = trim($valore);
        }
    }

    protected function resetStrumentoForm(): void
    {
        $this->strumentoForm = self::FORM_VUOTO;
        $this->parametri = [];
        // Un selettore rimasto aperto riaprirebbe il prossimo form già a metà.
        $this->chiudiFornitori();
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
            'referente_nome' => (string) $strumento->referente_nome,
            'referente_cognome' => (string) $strumento->referente_cognome,
            'referente_email' => (string) $strumento->referente_email,
            'referente_cellulare' => (string) $strumento->referente_cellulare,
            'note' => (string) $strumento->note,
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

        // Uno spazio non è un referente, e un campo svuotato lo toglie: è il
        // modo in cui si fanno smettere le email (🔗 ADR-054).
        $testo = function (string $campo): ?string {
            $valore = trim((string) ($this->strumentoForm[$campo] ?? ''));

            return $valore === '' ? null : $valore;
        };

        $payload = [
            'nome' => $this->strumentoForm['nome'],
            'modello' => $this->strumentoForm['modello'] ?: null,
            'matricola' => $this->strumentoForm['matricola'] ?: null,
            'data_installazione' => $this->strumentoForm['data_installazione'] ?: null,
            'fornitore_id' => $this->strumentoForm['fornitore_id'] ?: null,
            'parametri_tecnici' => $parametri === [] ? null : $parametri,
            'referente_nome' => $testo('referente_nome'),
            'referente_cognome' => $testo('referente_cognome'),
            'referente_email' => $testo('referente_email'),
            'referente_cellulare' => $testo('referente_cellulare'),
            'note' => $testo('note'),
        ];

        // T1a (S7): senza `fornitori.view` la regola del campo è `nullable` e l'id
        // non è validato da nulla. Chi il campo non lo vede non lo scrive: in
        // modifica resta il fornitore di prima, in creazione nessuno.
        if (! Gate::allows('fornitori.view')) {
            unset($payload['fornitore_id']);
        }

        return $payload;
    }
}
