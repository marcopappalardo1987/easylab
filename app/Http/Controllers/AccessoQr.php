<?php

namespace App\Http\Controllers;

use App\Models\Strumento;
use Illuminate\Http\RedirectResponse;

/**
 * Traduce il token letto dal QR nella scheda dello strumento (🔗 ADR-003).
 *
 * **Non mostra nulla, reindirizza**, ed è la scelta che regge tutta la
 * sicurezza di questo blocco: la scheda esiste in un posto solo
 * (`strumenti.show`), con una sola catena di autorizzazione — permesso, global
 * scope di Ente, sotto-albero del Responsabile. Una vista dedicata «da QR»
 * sarebbe una seconda superficie che quella catena deve ripetere, cioè una
 * seconda occasione di sbagliarla. Il prezzo è un redirect in più; il
 * guadagno è che ADR-003 («mai una scheda accessibile senza autenticazione»)
 * si verifica guardando un file solo.
 *
 * Firma, autenticazione e permesso sono già stati imposti dai middleware della
 * rotta. Qui resta la sola domanda che i middleware non possono fare: **questo
 * utente può vedere QUESTA macchina?** — e la risposta la dà il global scope,
 * perché la `where` gira su `Strumento::query()`. Un token valido di una
 * macchina di un altro Ente produce quindi un 404, non un 403: per chi guarda,
 * quella macchina non esiste — ed è la stessa risposta che darebbe l'URL
 * diretto, il che è esattamente il punto.
 */
class AccessoQr extends Controller
{
    public function __invoke(string $token): RedirectResponse
    {
        $strumento = Strumento::where('qr_token', $token)->firstOrFail();

        return redirect()->route('strumenti.show', $strumento);
    }
}
