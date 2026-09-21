<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Events\Dispatcher;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;

/**
 * Memo, per la durata di UNA richiesta o di UN job, dei nodi che
 * `AccessibleNodes::forCurrentUser()` risolve (livello 2 del Global Scope,
 * ADR-006; tenancy senza bypass, ADR-018; N Enti per account, ADR-032;
 * ERD: `responsabile_unita`, `unita_organizzativa`).
 *
 * ## Perché esiste
 *
 * `DepartmentScope` e i due scope che passano per lo strumento chiamano il
 * risolutore a OGNI query scopata, e il risolutore rilegge il pivot più
 * l'intero albero dell'Ente: sulla dashboard del Responsabile erano 28 statement
 * contro i 6 dell'Admin (misura congelata in `MetricheParcoTest`, S7).
 *
 * ## Perché la vita è quella del container e non una proprietà statica
 *
 * ⛔ È un risolutore di **autorizzazioni**. Una memo statica sotto un processo
 * persistente (queue worker, Octane) sopravvive alla richiesta che l'ha scritta
 * e servirebbe a un utente i nodi calcolati per un altro, o quelli di prima di
 * una revoca. `#[Scoped]` lega l'istanza al ciclo che Laravel già azzera da sé:
 * `forgetScopedInstances()` a ogni job (QueueServiceProvider) e a ogni richiesta
 * sotto Octane; sotto FPM e nei test il container stesso nasce e muore con la
 * richiesta. Nessun reset da ricordare a mano.
 *
 * ## Perché la chiave è (utente, tenant_id, CurrentTenant)
 *
 * Dentro la stessa richiesta l'identità può cambiare (login, impersonazione,
 * `Auth::setUser()` in un job che lavora per più utenti) e così il tenant
 * (`SwitcherEnte::passa()` riscrive `users.tenant_id`, o durante
 * un'impersonazione mette la sede effimera in sessione). Con i tre valori letti
 * LIVE nella chiave, un cambio di contesto non può mai pescare la riga di un
 * altro: l'invalidamento qui sotto è la seconda cintura, non la prima.
 *
 * Il ruolo NON è in memo: `AccessibleNodes` verifica `isDepartmentScoped()` a
 * ogni chiamata (spatie tiene i ruoli sul modello, zero query), così una
 * promozione a Responsabile a metà richiesta restringe subito invece di
 * servire il `null` «vede tutto» memorizzato prima.
 *
 * ## Invalidamento
 *
 * Si svuota TUTTO (è piccola e vive una richiesta) su: login, logout, inizio e
 * fine impersonazione, e **qualsiasi scrittura SQL** su `responsabile_unita` o
 * `unita_organizzativa`. Si ascolta la query e non gli eventi Eloquent perché
 * le assegnazioni passano da `attach()`/`detach()` (nessun evento senza un
 * pivot custom), da `DB::table()` (`EliminaCliente`) e gli spostamenti di nodi
 * possono essere update di massa: un evento di modello ne mancherebbe qualcuno,
 * la query no. Svuotare troppo costa una rilettura; svuotare troppo poco apre
 * un nodo revocato.
 *
 * 🔴 **E su ogni rollback** (`TransactionRolledBack`, savepoint compresi — T4A-1,
 * T4B-1). La scrittura svuota la memo, ma una lettura fatta DOPO la scrittura e
 * PRIMA dell'annullamento memorizza nodi di uno stato che il DB non avrà mai:
 * un'assegnazione annullata resterebbe concessa fino a fine richiesta. Il
 * rollback non emette nessuna query su queste tabelle, quindi va ascoltato a
 * parte. Si svuota su QUALSIASI rollback, senza chiedersi cosa annullasse.
 *
 * ## Limiti dichiarati e accettati
 *
 * - **Scritture di un altro processo** (un'altra richiesta, un job, la console)
 *   si vedono solo alla richiesta o al job successivo: la memo non le sente, e
 *   vive quanto la richiesta. È la stessa finestra che avrebbe un controllo
 *   letto all'inizio della richiesta.
 * - **`unita_organizzativa` prende anche `unita_organizzativa_id`** di altre
 *   tabelle (ogni insert di uno strumento, per esempio): invalidamento in
 *   eccesso, voluto — costa una rilettura, non un nodo sbagliato.
 */
#[Scoped]
final class AccessibleNodesMemo
{
    /**
     * Messo nel container da `subscribe()`. Senza, la memo non memorizza:
     * una memo senza il proprio invalidamento servirebbe un nodo revocato,
     * quindi se il provider perde la riga si torna lenti, non aperti.
     */
    private const INVALIDAMENTO_ATTIVO = self::class.'.invalidamento';

    /** @var array<string, list<int>> */
    private array $nodi = [];

    /**
     * @param  callable(): list<int>  $risolvi
     * @return list<int>
     */
    public function ricorda(User $user, ?int $tenantCorrente, callable $risolvi): array
    {
        if (! app()->bound(self::INVALIDAMENTO_ATTIVO)) {
            return $risolvi();
        }

        $chiave = $user->getKey().'|'.($user->tenant_id ?? '-').'|'.($tenantCorrente ?? '-');

        return $this->nodi[$chiave] ??= $risolvi();
    }

    public function dimentica(): void
    {
        $this->nodi = [];
    }

    /**
     * Registrato con `Event::subscribe()` nel provider. I listener risolvono
     * l'istanza a ogni evento, quindi colpiscono sempre quella del job o della
     * richiesta in corso, mai una catturata al boot.
     */
    public function subscribe(Dispatcher $events): void
    {
        app()->instance(self::INVALIDAMENTO_ATTIVO, true);

        $svuota = static fn () => app(self::class)->dimentica();

        $events->listen([
            Login::class, Logout::class, TakeImpersonation::class, LeaveImpersonation::class,
            TransactionRolledBack::class,
        ], $svuota);

        $events->listen(QueryExecuted::class, static function (QueryExecuted $query) use ($svuota): void {
            if (self::toccaLAlbero($query->sql)) {
                $svuota();
            }
        });
    }

    /**
     * Una scrittura che può cambiare l'insieme dei nodi di qualcuno.
     *
     * Gira su OGNI query dell'app, quindi l'ordine conta: prima il nome della
     * tabella (`stripos`, la quasi totalità delle query esce qui), poi la
     * parola chiave. Il verbo si cerca OVUNQUE e non in testa, senza badare a
     * maiuscole (T4A-2): un commento iniziale, una CTE (`with … delete`) o un
     * `REPLACE`/`MERGE` sfuggivano all'ancora `^`. `\b` tiene fuori
     * `deleted_at`/`updated_at`; un `select … for update` passa, ed è eccesso.
     */
    public static function toccaLAlbero(string $sql): bool
    {
        if (stripos($sql, 'unita_organizzativa') === false && stripos($sql, 'responsabile_unita') === false) {
            return false;
        }

        return preg_match('/\b(insert|update|delete|truncate|replace|merge)\b/i', $sql) === 1;
    }
}
