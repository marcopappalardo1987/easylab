<?php

namespace App\Http\Middleware;

use Closure;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 🔴 La firma del webhook Stripe, resa fail-closed sul serio (ADR-013).
 *
 * `VerifyWebhookSignature` di Cashier chiama `WebhookSignature::verifyHeader()`,
 * che calcola la firma attesa con `hash_hmac('sha256', $payload, $secret)`.
 * **Con `$secret` vuoto o null quella firma è pubblicamente calcolabile**:
 * chiunque può produrre un `Stripe-Signature` valido e farsi accettare un
 * payload arbitrario. Il middleware del pacchetto non se ne accorge — per lui
 * la verifica è passata.
 *
 * Il progetto aveva già chiuso metà del problema montando il middleware **sulla
 * rotta** invece di lasciarlo al costruttore del controller, che lo aggancia
 * solo `if (config('cashier.webhook.secret'))`. Ma quella era la fail-open
 * *dell'aggancio*: sotto restava quella *crittografica*, e un endpoint che
 * risponde 200 a una firma calcolata con la chiave vuota è indistinguibile da
 * uno sano. **Trovato da `/security-review`**, con un test che lo dimostrava
 * verde: la prima stesura mandava un header di firma *vuoto*, che
 * `verifyHeader()` scarta nel parser — provava il caso sbagliato.
 *
 * Perché non è un caso di scuola: `config/cashier.php` legge
 * `env('STRIPE_WEBHOOK_SECRET')`, su Laravel Cloud `php artisan optimize` gira
 * in **build**, e il `whsec_…` esiste solo **dopo** aver creato l'endpoint nella
 * dashboard Stripe. Su ogni ambiente nuovo c'è quindi una finestra garantita in
 * cui la rotta è raggiungibile e il segreto è null.
 *
 * Cosa può fare un attaccante in quella finestra, conoscendo un `cus_…`:
 * bloccare tutti gli utenti di tutti gli Enti di un account (`unpaid`), oppure
 * sbloccare il proprio e regalarsi il piano a pagamento (`active` + price id).
 *
 * Qui la porta si chiude prima: senza segreto il webhook è **inerte**, e
 * l'unico prezzo è un evento perso — che Stripe ritenta, e che la dashboard
 * mostra come consegna fallita.
 */
class VerificaFirmaWebhookStripe extends VerifyWebhookSignature
{
    public function handle($request, Closure $next)
    {
        if (blank(config('cashier.webhook.secret'))) {
            throw new AccessDeniedHttpException(
                'Webhook secret non configurato: la firma non è verificabile.'
            );
        }

        return parent::handle($request, $next);
    }
}
