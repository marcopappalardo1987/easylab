<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gli header di sicurezza di ogni risposta (security pass S7, T1b — 🔗 ADR-003,
 * ADR-018; nessuna entità dell'ERD).
 *
 * ## ⚠️ La CSP è in **report-only**, e deve restarci finché non si misura
 *
 * Livewire e Alpine valutano espressioni scritte negli attributi (`wire:*`,
 * `x-*`), cioè codice costruito a runtime: una CSP applicata senza
 * `'unsafe-eval'` spegnerebbe **ogni** componente, e il guasto sarebbe una
 * pagina che sembra viva ma non risponde ai clic. In report-only il browser
 * annota le violazioni in console e non blocca nulla: è il passo che serve a
 * sapere quanto manca a una CSP vera. `'unsafe-inline'`/`'unsafe-eval'` ci sono
 * apposta, perché il rumore di violazioni già note nasconderebbe quelle nuove:
 * ciò che questa politica già prova è che **nessuno script arriva da fuori**.
 *
 * `form-action` ammette Stripe perché checkout e portale di fatturazione sono un
 * redirect dopo un POST (`registrazione.verso-stripe`, `abbonamento.portale`).
 * `camera=(self)` perché lo scanner QR del Campo usa `getUserMedia`.
 *
 * ## HSTS solo fuori da `local` e `testing`
 *
 * Su Herd `*.test` gira in HTTP: un HSTS lì imprigionerebbe il dominio nel
 * browser dello sviluppatore per un anno. Non dipende da `isSecure()`: dietro il
 * proxy di Laravel Cloud la richiesta può arrivare in chiaro all'app, e il
 * browser ignora comunque un HSTS ricevuto su HTTP (RFC 6797 §8.1).
 *
 * Nessun header già impostato viene sovrascritto: una risposta che ne decide uno
 * da sé (un download con il suo `nosniff`, un domani un embed) ha l'ultima parola.
 */
class SecurityHeaders
{
    public const CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."media-src 'self' blob:; "
        ."object-src 'none'; "
        ."base-uri 'self'; "
        ."frame-ancestors 'none'; "
        ."form-action 'self' https://checkout.stripe.com https://billing.stripe.com";

    public const HSTS = 'max-age=31536000';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $intestazioni = [
            'Content-Security-Policy-Report-Only' => self::CSP,
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        if (! app()->environment(['local', 'testing'])) {
            $intestazioni['Strict-Transport-Security'] = self::HSTS;
        }

        foreach ($intestazioni as $nome => $valore) {
            if (! $response->headers->has($nome)) {
                $response->headers->set($nome, $valore);
            }
        }

        return $response;
    }
}
