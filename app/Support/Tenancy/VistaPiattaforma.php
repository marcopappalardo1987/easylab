<?php

namespace App\Support\Tenancy;

use App\Models\Account;
use App\Models\Scopes\DepartmentScope;
use App\Models\Scopes\TenantScope;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * 🔴 La porta unica delle viste di piattaforma (ADR-018).
 *
 * ADR-018 dice che **nessun ruolo bypassa i global scope**: il Superadmin è
 * tenant-bound come chiunque altro, e l'unico modo di attraversare i tenant è
 * l'impersonazione. Ma la stessa decisione prevede l'eccezione, e la definisce
 * per forma: viste **esplicitamente non-scopate**, gate da permessi di
 * piattaforma. Questa classe è quella forma, resa un posto solo.
 *
 * **Perché una sola e non un bypass per componente.** La Policy di Code Review
 * tiene un elenco **nominativo** delle query non-scopate, e quell'elenco si è
 * già corretto una volta perché contava male. Una classe = una voce; un bypass
 * per schermata = un elenco che ricomincia a mentire al terzo giorno di S6. In
 * più il permesso si chiede in un posto solo: con N builder sparsi, la guardia è
 * N volte da ricordare, e qui è **impossibile ottenere il builder senza
 * attraversare `porta()`**.
 *
 * ⚠️ **Gli scope si tolgono per NOME.** `withoutGlobalScopes()` nudo porta via
 * anche `SoftDeletingScope`, ed è un errore che questo progetto ha già pagato:
 * in `Account::enti()` faceva contare le sedi cestinate, che così occupavano
 * uno slot del piano per sempre. Qui costerebbe KPI gonfiati da righe che
 * nessuno ha più. `TenantScope` e `DepartmentScope` sono esattamente i due che
 * `UnitaOrganizzativa` e `Strumento` registrano (via `BelongsToTenant` e
 * `BelongsToOrgNode`): se un terzo si aggiungesse, si corregge **qui**.
 *
 * ⚠️ **`porta()` non basta a proteggere una vista.** Copre le tre letture, non
 * le azioni che accettano un id dal browser: `Account` non ha global scope e
 * `membri()` è un `belongsToMany` non scopato, quindi un'azione che risolvesse
 * un account per id lo raggiungerebbe senza passare di qui. Ogni metodo pubblico
 * di un componente di piattaforma che accetti un id deve aprire con `porta()` —
 * «l'azione è sicura perché il gate di rotta ha tenuto» è il ragionamento che ha
 * già prodotto un difetto in `_panoramica.blade.php`.
 *
 * Niente `utenti()`: `User` non ha global scope, e i candidati all'impersonazione
 * si raggiungono da `$account->membri()`. Un metodo in più sarebbe un bypass
 * finto, che legittimerebbe l'idea che serva sempre.
 *
 * ⚠️ **Solo contesti HTTP autenticati.** `Gate::authorize()` nega sempre senza
 * utente, quindi in console, nei job e nello scheduler questa porta **lancia**.
 * Non è una svista ed è la simmetria giusta: là il confine non c'è già —
 * `TenantScope` non si applica quando manca un utente — quindi una query nuda
 * è la forma corretta e questa classe non serve. Il precedente vivo è
 * `NotificaScadenze`, che legge gli Enti di tutti i tenant e **deve restare
 * com'è**: farla passare di qui manderebbe lo scheduler notturno in
 * `AuthorizationException`, e il sintomo sarebbe un digest che non arriva.
 *
 * ⚠️ **I builder che consegna sono scrivibili.** `Builder::update()` e
 * `->delete()` passano dal query builder e non emettono eventi di modello: gli
 * hook di `BelongsToTenant` che riforzano `tenant_id` non girano, quindi un
 * `strumenti()->update([...])` riscriverebbe **ogni riga di ogni cliente**
 * dietro un permesso che si chiama `.view_all`. Questa classe è per leggere: un
 * meta-test vieta di concatenarle un metodo di scrittura, ed è lì che va
 * aggiunta l'eccezione se un giorno servisse davvero.
 */
final class VistaPiattaforma
{
    public const PERMESSO = 'tenants.view_all';

    /**
     * Tutti gli account della piattaforma.
     *
     * Non toglie alcuno scope, e non è una svista: `Account` è modello di
     * piattaforma ed è esente da `BelongsToTenant` (ADR-032, dichiarato in
     * `TenantScopeGuardrailTest::NON_TENANT_MODELS`). Qui la classe non è un
     * bypass ma **la porta**: ciò che aggiunge è il permesso. Tenerla dentro è
     * ciò che impedisce alla prossima persona di scrivere `Account::query()` a
     * mano credendo di risparmiare un passaggio — e di saltare così il gate.
     *
     * Il `SoftDeletingScope` resta applicato: i cestinati sono fuori senza che
     * nessuno debba ricordarsene.
     *
     * ⚠️ **Esclude l'account di piattaforma** (`di_piattaforma`), cioè EasyLab
     * stessa. Il filtro sta QUI e non nei chiamanti per la stessa ragione per
     * cui esiste questa classe: delegarlo a ogni KPI significherebbe ricordarlo
     * N volte, e la colonna è nata (con la sua migration e il suo backfill)
     * proprio perché «Clienti: 13» quando sono 12 è un numero che qualcuno
     * riporterebbe a un socio. Chi ha davvero bisogno di vedere anche la
     * piattaforma usa `accountsInclusaPiattaforma()`, che lo dice nel nome.
     *
     * @return Builder<Account>
     */
    public static function accounts(): Builder
    {
        return self::accountsInclusaPiattaforma()->where('di_piattaforma', false);
    }

    /**
     * Tutti gli account, **EasyLab compresa**.
     *
     * Serve dove si amministra la piattaforma in quanto tale e non i suoi
     * clienti. Il nome è lungo di proposito: chi lo scrive deve sapere che sta
     * includendo una riga che non è un cliente.
     *
     * @return Builder<Account>
     */
    public static function accountsInclusaPiattaforma(): Builder
    {
        self::porta();

        return Account::query();
    }

    /**
     * Tutti i nodi dell'alberatura, di ogni Ente.
     *
     * @return Builder<UnitaOrganizzativa>
     */
    public static function enti(): Builder
    {
        self::porta();

        return UnitaOrganizzativa::query()
            ->withoutGlobalScopes([TenantScope::class, DepartmentScope::class]);
    }

    /**
     * Tutti gli strumenti della piattaforma.
     *
     * @return Builder<Strumento>
     */
    public static function strumenti(): Builder
    {
        self::porta();

        return Strumento::query()
            ->withoutGlobalScopes([TenantScope::class, DepartmentScope::class]);
    }

    /**
     * Il permesso di piattaforma, chiesto **prima** di consegnare il builder.
     *
     * `Gate::authorize()` e non `Gate::allows()`: chi non ha il permesso deve
     * ricevere un 403, non un builder vuoto che si legge come «non ci sono
     * clienti». Un permesso nudo è legittimo qui — non esiste una Policy su
     * `UnitaOrganizzativa` né su `Strumento`, quindi la trappola del
     * `Gate::before` di spatie non si applica. Dove la Policy esiste (`Account`)
     * le **azioni** passano da `authorize('manage'|'lockout', $account)`.
     */
    private static function porta(): void
    {
        Gate::authorize(self::PERMESSO);
    }
}
