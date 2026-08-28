<?php

namespace App\Support\Registrazione;

use App\Models\Registrazione;

/**
 * L'unica porta verso il Checkout ospitato di Stripe per il self-signup
 * (🔗 ADR-012, ADR-032, ADR-035 per il price che si legge dal listino).
 *
 * **Perché un'interfaccia e non una classe sola**, al contrario di
 * `App\Support\Billing\PortaleStripe`: là la cucitura serviva a non parlare con
 * la rete dentro un test, e bastava estendere la classe. Qui la logica **nostra**
 * a valle è sostanziale — il conto di quando un account nasce, l'idempotenza, il
 * piano che non si legge dal payload — e va provata contro esiti che Stripe non
 * ci darebbe mai su richiesta: sessione aperta, pagamento non incassato,
 * customer mancante. Un'interfaccia rende quegli esiti costruibili in una riga.
 *
 * Il percorso felice verso la rete resta **senza test di suite**, come
 * `easylab:abbona` e come l'apertura del Billing Portal: si verifica su staging
 * con le chiavi di test.
 */
interface PortaleCheckout
{
    /**
     * Apre la sessione di pagamento e restituisce **id e URL**.
     *
     * ⛔ Chiamata di RETE: chi la invoca ha già verificato che la casella sia
     * stata verificata, che il piano esista a listino e che il suo price sia
     * configurato. Le guardie stanno **prima**, come in `easylab:abbona`: un
     * oggetto lasciato a metà su Stripe non si ripulisce da questa parte.
     *
     * ⚠️ **L'id torna insieme all'URL, e non è una comodità**: chi chiama deve
     * scriverlo su `registrazioni.stripe_session_id` **prima** del redirect,
     * perché al ritorno non c'è nessun `session_id` nella query string da cui
     * leggerlo — vedi `SessioneCheckout` e `PortaleCheckoutStripe`.
     */
    public function apri(Registrazione $registrazione, string $successUrl, string $cancelUrl): SessioneCheckout;

    /**
     * Cosa è successo alla sessione, riletta da Stripe.
     *
     * ⛔ Il `sessionId` arriva da `registrazioni.stripe_session_id` — cioè da
     * una riga nostra — e **mai** dalla query string di ritorno: fidarsi
     * dell'URL significherebbe lasciare al browser la scelta di quale pagamento
     * far valere.
     */
    public function esito(string $sessionId): EsitoCheckout;
}
