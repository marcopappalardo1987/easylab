<?php

namespace App\Policies;

use App\Enums\VisibilitaGaranzieRicambio;
use App\Models\User;

/**
 * Chi può vedere e gestire le garanzie dei **pezzi montati** (ADR-029).
 *
 * **Prima Policy del progetto**, quindi vale spiegare perché nasce adesso e non
 * prima. Finora il "cosa" è sempre stato un permesso nudo (`authorize('...')`)
 * e il "su quali righe" un Global Scope: due piani, due strumenti, nessun terzo.
 * ADR-029 introduce una domanda che nessuno dei due sa esprimere — «il permesso
 * c'è, ma quanto vale in QUESTO Ente?» — e la risposta non può stare nel
 * permesso:
 *
 *   `spatie/laravel-permission` registra un `Gate::before` che CONCEDE non
 *   appena il permesso esiste sul ruolo (PermissionRegistrar::registerPermissions).
 *   Un `Gate::define('garanzie.ricambio.manage', …)` non verrebbe quindi mai
 *   raggiunto, e un secondo `before` registrato dopo — cioè qualunque nostro
 *   provider, che gira dopo i package provider — nemmeno.
 *
 * Le ability portano perciò nomi **diversi dai permessi**: è ciò che le rende
 * raggiungibili, non una preferenza di stile. Chi in futuro scrivesse di nuovo
 * `authorize('garanzie.ricambio.manage')` scavalcherebbe l'impostazione
 * dell'Ente — un test lo congela.
 *
 * **Il permesso resta la condizione necessaria.** Con `teams = false` in
 * `config/permission.php` (Schema Ruoli §7) i permessi di un ruolo sono
 * globali: RBAC dice il *default* della piattaforma, questa Policy applica
 * l'*eccezione* del singolo Ente. L'impostazione **restringe e non allarga
 * mai**: un Ente in `modifica` non concede nulla a chi il permesso non ce l'ha.
 *
 * Nessun model come secondo argomento: l'impostazione è dell'Ente e l'utente
 * appartiene a un solo Ente (ADR-018), quindi la riga non aggiunge
 * informazione. Le ability si invocano su `Garanzia::class`.
 */
class GaranziaRicambioPolicy
{
    /** Vede le righe `soggetto = ricambio`. */
    public function view(User $user): bool
    {
        return $user->can('garanzie.ricambio.view')
            && $this->visibilita($user) !== VisibilitaGaranzieRicambio::Nascosta;
    }

    /** Le crea, modifica o cestina. */
    public function manage(User $user): bool
    {
        return $user->can('garanzie.ricambio.manage')
            && $this->visibilita($user) === VisibilitaGaranzieRicambio::Modifica;
    }

    /**
     * L'impostazione che vincola questo utente, o `Modifica` se non è vincolato.
     *
     * ⚠️ **È l'unico punto del progetto in cui un ruolo è nominato nel codice**,
     * e ci sta di proposito: il vincolo è contrattuale verso il cliente finale
     * (ADR-029), non una regola di permessi — chi lavora per EasyLab (Admin,
     * Tecnico, Responsabile) non ne è toccato, e togliergli la scrittura perché
     * un Ente è in sola lettura sarebbe l'opposto di ciò che l'ADR decide.
     * Isolarlo qui significa che il giorno in cui i ruoli vincolati diventano
     * due si tocca un metodo e non sei call site.
     *
     * Il fallback a `Modifica` è la stessa scelta del default in colonna: per
     * chi non è vincolato l'impostazione non deve poter dire di no. Un utente
     * senza Ente (Tecnico esterno, ADR-007) non è un Tenant e non arriva qui.
     */
    private function visibilita(User $user): VisibilitaGaranzieRicambio
    {
        if (! $user->hasRole('Tenant')) {
            return VisibilitaGaranzieRicambio::Modifica;
        }

        // `ente` e non `ente()`: la proprietà risolve (e cachea) la relazione,
        // il metodo restituirebbe il BelongsTo — su cui l'attributo è sempre
        // null, e il fallback qui sotto trasformerebbe ogni Ente in `modifica`.
        return $user->ente?->visibilita_garanzie_ricambio
            ?? VisibilitaGaranzieRicambio::Modifica;
    }
}
