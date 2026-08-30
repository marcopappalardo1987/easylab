<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TemaUtente;
use App\Enums\TipoUnitaOrganizzativa;
use App\Support\AuditLog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

// `tenant_id` NON è fillable da ADR-032: è il dato su cui poggia l'intero
// scoping (ADR-018), e la sua unica via di riscrittura è `passaAllEnte()` —
// controllata, verificata sull'appartenenza all'account e auditata. Un form
// che potesse forgiarlo sposterebbe una persona in un altro tenant per
// mass-assignment. Provisioning e seeder usano forceFill, come per il nodo
// ente. Stessa postura di `riceve_email_scadenze` qui sotto.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /**
     * @use HasFactory<UserFactory>
     *
     * 🔴 `SoftDeletes` dal 29 Ago 2026 (🔗 ADR-038), ed è una guardia di
     * accesso travestita da colonna: il provider di autenticazione di Laravel
     * costruisce la query dal model, quindi applica i global scope — una
     * persona cestinata **non viene trovata al login**, senza che nessuno abbia
     * scritto un controllo. Lo stesso scope la toglie dalle tendine
     * dell'assegnatario, dai destinatari del digest notturno e dai candidati
     * all'impersonazione.
     *
     * ⛔ Il rovescio, e va conosciuto: lo storico **deve** continuare a
     * nominarla. Le cinque relazioni di attribuzione (`Intervento::tecnico`,
     * `Documento::caricato_da`, `Strumento::forced_by`,
     * `SpostamentoStrumento::eseguito_da`, `Errore::risolto_da`) e le due
     * letture del registro di audit leggono `withTrashed()`: senza,
     * cestinare una persona riscriverebbe il passato in «—».
     */
    use HasFactory, HasRoles, Impersonate, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Ruolo soggetto al secondo filtro (sotto-albero) del Global Scope (ADR-006).
     */
    public const DEPARTMENT_SCOPED_ROLE = 'Responsabile Reparto';

    /**
     * Ruolo con accesso per unione portafoglio ∪ assegnazione (ADR-007/030).
     */
    public const TECNICO_ROLE = 'Tecnico';

    /**
     * Il laboratorio/Ente finale (🔗 Funzionalità per Ruolo §4).
     *
     * Vive qui come costante, e non come stringa ribattuta, per la ragione dei
     * due sopra: è il ruolo che 🔗 ADR-029 protegge, quindi ogni punto che lo
     * nomina deve nominare **lo stesso** — un refuso in una delle due copie
     * spegnerebbe una regola di privacy senza rompere nulla.
     */
    public const TENANT_ROLE = 'Tenant';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'tenant_id' => 'integer',
            // Opt-out dal digest email delle scadenze (ADR-011). Fuori
            // dall'attributo Fillable di proposito: si scrive solo dalle
            // preferenze dell'utente, mai per mass-assignment — la stessa
            // postura di `visibilita_garanzie_ricambio` (ADR-029).
            'riceve_email_scadenze' => 'boolean',
            // La preferenza di tema (ADR-034). Fuori dall'attributo Fillable
            // come le due qui sopra: si scrive solo dalle preferenze
            // dell'utente, con `forceFill`, mai per mass-assignment.
            //
            // ⚠️ **Il cast è la seconda metà del vincolo di schema**, non un
            // ornamento: la colonna porta un CHECK sui tre valori, e qui
            // `TemaUtente::from()` fa fallire con un `ValueError` ogni
            // assegnazione fuori enum *prima* che arrivi al database. Le due
            // guardie coprono strade diverse — questa Eloquent, quella gli
            // import e le migration di correzione.
            'tema' => TemaUtente::class,
        ];
    }

    /**
     * True se l'utente è soggetto al filtro sotto-albero (Responsabile Reparto).
     */
    public function isDepartmentScoped(): bool
    {
        return $this->hasRole(self::DEPARTMENT_SCOPED_ROLE);
    }

    /**
     * True se l'utente accede per unione portafoglio ∪ assegnazione (ADR-030).
     *
     * Vale per **entrambi** i tipi di tecnico: l'interno (`tenant_id`
     * valorizzato) e l'esterno (NULL). La distinzione esiste nei dati e serve
     * come difesa in profondità, ma non è un criterio di accesso — se lo fosse,
     * ci sarebbero due regole da tenere allineate invece di una.
     */
    public function isTecnico(): bool
    {
        return $this->hasRole(self::TECNICO_ROLE);
    }

    /**
     * Portafoglio clienti del Tecnico: gli Enti di cui vede tutte le macchine
     * (ERD §3.3 — ADR-007/030). Il secondo canale, l'assegnazione puntuale, non
     * è una relazione dell'utente ma un campo dell'intervento.
     *
     * ⚠️ Questa relazione passa per i global scope di `UnitaOrganizzativa` e
     * serve alla UI (S6: pagina permessi), **non** allo scope: chi deve
     * calcolare il criterio legge il pivot da `AccessoTecnico`, che gira senza
     * scope per non richiamare sé stesso.
     */
    public function portafoglioClienti(): BelongsToMany
    {
        return $this->belongsToMany(UnitaOrganizzativa::class, 'tecnico_cliente', 'tecnico_id', 'ente_id')
            ->withTimestamps();
    }

    /**
     * Ente di appartenenza (ADR-006/018): la radice a cui punta `tenant_id`.
     *
     * `withoutGlobalScopes()` per la stessa ragione di `AccessibleStrumenti`:
     * questa relazione risponde alla domanda «di chi è quest'utente», e va
     * risposta anche dove gli scope di `UnitaOrganizzativa` non sono ancora
     * applicabili — dentro un global scope, in console, o per un utente che il
     * proprio Ente lo vede solo attraverso di sé. Il confine non si perde: la
     * chiave è `tenant_id` dell'utente, non un filtro.
     *
     * È una relazione e non una query nuda perché Eloquent ne cachea il
     * risultato sull'istanza: `GaranziaRicambioPolicy` la interroga a ogni
     * lettura di garanzie (ADR-029), e senza cache sarebbe una query in più per
     * ciascuna.
     */
    public function ente(): BelongsTo
    {
        return $this->belongsTo(UnitaOrganizzativa::class, 'tenant_id')->withoutGlobalScopes();
    }

    /**
     * Nodi dell'albero organizzativo di cui l'utente è responsabile (ADR-006).
     */
    public function unitaResponsabili(): BelongsToMany
    {
        return $this->belongsToMany(UnitaOrganizzativa::class, 'responsabile_unita')->withTimestamps();
    }

    /**
     * Gli account di cui l'utente è membro (ERD §4.3 — ADR-032): i rapporti
     * commerciali che amministra. Forma di `portafoglioClienti()`. `Account`
     * non è tenant-scoped, quindi qui nessun bypass serve.
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_user')->withTimestamps();
    }

    /**
     * I clienti **preferiti** di questa persona (🔗 ADR-037): il perimetro
     * «i miei preferiti» del Parco clienti.
     *
     * ⛔ **Non è un'autorizzazione e non va usata come tale.** Dice quali clienti
     * l'utente ha messo da parte, non quali può vedere: l'insieme legittimo
     * resta `VistaPiattaforma::accounts()`, con cui `ParcoClienti::clienti()`
     * interseca. Un preferito segnato quando l'account era vivo sopravvive al
     * suo cestinamento — la riga cade nell'intersezione, non nel `whereIn`.
     *
     * Si legge sempre da `App\Support\Piattaforma\Preferiti`, che è la porta
     * gata: questa relazione è la sua forma Eloquent, non il suo sostituto.
     */
    public function clientiPreferiti(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'clienti_preferiti')->withTimestamps();
    }

    /**
     * Le sedi (nodi ente) ancora raggiungibili dall'utente: quelle degli
     * account di cui è membro, esclusi gli account in lockout (ADR-013) e gli
     * enti cestinati. Servita dall'unique (user_id, account_id) del pivot.
     *
     * Vive qui e non nei chiamanti perché lo switcher in top bar e la pagina
     * `/bloccato` rispondono alla STESSA domanda — «dove posso ancora
     * andare?» — e due copie della query sarebbero due risposte libere di
     * divergere al primo bugfix. `withoutGlobalScopes` per la ragione di
     * `ente()` qui sopra: le sedi di un account sono per definizione fuori dal
     * tenant corrente, e il confine lo dà il pivot, non un filtro.
     *
     * @return Builder<UnitaOrganizzativa>
     */
    public function sediRaggiungibili(): Builder
    {
        return UnitaOrganizzativa::withoutGlobalScopes()
            ->where('tipo', TipoUnitaOrganizzativa::Ente->value)
            ->whereNull('deleted_at')
            ->whereIn('account_id', $this->accounts()->where('is_locked', false)->select('accounts.id'));
    }

    /**
     * Lo switcher (ADR-032 punto 4): l'unica via di riscrittura di
     * `users.tenant_id`, che è fuori dal Fillable proprio perché tutto passi
     * di qui.
     *
     * Non è il «selettore di tenant» che ADR-018 ha scartato: quello apriva un
     * secondo percorso verso dati ALTRUI, questo naviga fra Enti dello stesso
     * account — il confine che lì andava protetto qui non esiste. E non è un
     * permesso RBAC: il confine reale non è «può switchare» ma «verso quale
     * Ente», cioè un dato (l'appartenenza in `account_user`) — un permesso
     * direbbe la metà sbagliata della frase (gerarchia di ADR-029: la
     * condizione vive dove vive il dato).
     *
     * Guardie fail-closed a `return false`, senza eccezioni: la UI non offre
     * mai bersagli illegittimi, e chi prova a mano non merita un messaggio
     * diagnostico. ADR-018 resta intatto: una richiesta = un tenant — lo
     * switch avviene FRA le richieste, mai durante.
     */
    public function passaAllEnte(UnitaOrganizzativa $ente): bool
    {
        // Difesa in profondità (forma del tenant_id del tecnico interno,
        // ADR-030): un non-ente non può avere account_id — invariante in
        // UnitaOrganizzativa::booted() — quindi il check di appartenenza qui
        // sotto basterebbe. Ma se quell'invariante si allentasse, o il dato
        // arrivasse corrotto da fuori Eloquent, un tenant_id puntato su un
        // dipartimento romperebbe lo scoping in modi silenziosi. Un test la
        // esercita corrompendo il dato apposta.
        if ($ente->tipo !== TipoUnitaOrganizzativa::Ente) {
            return false;
        }

        // L'appartenenza: un account di cui sono membro possiede quell'Ente.
        if ($ente->account_id === null || ! $this->accounts()->whereKey($ente->account_id)->exists()) {
            return false;
        }

        // Un account in lockout (ADR-013) non fa entrare in nessuno dei suoi
        // Enti: lo switcher è un ingresso nuovo e nasce già chiuso. Il
        // middleware sulla navigazione ordinaria arriva col suo blocco.
        if ($this->accounts()->whereKey($ente->account_id)->where('is_locked', true)->exists()) {
            return false;
        }

        // Durante l'impersonazione il tenant seguito è quello dell'impersonato
        // (CurrentTenant segue Auth::user()): riscriverglielo sarebbe una
        // modifica permanente fatta «per suo conto».
        if (app('impersonate')->isImpersonating()) {
            return false;
        }

        $precedente = $this->tenant_id;
        $this->forceFill(['tenant_id' => $ente->id])->save();

        // Audit a mano (User è esente dal trait — ADR-027: o l'uno o le altre):
        // è l'attraversamento di un confine, come l'impersonazione e l'accesso
        // tecnico, e va potuto ricostruire chi, da dove, verso dove.
        activity(AuditLog::NAME)
            ->causedBy($this)
            ->performedOn($this)
            ->withProperties([
                'da_tenant_id' => $precedente,
                'a_tenant_id' => $ente->id,
                'account_id' => $ente->account_id,
            ])
            ->log('Ente attivo cambiato');

        return true;
    }

    /**
     * Solo chi ha il permesso utenti.impersonate (Developer/Superadmin).
     */
    public function canImpersonate(): bool
    {
        return $this->can('utenti.impersonate');
    }

    /**
     * Il Developer (proprietario tecnico) è l'unico account mai impersonabile.
     * Il Superadmin (proprietario di piattaforma) può essere impersonato dal
     * Developer; a sua volta può impersonare chiunque tranne il Developer.
     */
    public function canBeImpersonated(): bool
    {
        return ! $this->hasRole('Developer');
    }
}
