<?php

namespace Tests\Fakes;

use App\Models\Account;
use App\Support\Billing\AbbonamentoStripe;
use Laravel\Cashier\Subscription;
use Throwable;

/**
 * La finta della porta verso Stripe per l'attivazione e il cambio di piano
 * (🔗 ADR-045).
 *
 * Registra ciò che riceve e non parla con nessuno. `checkout` e `cambi` vuoti
 * sono l'asserzione che regge le guardie «prima della rete»: senza, un test
 * potrebbe passare per il motivo sbagliato.
 *
 * ⚠️ Sul cambio imita la **sola** cosa che `Subscription::swap()` fa in locale —
 * riscrivere `stripe_price` e lo stato sulla riga — perché è da lì che la
 * pagina e la regola rileggono dopo. Tutto il resto è di Stripe.
 */
final class AbbonamentoStripeFinto extends AbbonamentoStripe
{
    /** @var list<array{account_id: int, piano: string, price: string, success_url: string, cancel_url: string}> */
    public array $checkout = [];

    /** @var list<array{subscription: string, price: string}> */
    public array $cambi = [];

    public string $url = 'https://checkout.stripe.test/attivazione-finta';

    public string $statoDopoIlCambio = 'active';

    public ?Throwable $guasto = null;

    public function checkout(Account $account, string $piano, string $price, string $successUrl, string $cancelUrl): string
    {
        if ($this->guasto !== null) {
            throw $this->guasto;
        }

        $this->checkout[] = [
            'account_id' => (int) $account->getKey(),
            'piano' => $piano,
            'price' => $price,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        return $this->url;
    }

    public function cambia(Subscription $abbonamento, string $price): string
    {
        if ($this->guasto !== null) {
            throw $this->guasto;
        }

        $this->cambi[] = ['subscription' => (string) $abbonamento->stripe_id, 'price' => $price];

        $abbonamento->forceFill([
            'stripe_price' => $price,
            'stripe_status' => $this->statoDopoIlCambio,
        ])->save();

        return $this->statoDopoIlCambio;
    }
}
