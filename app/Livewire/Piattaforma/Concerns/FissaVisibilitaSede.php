<?php

namespace App\Livewire\Piattaforma\Concerns;

use App\Enums\TipoUnitaOrganizzativa;
use App\Enums\VisibilitaGaranzieRicambio;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * La visibilità delle garanzie ricambio, per sede (S6 — consegna da ADR-029).
 *
 * È l'unica delle quattro leve che scrive **fuori dall'Account**, ed è quindi
 * l'unico percorso di scrittura cross-tenant di tutta la cabina: `roles.manage`
 * apre, ma l'id della sede arriva dal browser.
 *
 * ⚠️ **Gate `roles.manage`, non `unita_organizzativa.update`.** Il secondo ce
 * l'ha anche l'Admin dell'Ente, e questa non è un'impostazione dell'anagrafica:
 * è una **clausola del rapporto commerciale** con il cliente — chi vede le
 * garanzie sui pezzi di ricambio è materia di contratto e di privacy (ADR-004,
 * superato da ADR-029), non di configurazione locale. Fino a oggi si cambiava
 * dal form dell'anagrafica, dove il Superadmin vede un Ente solo perché è
 * tenant-bound (ADR-018): per tutti gli altri si passava dalla console.
 *
 * ⚠️ **Il nodo si rilegge dal DB dentro l'azione**, e non ci si fida della
 * property: si verifica che sia di **tipo Ente** (`account_id` vive solo lì) e
 * che appartenga a un **cliente vero** — cioè che passi da
 * `VistaPiattaforma::enti()`, che esclude cestinati e piattaforma. Un id di
 * dipartimento o di sede della piattaforma fallisce chiuso.
 *
 * *Scarto dichiarato rispetto al piano*, che chiedeva di verificare anche
 * `account_id === $account->id`: **quel confronto qui non ha un secondo
 * termine**. L'azione riceve una sede, non una coppia sede-account — perché la
 * `select` vive dentro l'espansione di una riga e non dentro un form
 * intestato a un cliente. Legarla all'account espanso significherebbe fidarsi
 * di `$espanso`, che arriva dal browser esattamente come `$sedeId`: due valori
 * non fidati che si confermano a vicenda non fanno una verifica. Ciò che conta
 * — «questa sede è di **un** cliente vero» — è verificato, e per la sola cosa
 * che l'operatore può fare (fissare una clausola contrattuale su una sede che
 * amministra comunque) è la condizione giusta.
 *
 * L'audit lo scrive `fissaVisibilitaGaranzieRicambio()`, che è **l'unica via**
 * per quella colonna (è fuori da `$fillable`). Nessun `activity()` qui: due
 * tracciamenti sullo stesso gesto raccontano la stessa cosa in due modi, e il
 * guardrail trait-vs-esplicita diventerebbe rosso.
 */
trait FissaVisibilitaSede
{
    public function fissaVisibilita(int $sedeId, string $valore): void
    {
        Gate::authorize('roles.manage');

        $visibilita = VisibilitaGaranzieRicambio::tryFrom($valore);

        if ($visibilita === null) {
            // Fail-closed e rumoroso: una `select` non può produrre questo
            // valore, quindi chi lo manda non sta usando la pagina.
            throw ValidationException::withMessages([
                'visibilita' => "«{$valore}» non è uno dei tre stati previsti.",
            ]);
        }

        $sede = VistaPiattaforma::enti()
            ->where('tipo', TipoUnitaOrganizzativa::Ente)
            ->whereIn('account_id', VistaPiattaforma::accounts()->select('accounts.id'))
            ->whereKey($sedeId)
            ->firstOrFail();

        $sede->fissaVisibilitaGaranzieRicambio($visibilita);
    }

    /** I tre stati, per la `select`. La vista non conosce l'enum. */
    public function statiVisibilita(): array
    {
        return VisibilitaGaranzieRicambio::cases();
    }
}
