<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
