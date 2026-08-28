<?php

namespace App\Support\Registrazione;

use App\Models\Registrazione;
use App\Support\Piani;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Checkout;
use RuntimeException;

/**
 * L'implementazione vera di `PortaleCheckout`: Stripe Checkout in modalità
 * **guest** (🔗 ADR-012, ADR-032, ADR-035).
 *
 * ## 🔴 `Checkout::guest()` e non `Checkout::customer()`
 *
 * Il Billable di questo progetto è l'`Account` (ADR-032), e al momento in cui
 * si apre il checkout **l'Account non esiste ancora**: è precisamente la
 * decisione di prodotto — nessun account marcato pagante che non paga. Non c'è
 * quindi nessun owner da passare, e la sessione nasce senza customer: lo crea
 * Stripe al pagamento, e il suo id torna nell'esito per essere scritto su
 * `accounts.stripe_id` **dopo** il provisioning.
 *
 * ## ⛔ Nessun `session_id` nell'URL di ritorno
 *
 * `success_url` e `cancel_url` sono URL **firmati** da Laravel, e la firma
 * copre l'intera query string: il `?session_id={CHECKOUT_SESSION_ID}` che Stripe
 * sostituirebbe la invaliderebbe, e il ritorno da un pagamento **già incassato**
 * darebbe 403. Non serve nemmeno: la sessione sta su `registrazioni`, e
 * rileggerla da lì invece che dall'URL è anche l'unico modo di non fidarsi del
 * browser.
 *
 * ⚠️ Le due firme durano **un giorno**: chi resta quaranta minuti sulla pagina
 * di Stripe — un cambio di carta, una telefonata — deve poter tornare. Una
 * scadenza corta qui è un 403 su un pagamento riuscito.
 *
 * ## `metadata.registrazione_id`, che è la rete del webhook
 *
 * È il solo modo che ha `checkout.session.completed` di sapere quale riga
 * completare quando il cliente chiude la scheda prima di tornare. Va sulla
 * **sessione** e sulla **subscription**: la prima serve al nostro handler, la
 * seconda rende leggibile dalla dashboard di Stripe da dove viene un
 * abbonamento che ancora non ha un account.
 */
final class PortaleCheckoutStripe implements PortaleCheckout
{
    public function apri(Registrazione $registrazione, string $successUrl, string $cancelUrl): SessioneCheckout
    {
        $price = Piani::stripePrice($registrazione->piano);

        // Cintura: chi chiama ha già verificato. Ma qui sotto c'è una chiamata
        // di rete che creerebbe un oggetto su Stripe, e un `blank()` che
        // arrivasse fin qui produrrebbe un errore dell'API illeggibile invece
        // di un guasto con un nome.
        if (blank($price)) {
            throw new RuntimeException(
                "Il piano «{$registrazione->piano}» non ha un price di Stripe a listino: il checkout non si apre."
            );
        }

        $checkout = Checkout::guest()->create($price, [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            // Stripe incassa e crea il customer: l'email la conosce già, e
            // preriempirla evita che il cliente ne digiti una diversa da quella
            // che ha appena verificato — che darebbe un customer intestato a una
            // casella e un account intestato a un'altra.
            'customer_email' => $registrazione->email,
            'metadata' => [
                'registrazione_id' => (string) $registrazione->id,
                'piano' => $registrazione->piano,
            ],
            'subscription_data' => [
                'metadata' => [
                    'registrazione_id' => (string) $registrazione->id,
                    'piano' => $registrazione->piano,
                ],
            ],
        ]);

        $sessione = $checkout->asStripeCheckoutSession();

        return new SessioneCheckout(id: $sessione->id, url: $sessione->url);
    }

    public function esito(string $sessionId): EsitoCheckout
    {
        $sessione = Cashier::stripe()->checkout->sessions->retrieve($sessionId, []);

        return EsitoCheckout::daSessioneStripe($sessione->toArray());
    }
}
