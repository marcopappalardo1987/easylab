<?php

namespace App\Http\Middleware;

use App\Support\Rbac;
use Closure;
use Illuminate\Http\Request;
use Lab404\Impersonate\Services\ImpersonateManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forza il 2FA per i ruoli privilegiati (Developer/Superadmin/Admin):
 * se l'utente non ha confermato il 2FA viene rediretto a /settings/security.
 * Si auto-esclude dalla pagina di sicurezza e dal logout per evitare loop e
 * permettere l'attivazione.
 *
 * 🔴 **E si auto-esclude durante un'impersonazione (ADR-018), per una ragione
 * di sicurezza prima ancora che di comodità.**
 *
 * Segnalato da Marco il 28 Ago 2026: impersonando un Admin che il 2FA non l'ha
 * ancora attivato, il Developer finiva sulla pagina di sicurezza *di quel
 * cliente* e non poteva andare da nessun'altra parte — l'impersonazione, che
 * esiste per guardare l'applicazione con gli occhi del cliente, diventava un
 * vicolo cieco.
 *
 * ⛔ Ma il guasto peggiore non era il blocco: era **il pulsante**. Su quella
 * pagina il Developer poteva premere «Abilita 2FA» e **generare il secondo
 * fattore sull'account di un cliente**, legandolo alla propria app di
 * autenticazione. Da lì in avanti il cliente non sarebbe più entrato senza
 * chiedere un codice a noi, e il registro di audit avrebbe detto che il 2FA
 * l'ha attivato lui. Un'impersonazione deve poter **guardare**, non acquisire
 * le credenziali di chi si impersona.
 *
 * Il 2FA che conta è già stato chiesto: quello dell'impersonatore, che è un
 * ruolo privilegiato ed è passato da qui prima di cominciare. Chiedere anche
 * quello dell'impersonato significa chiedere due volte lo stesso fattore a due
 * persone diverse, di cui una non è davanti allo schermo.
 */
class EnsureTwoFactorIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // ⚠️ Si legge dal SERVIZIO e non da `$user->isImpersonated()`: il
        // secondo passa comunque dallo stesso manager, ma qui l'utente può
        // essere nullo e la domanda è sulla richiesta, non sulla persona.
        if (app(ImpersonateManager::class)->isImpersonating()) {
            return $next($request);
        }

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
