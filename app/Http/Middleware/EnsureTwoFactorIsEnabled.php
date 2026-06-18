<?php

namespace App\Http\Middleware;

use App\Support\Rbac;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forza il 2FA per i ruoli privilegiati (Developer/Superadmin/Admin):
 * se l'utente non ha confermato il 2FA viene rediretto a /settings/security.
 * Si auto-esclude dalla pagina di sicurezza e dal logout per evitare loop e
 * permettere l'attivazione.
 */
class EnsureTwoFactorIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user
            && is_null($user->two_factor_confirmed_at)
            && $user->hasAnyRole(Rbac::twoFactorRequiredRoles())
            && ! $request->routeIs('settings.security', 'logout')
        ) {
            return redirect()->route('settings.security')
                ->with('status', 'Il tuo ruolo richiede la verifica in due passaggi: attivala per continuare.');
        }

        return $next($request);
    }
}
