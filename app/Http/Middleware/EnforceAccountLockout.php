<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Il lockout totale di ADR-013: se l'account dell'Ente CORRENTE è in insoluto,
 * ogni richiesta del gruppo protetto finisce su /bloccato.
 *
 * Guarda **l'account dell'Ente, non l'utente**: `users.is_active` (ADR-012,
 * non ancora esistente) spegnerà una persona, questo sospende un contratto —
 * due interruttori diversi che non vanno fusi (nota di ADR-032).
 *
 * Chi passa, e perché:
 * - **guest** → ci pensa `auth`, che sta prima nel gruppo;
 * - **`tenant_id` null** (tecnico esterno, utenti di piattaforma) → il lockout
 *   ferma il cliente moroso, non l'assistenza che serve le macchine: il
 *   tecnico è staff/partner EasyLab, già confinato e tracciato (ADR-030). Se
 *   un domani si decidesse diversamente, il punto d'innesto è QUESTO;
 * - **impersonazione attiva** → è l'attuazione della salvaguardia GDPR di
 *   ADR-013: il Superadmin deve poter assistere ed ESPORTARE i dati anche in
 *   lockout (i certificati sono del cliente), e ogni suo passo è già loggato
 *   dall'impersonation. Il bypass è della sessione impersonante, non
 *   dell'utente: il membro reale resta fuori;
 * - **ente senza account** → fail-open dichiarato: il lockout è un attributo
 *   del rapporto commerciale, e senza rapporto non c'è nulla da sospendere.
 *
 * Nessuna esclusione `routeIs`: /bloccato e la fuga vivono FUORI dal gruppo
 * (routes/web.php) — la sicurezza è posizionale, perché un'esclusione per nome
 * non varrebbe sugli update Livewire, dove la rotta è sempre /livewire/update.
 * E per gli update Livewire questo middleware è registrato come PERSISTENTE
 * (AppServiceProvider): senza, ogni azione Livewire — cioè quasi tutte le
 * scritture dell'app — aggirerebbe il blocco.
 *
 * Costo: al più due letture per PK (`ente` → `account`), relazioni BelongsTo
 * cacheate sull'istanza che il resto della richiesta riusa. Niente cache in
 * più: sarebbe il posto dove un lockout appena scattato non si vede.
 */
class EnforceAccountLockout
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->tenant_id === null) {
            return $next($request);
        }

        if (app('impersonate')->isImpersonating()) {
            return $next($request);
        }

        if ($user->ente?->account?->is_locked) {
            return redirect()->route('bloccato');
        }

        return $next($request);
    }
}
