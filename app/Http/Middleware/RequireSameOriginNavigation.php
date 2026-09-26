<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una rotta GET che **cambia lo stato della sessione** accetta solo navigazioni
 * partite da una pagina nostra (security pass S7, T1b — 🔗 ADR-016 nota del
 * 21 Ago 2026, ADR-018; nessuna entità dell'ERD).
 *
 * Nasce per l'impersonazione: `GET /impersonate/take/{id}` e
 * `GET /piattaforma/parco/impersona/...` sostituiscono l'utente in sessione, e
 * un GET non porta token CSRF. Con i cookie `SameSite=Lax` una pagina esterna
 * può ancora far **navigare** lì un Superadmin connesso (un link, un
 * `window.location`), e da quel momento lui lavora dentro un altro tenant senza
 * averlo deciso. Il rischio era dichiarato e rimandato a questo pass
 * (`OffreImpersonazione`).
 *
 * ## Perché `Sec-Fetch-Site` e non un POST
 *
 * L'ingresso è un `<a href>` apposta: `take()` sostituisce l'utente, e una
 * risposta Livewire lascerebbe in pagina un componente montato per l'utente
 * precedente (il perché sta in `OffreImpersonazione`). `Sec-Fetch-Site` è un
 * header *forbidden*: una pagina non può falsificarlo, e i browser correnti lo
 * mandano a ogni navigazione.
 *  - `same-origin` → click da una pagina nostra: passa;
 *  - `none`        → URL digitato o preferito: gesto dell'utente, passa;
 *  - `same-site` / `cross-site` → rifiutato, **anche** da un sottodominio.
 *
 * ## ⚠️ Rischio residuo dichiarato
 *
 * Senza `Sec-Fetch-Site` (browser precedenti a Safari 16.4, client non
 * browser) si ricade sul `Referer`: se c'è e punta fuori, si rifiuta; se manca
 * del tutto si lascia passare. Il caso sfruttabile resta un Superadmin su un
 * browser di prima del 2023 **e** una pagina con `referrerpolicy=no-referrer`.
 * Rifiutare anche l'assenza avrebbe chiuso fuori i test HTTP e ogni client che
 * non è un browser, per un caso che la piattaforma non supporta.
 *
 * Il `Referer` si confronta sul solo **host**, non su schema e porta: dietro il
 * proxy di Laravel Cloud l'app può vedere `http` e la porta interna mentre il
 * browser ha navigato in `https:443`, e un confronto pieno rifiutava utenti
 * legittimi (sospetto S1 della caccia T1b). Un attaccante non può servire una
 * pagina dal nostro host su un'altra porta, quindi l'host basta.
 *
 * ⚠️ **Secondo rischio dichiarato (S2 della caccia T1b):** un link aperto da un
 * client di posta desktop o da un'app arriva con `Sec-Fetch-Site: none`, come un
 * URL digitato, e passa. Chi manda al Superadmin un'email con il link di
 * impersonazione lo fa entrare in un tenant con un clic. Resta così perché
 * `none` è anche il preferito e l'URL incollato, e il browser non li distingue;
 * il gesto è comunque un clic dell'utente, loggato, e col banner a schermo.
 */
class RequireSameOriginNavigation
{
    private const AMMESSI = ['same-origin', 'none'];

    public function handle(Request $request, Closure $next): Response
    {
        $sito = $request->headers->get('Sec-Fetch-Site');

        if ($sito !== null) {
            abort_unless(in_array(strtolower($sito), self::AMMESSI, true), 403);

            return $next($request);
        }

        $provenienza = $request->headers->get('Referer');

        if ($provenienza !== null && ! $this->stessaOrigine($provenienza, $request)) {
            abort(403);
        }

        return $next($request);
    }

    private function stessaOrigine(string $url, Request $request): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strtolower($host) === strtolower($request->getHost());
    }
}
