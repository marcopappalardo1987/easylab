<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Risolutore del tenant corrente per il Global Scope multi-tenant (ADR-001/018).
 *
 * Unico punto in cui si legge il contesto: gli scope/trait dipendono da qui,
 * così il livello 2 (sotto-albero Responsabile, unione Tecnico — S2 punto 2)
 * potrà estendere la logica senza toccare i modelli.
 *
 * ADR-018: NESSUN ruolo bypassa lo scope. Ogni utente autenticato è scopato al
 * proprio tenant; l'accesso cross-tenant avviene solo via impersonazione.
 *
 * Impersonation: lab404 sostituisce l'utente autenticato, quindi
 * `Auth::user()` (e il suo `tenant_id`) seguono automaticamente il contesto
 * impersonato — nessun trattamento speciale necessario.
 */
class CurrentTenant
{
    /**
     * Id del tenant (Ente) dell'utente corrente. Può essere null per un utente
     * autenticato senza tenant (gestito fail-closed da TenantScope) oppure in
     * contesto senza utente (console/seeder/job → nessuno scope).
     */
    public static function id(): ?int
    {
        return self::user()?->tenant_id;
    }

    /**
     * True quando esiste un utente autenticato: in tal caso lo scope si applica
     * (fail-closed se senza tenant). False solo per i contesti SENZA utente
     * (console/seeder/job/guest), dove il Global Scope non filtra.
     */
    public static function shouldScope(): bool
    {
        return self::user() !== null;
    }

    protected static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
