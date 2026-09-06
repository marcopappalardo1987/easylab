<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/**
 * Chi può amministrare il rapporto commerciale di un Account (ADR-032).
 *
 * **Seconda Policy del progetto**, e nasce dalla stessa domanda che ha
 * prodotto la prima (`GaranziaRicambioPolicy`): il permesso dice *cosa*, non
 * *su quali righe*. `billing.manage_own` risponde «questo utente amministra il
 * proprio abbonamento»; da solo non risponde a «**quale** abbonamento», e con
 * `teams = false` in `config/permission.php` i permessi sono globali — quindi
 * un Admin col permesso lo avrebbe su **ogni** account della piattaforma.
 * ADR-032 lo dice in una riga: «il permesso è la condizione necessaria, la
 * Policy restringe, e nessuno dei due allarga l'altro».
 *
 * ⚠️ **Le ability hanno nomi diversi dai permessi, ed è ciò che le rende
 * raggiungibili.** `spatie/laravel-permission` registra un `Gate::before` che
 * CONCEDE non appena il permesso esiste sul ruolo: un'ability chiamata
 * `billing.manage_own` non verrebbe mai interrogata, e l'Admin passerebbe su
 * account di cui non è membro. È la stessa trappola documentata in
 * `GaranziaRicambioPolicy`, e lì è già costata un errore vero.
 *
 * **Nessun call site oggi**, e la Policy esiste lo stesso. Nel blocco 2
 * (self-signup, Billing Portal) e in S6 (dashboard Superadmin) la strada di
 * minor resistenza sarà `authorize('billing.manage_own')` o
 * `->middleware('can:billing.manage_own')` — il permesso nudo, che concede a
 * chiunque su qualunque account. Non è un'ipotesi: è già successo con
 * `garanzie.ricambio.manage` in `_panoramica.blade.php`. Scriverla adesso,
 * insieme al test che vieta la stringa nuda fuori da questo file, rende la
 * strada sbagliata rossa **prima** che qualcuno la percorra.
 */
class AccountPolicy
{
    /**
     * Amministra l'abbonamento di questo account: metodo di pagamento, piano,
     * fatture (blocco 2), dati fiscali.
     *
     * Due strade, e l'ordine conta. Chi ha `billing.manage_global` (Developer e
     * Superadmin, permesso 🔒) amministra **gli account altrui**: è tutto il
     * senso di quel permesso, e senza questo ramo il Superadmin avrebbe `manage`
     * falso ovunque tranne che sul proprio account — con l'effetto prevedibile
     * che in S6, davanti alla dashboard che non funziona, la via rapida
     * sarebbe rilassare l'appartenenza qui sotto, cioè rompere ADR-032 dal lato
     * sbagliato.
     *
     * Chi ha `billing.manage_own` (Admin) amministra **il proprio**, e «proprio»
     * significa esattamente una cosa: essere membro dell'account (pivot
     * `account_user`). Non «avere un Ente di quell'account»: gli utenti di una
     * sede non amministrano il contratto, e infatti Responsabile, Tenant e
     * Tecnico il permesso non ce l'hanno affatto.
     */
    public function manage(User $user, Account $account): bool
    {
        if ($user->can('billing.manage_global')) {
            return true;
        }

        return $user->can('billing.manage_own')
            && $account->membri()->whereKey($user->id)->exists();
    }

    /**
     * Blocca o sblocca l'account per insoluto (ADR-013).
     *
     * **Nessuna condizione di appartenenza, di proposito**: `billing.lockout` è
     * un'abilità di piattaforma (set 🔒, solo Developer e Superadmin) e si
     * esercita per definizione su account **altrui** — chiudere il proprio non
     * è un caso d'uso. Aggiungere qui l'appartenenza renderebbe il permesso
     * inutilizzabile proprio da chi lo possiede.
     *
     * Oggi il gesto passa da `easylab:lockout`, che in console non interroga
     * alcun Gate; questa ability è la superficie che la UI di S6 userà.
     */
    public function lockout(User $user, Account $account): bool
    {
        return $user->can('billing.lockout');
    }

    /**
     * 🔴 L'eliminazione **definitiva** di un cliente (🔗 ADR-040): dati, file,
     * abbonamento, e le persone rimaste senza contratto.
     *
     * ## Perché `billing.lockout` e non un permesso nuovo
     *
     * Perché è già **esattamente** questo: il permesso «gesto distruttivo di
     * piattaforma su account altrui», nel set 🔒 (quindi non concedibile
     * dall'editor dei ruoli), in mano ai soli Developer e Superadmin, e con
     * un'ability che per scelta non chiede l'appartenenza — chiudere il proprio
     * contratto non è un caso d'uso, e qui ancora meno.
     *
     * ⛔ E perché aggiungere una voce a `config/rbac.php` obbligherebbe a
     * **riseminare**, e da S6 quel comando `syncPermissions()` cancella ogni
     * personalizzazione fatta da `/piattaforma/ruoli` — in entrambe le
     * direzioni. Un permesso nuovo per un gesto già coperto costerebbe la
     * matrice di runtime di tutti i clienti.
     *
     * ⚠️ **Un'ability a sé e non `lockout` riusata**, benché la condizione sia
     * la stessa: il giorno in cui l'eliminazione dovesse restringersi al solo
     * Developer, o chiedere una condizione in più, il posto dove scriverlo deve
     * già esistere. Un `@can('lockout')` sul pulsante «Elimina» direbbe la cosa
     * giusta oggi e la cosa sbagliata al primo cambiamento.
     */
    public function elimina(User $user, Account $account): bool
    {
        return $user->can('billing.lockout');
    }
}
