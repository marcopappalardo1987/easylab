<?php

namespace App\Livewire\Tenancy;

use App\Models\UnitaOrganizzativa;
use App\Models\User;
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

        if ($ente === null || ! $this->user()->passaAllEnte($ente)) {
            return; // fail-closed silenzioso: la UI non offre bersagli illegittimi.
        }

        // Full reload verso la dashboard, non navigate SPA e non la pagina
        // corrente: il cambio di tenant cambia tutto (sidebar, liste, scope) e
        // la pagina da cui si parte appartiene al tenant appena lasciato.
        $this->redirect(route('dashboard'));
    }

    /**
     * Le sedi degli account di cui l'utente è membro, esclusi gli account in
     * lockout. Query servita dall'unique (user_id, account_id) del pivot.
     *
     * @return Builder<UnitaOrganizzativa>
     */
    private function sedi()
    {
        $user = $this->user();

        return UnitaOrganizzativa::withoutGlobalScopes()
            ->where('tipo', 'ente')
            ->whereNull('deleted_at')
            ->whereIn('account_id', $user->accounts()->where('is_locked', false)->select('accounts.id'));
    }

    /**
     * @return Collection<int, UnitaOrganizzativa>
     */
    public function altreSedi(): Collection
    {
        if (! $this->aperto || $this->impersonando()) {
            return new Collection;
        }

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
            'tendina' => $this->sediRaggiungibili > 0 && ! $this->impersonando(),
        ]);
    }
}
