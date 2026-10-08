<?php

namespace App\Support\Billing;

use App\Models\Account;
use Laravel\Cashier\Subscription;
use Stripe\Subscription as StripeSubscription;

/**
 * La porta verso Stripe per **attivare** un piano e per **cambiarlo**
 * (🔗 ADR-045, ADR-032 l'Account intestatario, ADR-013 il lockout).
 *
 * È una cucitura, come `PortaleStripe`: `SceltaPianoAbbonamento` dev'essere
 * provato per intero — autorizzazione, guardie, la regola «mai gratuito, mai
 * più economico», gli URL di ritorno — senza che la suite parli con Stripe. La
 * suite la estende con una finta; il percorso felice verso la rete resta senza
 * test di suite, come `easylab:abbona` e il Billing Portal, e si verifica su
 * staging con le chiavi di test.
 *
 * ⛔ **Chi la invoca ha già fatto tutte le guardie**: appartenenza all'account,
 * impersonazione, chiave segreta, e soprattutto che il piano sia fra quelli di
 * `PianiAcquistabili`. Qui sotto ogni metodo crea o modifica un oggetto di
 * fatturazione **vero**.
 *
 * Non `final` e senza costruttore: il container la risolve senza binding.
 */
class AbbonamentoStripe
{
    /**
     * Apre il Checkout ospitato per un account **senza abbonamento in corso**,
     * e ne restituisce l'URL.
     *
     * ## 🔴 `checkout()` sul customer dell'Account, non `Checkout::guest()`
     *
     * Al contrario del self-signup (`PortaleCheckoutStripe`) qui l'Account
     * **esiste già**: la sessione nasce intestata al suo customer, quindi la
     * subscription che ne esce arriva al webhook con uno `stripe_id` che il
     * database conosce. È `customer.subscription.created` a scrivere la riga
     * locale e a riallineare il piano — nessun handler nuovo, nessun
     * `registrazione_id`, nessuna corsa fra l'account e il suo pagamento.
     *
     * ## ⛔ `success_url` e `cancel_url` sono OBBLIGATORI
     *
     * Il default di Cashier è `route('home')`, e in questa applicazione non
     * esiste nessuna rotta con quel nome: ometterli darebbe
     * `RouteNotFoundException`, cioè un 500 sul bottone che porta denaro. È la
     * stessa trappola del `returnUrl` di `PortaleStripe`, e la firma è la
     * guardia.
     *
     * ## Le due difese contro il doppio abbonamento
     *
     * 1. **Stripe viene interrogato prima** — vedi `AbbonamentoGiaSuStripe`: lo
     *    specchio locale può essere in ritardo, e fidarsi solo di lui farebbe
     *    aprire un checkout a chi ha appena pagato.
     * 2. **I checkout rimasti aperti si chiudono**: due schede su «Attiva» sono
     *    due sessioni pagabili. Chiudere le precedenti lascia viva solo
     *    l'ultima. È `rescue()` perché è una cortesia e non una condizione: se
     *    fallisce, il checkout si apre lo stesso.
     *
     * @throws AbbonamentoGiaSuStripe
     */
    public function checkout(Account $account, string $piano, string $price, string $successUrl, string $cancelUrl): string
    {
        // Un customer appena creato non ha né abbonamenti né sessioni da
        // guardare: le due difese valgono solo per chi su Stripe c'era già.
        if ($account->hasStripeId()) {
            $this->rifiutaSeGiaAbbonato($account);

            rescue(fn () => $this->chiudiCheckoutAperti($account));
        } else {
            $account->createAsStripeCustomer();
        }

        $opzioni = $this->opzioniCheckout($account, $piano, $successUrl, $cancelUrl);

        $checkout = $account->newSubscription('default', $price)
            ->withMetadata($opzioni['metadata'])
            ->checkout($opzioni);

        return $checkout->asStripeCheckoutSession()->url;
    }

    /**
     * Cosa si chiede a Stripe quando si apre il checkout di attivazione,
     * **senza toccare la rete**.
     *
     * È un metodo a parte perché è l'unica parte di `checkout()` che la suite
     * può provare, ed è quella che porta le decisioni di prodotto: il resto è
     * la chiamata.
     *
     * ## 🔴 La partita IVA si inserisce QUI, ed è obbligatoria
     *
     * `tax_id_collection.required = if_supported`: nei paesi in cui Stripe sa
     * raccoglierla — l'Italia è fra questi — il pagamento non si conclude
     * senza. Chi attiva un piano a pagamento è un'azienda a cui va emessa una
     * fattura, e rincorrere il dato dopo l'incasso costa più che chiederlo
     * prima. Dal checkout arriva sull'Account col webhook
     * (`StripeWebhookController::annotaLaPartitaIva()`), e Stripe la salva sul
     * customer, dove finisce sulle sue fatture.
     *
     * ⚠️ **È una scelta diversa da quella del Payment Link** (ADR-039), dove la
     * partita IVA è chiesta ma non imposta: là il compratore è uno sconosciuto
     * e un rifiuto è una vendita persa; qui è un cliente che esiste già, e che
     * se non ne ha una sa a chi telefonare.
     *
     * ⚠️ Stripe **non richiede** la partita IVA a un customer che ne ha già una
     * salvata: chi riattiva dopo una disdetta non la riscrive.
     *
     * ## Le due righe che la raccolta porta con sé
     *
     * - `customer_update.name = auto` — Stripe lo **esige** quando la raccolta
     *   è accesa su un customer esistente: la ragione sociale scritta in
     *   checkout diventa il nome del customer. Tocca il customer su Stripe,
     *   mai `accounts.ragione_sociale`.
     * - `billing_address_collection` e `customer_update.address` — l'indirizzo
     *   serve a Stripe per sapere in che paese sta il cliente, cioè se la
     *   partita IVA va chiesta; salvarlo sul customer lo mette in fattura
     *   invece di buttarlo via dopo averlo fatto scrivere.
     *
     * ## I metadata non sono per noi
     *
     * Servono a chi guarda la dashboard di Stripe. Il piano che finisce su
     * `accounts.piano` lo risolve il webhook dal price, sul NOSTRO listino
     * (`Piani::perPrice()`): un piano letto dai metadata sarebbe un piano che
     * si può regalare riscrivendoli.
     *
     * @return array{success_url: string, cancel_url: string, metadata: array<string, string>, tax_id_collection: array{enabled: bool, required: string}, billing_address_collection: string, customer_update: array<string, string>}
     */
    public function opzioniCheckout(Account $account, string $piano, string $successUrl, string $cancelUrl): array
    {
        return [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => ['account_id' => (string) $account->getKey(), 'piano' => $piano],
            'tax_id_collection' => ['enabled' => true, 'required' => 'if_supported'],
            'billing_address_collection' => 'required',
            'customer_update' => ['name' => 'auto', 'address' => 'auto'],
        ];
    }

    /**
     * Cambia il prezzo dell'abbonamento in corso, e restituisce lo stato che
     * Stripe gli ha dato.
     *
     * ⚠️ **`swap()` e non `swapAndInvoice()`**: la differenza per il periodo
     * già iniziato la calcola Stripe e va sulla **prossima fattura**. Fatturare
     * subito vorrebbe dire tentare un addebito dentro questa richiesta, e un
     * addebito che chiede il 3-D Secure ha bisogno della pagina di conferma di
     * Cashier — che `AppServiceProvider` non registra apposta
     * (`Cashier::ignoreRoutes()`). Il cambio lascerebbe l'abbonamento
     * `past_due` senza un posto in cui il cliente possa confermare.
     *
     * Lo specchio locale (`subscriptions.stripe_price`, lo stato) lo aggiorna
     * Cashier dentro `swap()`; `accounts.piano` lo scrive chi chiama, e il
     * webhook `customer.subscription.updated` lo riconferma.
     */
    public function cambia(Subscription $abbonamento, string $price): string
    {
        $abbonamento->swap($price);

        return (string) $abbonamento->stripe_status;
    }

    /**
     * @throws AbbonamentoGiaSuStripe
     */
    private function rifiutaSeGiaAbbonato(Account $account): void
    {
        // Senza `status` Stripe restituisce gli abbonamenti non cancellati,
        // che è la domanda giusta: chiedere `all` metterebbe in fila anche
        // quelli chiusi, e oltre il limite della pagina uno vivo sparirebbe.
        $abbonamenti = $account->stripe()->subscriptions->all([
            'customer' => $account->stripe_id,
            'limit' => 20,
        ]);

        foreach ($abbonamenti->data as $abbonamento) {
            if (! in_array($abbonamento->status, [
                StripeSubscription::STATUS_CANCELED,
                StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
            ], true)) {
                throw AbbonamentoGiaSuStripe::per($account->getKey(), $abbonamento->id, $abbonamento->status);
            }
        }
    }

    private function chiudiCheckoutAperti(Account $account): void
    {
        $aperti = $account->stripe()->checkout->sessions->all([
            'customer' => $account->stripe_id,
            'status' => 'open',
            'limit' => 20,
        ]);

        foreach ($aperti->data as $sessione) {
            $account->stripe()->checkout->sessions->expire($sessione->id);
        }
    }
}
