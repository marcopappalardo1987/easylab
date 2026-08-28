<?php

namespace App\Livewire\Tenancy;

use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Il contesto Ente in top bar, con lo switcher fra le proprie sedi (ADR-032).
 *
 * NON è il «selettore di tenant» che ADR-018 ha scartato: quello apriva un
 * secondo percorso di accesso verso dati ALTRUI, questo naviga fra Enti dello
 * stesso account — il confine che lì andava protetto qui non esiste, e la
 * guardia vera sta comunque in `User::passaAllEnte()`, non in questa UI.
 *
 * Terzo componente Livewire annidato del progetto (disciplina della
 * Campanella): si monta su ogni pagina, quindi `mount()` fa due sole letture —
 * il nome dell'Ente corrente e il conteggio delle sedi raggiungibili.
 * L'elenco arriva solo a tendina aperta. Per chi ha una sede sola il
 * componente degrada al solo nome, che è già un guadagno: finora il Design
 * System §5.7 prometteva il «contesto (Ente/ruolo)» e la top bar non lo
 * mostrava affatto.
 *
 * Durante l'impersonazione il nome resta (è contesto utile proprio a chi
 * impersona) ma la tendina è soppressa: il tenant seguito è quello
 * dell'impersonato, e il dominio rifiuterebbe comunque — la UI nasconde, il
 * dominio nega.
 */
class SwitcherEnte extends Component
{
    public bool $aperto = false;

    public ?string $nomeEnte = null;

    /** Sedi raggiungibili oltre a quella corrente; governa la tendina. */
    public int $sediRaggiungibili = 0;

    public function mount(): void
    {
        $user = $this->user();

        if ($user->tenant_id === null) {
            return; // tecnico esterno / utente di piattaforma: nessun contesto Ente.
        }

        $this->nomeEnte = $user->ente?->nome;
        $this->sediRaggiungibili = $this->sedi()->where('id', '!=', $user->tenant_id)->count();
    }

    public function apri(): void
    {
        $this->aperto = true;
    }

    public function passa(int $enteId): void
    {
        $ente = UnitaOrganizzativa::withoutGlobalScopes()->find($enteId);

        if ($ente === null) {
            return; // fail-closed silenzioso: la UI non offre bersagli illegittimi.
        }

        // 🔴 Impersonando, lo spostamento è EFFIMERO: vive nella sessione di
        // chi impersona e non tocca `users.tenant_id`. `passaAllEnte()`
        // continua a rifiutare — ed è giusto che rifiuti: quella scrive, e
        // scrivere qui sarebbe una modifica permanente fatta per conto del
        // cliente, che si ritroverebbe al prossimo accesso in una sede che non
        // ha scelto lui.
        //
        // ⛔ La legittimità del bersaglio si verifica QUI, dove la chiave si
        // scrive: dev'essere una sede raggiungibile dall'impersonato, cioè
        // dello stesso account e non in lockout. `CurrentTenant` a valle non
        // ricontrolla — costerebbe una query per richiesta — quindi questa
        // riga è l'unica guardia e non può essere allentata.
        if ($this->impersonando()) {
            $legittima = $this->sedi()->whereKey($ente->id)->exists();

            if (! $legittima) {
                return;
            }

            session([CurrentTenant::SEDE_IMPERSONATA => [
                'utente' => $this->user()->getKey(),
                'ente' => $ente->id,
            ]]);

            // L'attraversamento di un confine si registra, come l'impersonazione
            // stessa e lo switch permanente: il causer è l'impersonato (è chi la
            // guard espone), e la riga dell'impersonazione dice chi c'è dietro.
            activity(AuditLog::NAME)
                ->causedBy($this->user())
                ->performedOn($ente)
                ->withProperties(['effimero' => true, 'impersonazione' => true])
                ->log('sede.cambiata');

            $this->redirect(route('dashboard'));

            return;
        }

        if (! $this->user()->passaAllEnte($ente)) {
            return;
        }

        // Full reload verso la dashboard, non navigate SPA e non la pagina
        // corrente: il cambio di tenant cambia tutto (sidebar, liste, scope) e
        // la pagina da cui si parte appartiene al tenant appena lasciato.
        $this->redirect(route('dashboard'));
    }

    /**
     * La stessa domanda della pagina /bloccato («dove posso ancora andare?»),
     * la stessa risposta: la query vive in User::sediRaggiungibili() (ADR-013).
     *
     * @return Builder<UnitaOrganizzativa>
     */
    private function sedi()
    {
        return $this->user()->sediRaggiungibili();
    }

    /**
     * 🔴 **L'elenco si calcola SEMPRE, e non più solo a tendina aperta.**
     *
     * La versione pigra era una scelta di costo — questo componente si monta su
     * ogni pagina — ma il prezzo era che il pannello **non si apriva**: il click
     * chiedeva l'elenco al server, il server rispondeva, Livewire ridisegnava il
     * frammento e Alpine ripartiva da capo, richiudendolo. Due tentativi di
     * tenere lo stato al di là del ridisegno non hanno funzionato sull'ambiente
     * vero; la strada giusta era togliere il ridisegno, non sopravvivergli.
     *
     * Il costo reale è **una query in più**, di soli `id` e `nome`, e **solo per
     * chi ha più di una sede** — cioè una minoranza dei clienti: `mount()`
     * calcola già `sediRaggiungibili`, e dove quel conteggio è zero la tendina
     * non esiste e questo metodo non viene mai chiamato dalla vista.
     *
     * In cambio la tendina si comporta come il menù utente, che funziona da
     * sempre: apertura e chiusura tutte nel browser, nessuna chiamata al server
     * finché non si sceglie davvero una sede.
     *
     * @return Collection<int, UnitaOrganizzativa>
     */
    public function altreSedi(): Collection
    {
        return $this->sedi()
            ->where('id', '!=', $this->user()->tenant_id)
            ->orderBy('nome')
            ->get(['id', 'nome']);
    }

    public function impersonando(): bool
    {
        return app('impersonate')->isImpersonating();
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render()
    {
        return view('livewire.tenancy.switcher-ente', [
            'altreSedi' => $this->altreSedi(),
            // 🔴 La tendina vive anche durante un'impersonazione (28 Ago 2026):
            // chi impersona un cliente con più sedi deve poterle vedere tutte —
            // è la ragione per cui impersona. Lo spostamento che ne segue è
            // effimero e non tocca il contesto del cliente (vedi `passa()`).
            'tendina' => $this->sediRaggiungibili > 0,
        ]);
    }
}
