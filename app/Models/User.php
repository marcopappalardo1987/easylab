<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'tenant_id'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Impersonate, Notifiable, TwoFactorAuthenticatable;

    /**
     * Ruolo soggetto al secondo filtro (sotto-albero) del Global Scope (ADR-006).
     */
    public const DEPARTMENT_SCOPED_ROLE = 'Responsabile Reparto';

    /**
     * Ruolo con accesso per unione portafoglio ∪ assegnazione (ADR-007/030).
     */
    public const TECNICO_ROLE = 'Tecnico';

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
