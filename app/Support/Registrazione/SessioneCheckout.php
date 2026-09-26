<?php

namespace App\Support\Registrazione;

/**
 * La sessione di Checkout appena aperta: **il suo id e dove mandare il
 * browser** (🔗 ADR-012).
 *
 * ⚠️ **Esiste perché l'id serve a noi, non a Stripe.** La prima stesura di
 * `PortaleCheckout::apri()` restituiva la sola URL, ed era un vicolo cieco: il
 * `success_url` non può portare `?session_id={CHECKOUT_SESSION_ID}` — la firma
 * di Laravel copre l'intera query string e Stripe la invaliderebbe, dando 403
 * su un pagamento già incassato — quindi l'unico posto in cui quell'id può
 * vivere è **una colonna nostra**, scritta prima di consegnare il browser.
 * Senza, al ritorno non sapremmo quale sessione rileggere, e l'alternativa
 * (fidarsi dell'URL) è precisamente ciò che il progetto rifiuta.
 *
 * Due valori e non una tupla: `[$id, $url]` si scambia di posto senza che
 * nessuno se ne accorga, e sono entrambi stringhe.
 */
final readonly class SessioneCheckout
{
    public function __construct(
        public string $id,
        public string $url,
    ) {}
}
