<?php

namespace App\Support\Gdpr;

use App\Models\Account;
use App\Models\OccorrenzaErrore;
use App\Models\User;
use App\Support\Piattaforma\EliminaCliente;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il perimetro dei dati di UN Account, per l'export GDPR (accesso/portabilità,
 * artt. 15 e 20 — «Privacy GDPR e Registro Trattamenti» §4, ADR-013).
 *
 * ⛔ **Query builder e filtro esplicito, non Eloquent e non gli scope.** L'export
 * gira in console, dove `CurrentTenant::shouldScope()` è falso: `TenantScope` e
 * `DepartmentScope` si ritirano, e un `Strumento::query()` restituirebbe le
 * righe di **tutti** i clienti. Togliere gli scope a mano (`withoutGlobalScopes`)
 * non aggiungerebbe nulla: il confine qui non lo dà il framework, lo dà il
 * `whereIn('tenant_id', …)` di ogni query, ed è scritto una volta sola in
 * questa classe. Così il perimetro è lo stesso con o senza utente in sessione,
 * e nessun ruolo bypassa niente (ADR-001/006/018): semplicemente non c'è uno
 * scope da cui dipendere.
 *
 * **Gli Enti si leggono cestinati compresi**, a differenza di `Account::enti()`:
 * una sede nel cestino ha ancora le sue righe a database, e il diritto di
 * accesso riguarda i dati **detenuti**, non quelli visibili in pagina. Stesso
 * criterio per le righe cestinate di ogni tabella: escono con `deleted_at`.
 *
 * **Le tabelle di dominio sono quelle di `EliminaCliente::TABELLE`** (ADR-040):
 * ciò che l'eliminazione porta via è ciò che l'export consegna, con un elenco
 * solo. Sopra ci sono l'albero, i pivot, le righe del self-signup e le
 * occorrenze d'errore delle persone. Tutto il resto che porta `tenant_id`,
 * `account_id` o `user_id` è in `TABELLE_ESCLUSE` con la sua ragione:
 * `EsportazioneTenantTest` legge lo schema e pretende l'una o l'altra cosa.
 *
 * **Ordine: sempre `lazyById`/`chunkById`**, cioè `order by id` + `id > ultimo` + `limit`. L'id
 * è unico, quindi niente pari fra un blocco e l'altro su Postgres (la lezione
 * delle 46 righe su 47), e niente tabella intera in memoria.
 *
 * 🔗 ERD §3.1-3.3, §4.1, §4.3, §5-§8 · ADR-001/006/018 (isolamento), ADR-032
 * (Account → N Enti), ADR-013 (export anche in lockout), ADR-040 (perimetro).
 */
final class PerimetroTenant
{
    /**
     * Le colonne di `users` che escono. **Allowlist**, e non per prudenza generica:
     * è l'unica tabella del perimetro che contiene segreti.
     *
     * Fuori `password` (un hash resta un segreto: consegnarlo apre un attacco
     * offline e non serve a nessun diritto dell'interessato), `remember_token`,
     * `two_factor_secret` e `two_factor_recovery_codes` (credenziali vive: chi
     * legge lo zip entrerebbe al posto dell'utente). `two_factor_confirmed_at`
     * esce ridotto a un sì/no (`doppio_fattore_attivo`). Una colonna nuova su
     * `users` resta fuori finché qualcuno non la classifica: il test lo esige.
     *
     * @var list<string>
     */
    public const COLONNE_UTENTI = [
        'id', 'name', 'email', 'email_verified_at', 'tenant_id',
        'riceve_email_scadenze',
        // Le tre preferenze sugli interventi (ADR-047): scelte della persona,
        // come quella qui sopra, e come quella sono sue da leggere.
        'riceve_email_interventi_programmati', 'riceve_email_interventi_eseguiti', 'riceve_email_interventi_assegnati',
        'riceve_email_macchine_segnalate',
        'tema', 'created_at', 'updated_at', 'deleted_at',
    ];

    /** @var list<string> */
    public const COLONNE_UTENTI_ESCLUSE = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
    ];

    /**
     * Le colonne di `accounts` che escono.
     *
     * Fuori `locked_reason`/`stripe_lock_reason` (annotazioni operative interne,
     * che nemmeno `/bloccato` mostra — ADR-013), `stripe_id`/`pm_*`/`trial_ends_at`
     * (specchio locale di Stripe: la fonte è là, ADR-032) e `di_piattaforma`
     * (un flag di EasyLab, non un dato del cliente).
     *
     * `piano_proposto` esce accanto a `piano` (ADR-045): è un dato del rapporto
     * commerciale che il cliente legge già su `/abbonamento`, non
     * un'annotazione interna.
     *
     * `manutenzione_gestita` esce anch'essa (ADR-046): dice al cliente che
     * EasyLab lavora sui suoi dati, ed è proprio ciò che ha diritto di sapere.
     *
     * @var list<string>
     */
    public const COLONNE_ACCOUNT = [
        'id', 'ragione_sociale', 'partita_iva', 'codice_fiscale', 'pec', 'codice_destinatario_sdi',
        'piano', 'piano_proposto', 'manutenzione_gestita', 'is_locked', 'locked_at', 'stripe_locked_at', 'created_at', 'updated_at', 'deleted_at',
    ];

    /** @var list<string> */
    public const COLONNE_ACCOUNT_ESCLUSE = [
        'locked_reason', 'stripe_lock_reason', 'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at', 'di_piattaforma',
    ];

    /**
     * Le righe del self-signup da cui l'Account è nato (ADR-012/036): nome e
     * email del referente sono suoi dati personali. Fuori `password_hash` (un
     * segreto, come su `users`) e `stripe_session_id` (riferimento interno a Stripe).
     *
     * @var list<string>
     */
    public const COLONNE_REGISTRAZIONI = [
        'id', 'nome_ente', 'nome_referente', 'email', 'piano',
        'email_verificata_at', 'completata_at', 'account_id', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    public const COLONNE_REGISTRAZIONI_ESCLUSE = ['password_hash', 'stripe_session_id'];

    /**
     * Le occorrenze dell'error tracker (ADR-017) causate dalle persone
     * dell'Account: esportate, e non dichiarate escluse, perché IP, user agent e
     * orario legati a un `user_id` sono dati personali di quella persona, e il
     * diritto di accesso li copre.
     *
     * Escono **ridotte**: fuori `input` (il corpo della richiesta: può portare
     * dati di terzi o segreti che la redazione non conosce), `messaggio` e
     * `stack_trace` (interni dell'applicazione, con valori di altri record),
     * `percorso` (può contenere token di URL firmate o di reset), e
     * `impersonato_da` (l'id di un operatore EasyLab, ADR-038). Resta il fatto:
     * quando, da dove, con quale client, con quale esito.
     *
     * @var list<string>
     */
    public const COLONNE_OCCORRENZE = [
        'id', 'errore_id', 'metodo', 'codice_http', 'user_id', 'ip', 'user_agent', 'contesto', 'avvenuta_at',
    ];

    /** @var list<string> */
    public const COLONNE_OCCORRENZE_ESCLUSE = ['messaggio', 'stack_trace', 'percorso', 'input', 'impersonato_da'];

    /**
     * Le tabelle che portano `tenant_id`, `account_id` o `user_id` e che
     * **non** escono, con la ragione. Un test legge lo schema e pretende che ogni
     * tabella con una di quelle colonne sia esportata o elencata qui.
     *
     * @var array<string, string>
     */
    public const TABELLE_ESCLUSE = [
        'passkeys' => 'credenziali: chiavi pubbliche di accesso, non dati di dominio',
        'sessions' => 'sessioni vive: il payload equivale a una credenziale',
        'password_reset_tokens' => 'credenziali temporanee',
        'subscriptions' => 'specchio locale di Stripe: la fonte dei dati di pagamento è Stripe',
        'subscription_items' => 'specchio locale di Stripe, come subscriptions',
        'clienti_preferiti' => 'preferenze degli operatori EasyLab sul cliente: dati dello staff, non del cliente',
        'activity_log' => 'registro di audit: fuori perimetro in questa versione (retention T6 aperta)',
        'notifications' => 'notifiche in-app: fuori perimetro in questa versione',
        'errori' => 'impronta aggregata di un errore dell\'applicazione, senza legame con una persona (le occorrenze escono)',
        'model_has_roles' => 'esce come colonna «ruoli» di users.csv',
        'model_has_permissions' => 'permessi diretti: configurazione della piattaforma',
    ];

    /** Righe per blocco di `lazyById`. */
    public const BLOCCO = 500;

    /** @param  list<int>  $enti */
    private function __construct(public readonly Account $account, public readonly array $enti) {}

    public static function di(Account $account): self
    {
        $enti = DB::table('unita_organizzativa')
            ->where('account_id', $account->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return new self($account, $enti);
    }

    /**
     * Le tabelle filtrate per `tenant_id`: l'albero e le tabelle di dominio.
     *
     * @return list<string>
     */
    public static function tabelleDelTenant(): array
    {
        return ['unita_organizzativa', ...EliminaCliente::TABELLE];
    }

    /**
     * Ogni query dell'export, per nome del file CSV. Nessuna è ordinata qui:
     * l'ordine lo mette `lazyById`, che ordina per id e riprende da lì.
     *
     * @return array<string, Builder>
     */
    public function query(): array
    {
        $query = [];

        foreach (self::tabelleDelTenant() as $tabella) {
            $query[$tabella] = DB::table($tabella)->whereIn('tenant_id', $this->enti);
        }

        $query['responsabile_unita'] = DB::table('responsabile_unita')->whereIn(
            'unita_organizzativa_id',
            DB::table('unita_organizzativa')->select('id')->whereIn('tenant_id', $this->enti),
        );
        $query['tecnico_cliente'] = DB::table('tecnico_cliente')->whereIn('ente_id', $this->enti);
        $query['account_user'] = DB::table('account_user')->where('account_id', $this->account->getKey());
        $query['registrazioni'] = DB::table('registrazioni')
            ->select(self::COLONNE_REGISTRAZIONI)
            ->where('account_id', $this->account->getKey());
        // Dal model e `toBase()`, non da `DB::table()`: sulle tabelle del tracker
        // `ScrittureErroriGuardrailTest` tratta ogni `DB::table()` come una
        // scrittura. `toBase()` riporta righe grezze come le altre tabelle.
        $query['occorrenze_errore'] = OccorrenzaErrore::query()->toBase()
            ->select(self::COLONNE_OCCORRENZE)
            ->whereIn('user_id', $this->idUtenti());

        return $query;
    }

    /**
     * Le colonne del CSV di una tabella: l'allowlist dove c'è, altrimenti tutte.
     *
     * @return list<string>
     */
    public static function colonne(string $tabella): array
    {
        return match ($tabella) {
            'registrazioni' => self::COLONNE_REGISTRAZIONI,
            'occorrenze_errore' => self::COLONNE_OCCORRENZE,
            default => Schema::getColumnListing($tabella),
        };
    }

    /** Gli id delle persone dell'Account (vedi `utenti()`), come sottoquery. */
    public function idUtenti(): Builder
    {
        return DB::table('users')->select('id')->where(fn (Builder $q) => $q
            ->whereIn('tenant_id', $this->enti)
            ->orWhereIn('id', DB::table('account_user')->select('user_id')->where('account_id', $this->account->getKey())));
    }

    /**
     * Le persone dell'Account: chi ha il `tenant_id` su uno dei suoi Enti, più i
     * membri del rapporto commerciale (`account_user`), cestinati compresi.
     *
     * I tecnici EasyLab del portafoglio (`tecnico_cliente`) **non** ci sono: sono
     * personale di EasyLab, e mostrarne i dati ai clienti è un punto aperto di
     * ADR-038. Nell'export restano solo come id.
     */
    public function utenti(): Builder
    {
        $membri = DB::table('account_user')->select('user_id')->where('account_id', $this->account->getKey());

        return DB::table('users')
            ->select(self::COLONNE_UTENTI)
            ->addSelect('two_factor_confirmed_at')
            ->selectRaw('case when users.id in ('.$membri->toSql().') then 1 else 0 end as membro_account', $membri->getBindings())
            ->where(fn (Builder $q) => $q
                ->whereIn('tenant_id', $this->enti)
                ->orWhereIn('id', $membri));
    }

    /**
     * I nomi dei ruoli di un blocco di utenti, in una query sola.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    public static function ruoliDi(array $ids): array
    {
        $tabelle = config('permission.table_names');

        return DB::table($tabelle['model_has_roles'].' as mr')
            ->join($tabelle['roles'].' as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_type', (new User)->getMorphClass())
            ->whereIn('mr.model_id', $ids)
            ->orderBy('r.name')
            ->get(['mr.model_id', 'r.name'])
            ->groupBy('model_id')
            ->map(fn ($righe) => $righe->pluck('name')->all())
            ->all();
    }
}
