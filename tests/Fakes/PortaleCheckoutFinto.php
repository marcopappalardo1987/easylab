<?php

namespace Tests\Fakes;

use App\Models\Registrazione;
use App\Support\Registrazione\EsitoCheckout;
use App\Support\Registrazione\PortaleCheckout;
use App\Support\Registrazione\SessioneCheckout;

/**
 * Stripe Checkout, finto (🔗 ADR-012).
 *
 * ⚠️ **Non verifica le risposte di Stripe, e non deve provarci**: la porta vera
 * (`PortaleCheckoutStripe`) resta senza test di suite, come `easylab:abbona` e
 * come `PortaListinoStripeReale` — mockare `StripeClient` produce un test che
 * verifica il proprio mock. Questa finta esiste per provare l'altra metà,
 * quella che conta: che l'account nasca **solo** a pagamento riuscito, che due
 * passate ne producano uno solo, che il piano scritto venga dalla nostra riga e
 * non dal payload.
 *
 * 🔴 **Conta le invocazioni, ed è un'asserzione di prima classe**: i test
 * negativi della verifica email non provano soltanto che la risposta sia 403,
 * provano che il checkout **non sia stato aperto** — cioè che nessun oggetto
 * sia nato su Stripe per una casella che nessuno ha confermato. Senza il
 * contatore, una guardia spostata dopo la chiamata di rete resterebbe verde.
 *
 * Vive in `tests/Fakes/` e non in un file di test: una classe dichiarata in un
 * file di test esiste solo se quel file viene selezionato — la cicatrice già
 * pagata dal progetto con `snapshotDa()`.
 */
final class PortaleCheckoutFinto implements PortaleCheckout
{
    /** @var list<string> ogni chiamata, in ordine e per nome del metodo. */
    public array $chiamate = [];

    /** Le registrazioni per cui il checkout è stato davvero aperto. */
    public array $aperture = [];

    public string $sessionId = 'cs_test_finta';

    public string $url = 'https://checkout.stripe.test/sessione-finta';

    /** L'esito che `esito()` restituirà. Non pagato per difetto: fail-closed. */
    public ?EsitoCheckout $esito = null;

    public function apri(Registrazione $registrazione, string $successUrl, string $cancelUrl): SessioneCheckout
    {
        $this->chiamate[] = 'apri';
        $this->aperture[] = [
            'registrazione_id' => $registrazione->getKey(),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        return new SessioneCheckout(id: $this->sessionId, url: $this->url);
    }

    public function esito(string $sessionId): EsitoCheckout
    {
        $this->chiamate[] = 'esito';

        // ⚠️ Il default è **non pagato**, non «pagato»: una finta ottimista
        // renderebbe verde per difetto il ramo che questa feature esiste per
        // tenere chiuso.
        return $this->esito ?? new EsitoCheckout(pagato: false, sessionId: $sessionId);
    }

    /** L'esito del percorso felice, in una riga. */
    public function pagato(string $customer = 'cus_finto', string $subscription = 'sub_finta', ?string $piano = 'saas'): self
    {
        $this->esito = new EsitoCheckout(
            pagato: true,
            sessionId: $this->sessionId,
            customerId: $customer,
            subscriptionId: $subscription,
            piano: $piano,
        );

        return $this;
    }

    /** Quante volte è stato chiesto di aprire una sessione su Stripe. */
    public function aperture(): int
    {
        return count(array_filter($this->chiamate, fn (string $c) => $c === 'apri'));
    }
}
