<?php

namespace App\Models;

use App\Models\Concerns\AuditsDomainWrites;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * L'Account: l'intestatario del rapporto commerciale (ERD §4.3 — ADR-032).
 *
 * Possiede N Enti e paga EasyLab (rapporto A di ADR-002): qui vivranno il
 * customer Stripe/`Billable` (blocco Cashier), i dati fiscali (ADR-010) e il
 * lockout (ADR-013). Non è un Rivenditore — quello è un merchant terzo che
 * incassa in proprio, e resta dall'altra parte del confine di ADR-002.
 *
 * **Primo modello di piattaforma del progetto**: vive sopra i tenant, quindi
 * niente `BelongsToTenant` — l'esenzione è dichiarata nel meta-test
 * (`TenantScopeGuardrailTest::NON_TENANT_MODELS`) ed è il pattern per i futuri
 * modelli di questo livello (`resellers`, che oggi un modello non ce l'ha).
 * Chi lo interroga è per definizione fuori dal proprio scope: viste
 * esplicitamente non-scopate gate da permessi di piattaforma (ADR-018), fino
 * ad allora console e provisioning.
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use AuditsDomainWrites, HasFactory, SoftDeletes;

    /**
     * Solo l'anagrafica commerciale: sono dati di form (onboarding, dashboard
     * Superadmin) ed è giusto che l'audit del trait li tracci.
     *
     * `is_locked`/`locked_at`/`locked_reason` restano FUORI, come `forced_*`
     * su Strumento (ADR-005) e `visibilita_garanzie_ricambio` (ADR-029): il
     * lockout sarà un gesto con un metodo dedicato (blocco ADR-013), non un
     * campo che un form può forgiare. Quando quel gesto nascerà, la traccia
     * passerà da `attributiDerivatiTracciati()` — non da `activity()` a mano,
     * che violerebbe la regola «o il trait o le esplicite, mai entrambi».
     */
    protected $fillable = [
        'ragione_sociale',
        'partita_iva',
        'codice_fiscale',
        'pec',
        'codice_destinatario_sdi',
    ];

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    /** «Creazione account», non «Creazione account…qualcosa»: nome-primo (ADR-027). */
    protected function nomeDominio(): string
    {
        return 'account';
    }

    /**
     * Gli Enti dell'account.
     *
     * `withoutGlobalScopes()` — eccezione nominata, la quarta del progetto dopo
     * `User::ente()`, `Garanzia::deiPezziMontati()` e
     * `Strumento::percorsoUbicazione()`. Il motivo è costitutivo: gli Enti di
     * un account sono per definizione fuori dal tenant corrente — è la ragione
     * per cui esiste lo switcher — e il TenantScope li filtrerebbe via tutti
     * tranne quello attivo. Il confine non si perde: la chiave è `account_id`,
     * non un filtro che qualcuno può dimenticare, e chi arriva qui è già
     * passato dall'appartenenza (`membri`).
     *
     * @return HasMany<UnitaOrganizzativa, $this>
     */
    public function enti(): HasMany
    {
        return $this->hasMany(UnitaOrganizzativa::class, 'account_id')
            ->withoutGlobalScopes();
    }

    /**
     * I membri: chi amministra il rapporto commerciale. È la condizione che la
     * Policy billing (blocco Cashier) verificherà dietro `billing.manage_own`
     * — permesso condizione necessaria, il dato restringe (gerarchia ADR-029).
     *
     * @return BelongsToMany<User, $this>
     */
    public function membri(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_user')->withTimestamps();
    }

    public function aggiungiMembro(User $utente): void
    {
        $this->membri()->syncWithoutDetaching([$utente->id]);
    }

    /**
     * Invariante di ADR-032: **ogni account ha sempre almeno un membro** — un
     * account senza nessuno che possa amministrarlo è un contratto senza
     * controparte. Il `detach()` nudo resta tecnicamente possibile, ma questa
     * è la via maestra e il seeder demo riverifica l'invariante a posteriori.
     */
    public function rimuoviMembro(User $utente): void
    {
        $altri = $this->membri()->whereKeyNot($utente->id)->exists();

        if (! $altri) {
            throw new RuntimeException(
                "L'utente {$utente->id} è l'ultimo membro dell'account {$this->id}: un account non resta senza amministratori (ADR-032)."
            );
        }

        $this->membri()->detach($utente->id);
    }
}
