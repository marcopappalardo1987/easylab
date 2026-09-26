<?php

namespace App\Support\Listino\Stripe;

use App\Models\Piano;
use Laravel\Cashier\Cashier;

/**
 * La porta vera verso Stripe (🔗 ADR-035, ADR-002).
 *
 * ⚠️ **Non ha test di suite, ed è dichiarato** — stessa scelta di
 * `easylab:abbona`: mockare `StripeClient` produrrebbe un test che verifica il
 * proprio mock. Si verifica su staging con le chiavi test. Ciò che i test
 * coprono è l'altra metà, quella che conta: `GovernoListino` con la porta
 * finta, cioè le **nostre** transizioni di stato.
 *
 * Il client viene da `Cashier::stripe()` e non da un `new StripeClient`: è la
 * stessa configurazione (chiave, versione dell'API) che usa tutto il resto del
 * blocco Cashier, e una seconda strada verso Stripe sarebbe una seconda
 * configurazione da tenere allineata.
 */
final class PortaListinoStripeReale implements PortaListinoStripe
{
    public function creaOAggiornaProdotto(Piano $piano): string
    {
        $client = Cashier::stripe();

        // ⚠️ **Prima si guarda se il product id c'è già**, e non ci si affida
        // alla sola `idempotency_key`: quella scade dopo 24 ore, quindi non
        // protegge il tentativo di domani. È il primo dei due livelli.
        if (filled($piano->stripe_product_id)) {
            $client->products->update($piano->stripe_product_id, ['name' => $piano->etichetta]);

            return $piano->stripe_product_id;
        }

        $prodotto = $client->products->create([
            'name' => $piano->etichetta,
            // Il codice sul Product: è la sola strada per ritrovare a mano, in
            // dashboard, a quale piano di EasyLab appartiene un oggetto di
            // Stripe — e la dashboard è dove qualcuno andrà a guardare quando
            // qualcosa non torna.
            'metadata' => ['easylab_piano' => $piano->codice],
        ], ['idempotency_key' => 'easylab_prod_'.sha1($piano->codice)]);

        return $prodotto->id;
    }

    public function creaPrezzo(Piano $piano, int $importoCent, string $valuta, string $chiaveIdempotenza): PrezzoRemoto
    {
        $prezzo = Cashier::stripe()->prices->create([
            'product' => $piano->stripe_product_id,
            'unit_amount' => $importoCent,
            'currency' => $valuta,
            'recurring' => ['interval' => 'month'],
            'metadata' => ['easylab_piano' => $piano->codice],
        ], ['idempotency_key' => $chiaveIdempotenza]);

        return self::inPrezzoRemoto($prezzo);
    }

    public function archiviaPrezzo(string $priceId): void
    {
        Cashier::stripe()->prices->update($priceId, ['active' => false]);
    }

    public function leggiProdotto(string $productId): ?array
    {
        $prodotto = Cashier::stripe()->products->retrieve($productId);

        return [
            'id' => $prodotto->id,
            'nome' => (string) $prodotto->name,
            'attivo' => (bool) $prodotto->active,
        ];
    }

    public function leggiPrezzo(string $priceId): ?PrezzoRemoto
    {
        $prezzo = Cashier::stripe()->prices->retrieve($priceId);

        return self::inPrezzoRemoto($prezzo);
    }

    public function creaPaymentLink(Piano $piano, string $priceId, string $chiaveIdempotenza): PaymentLinkRemoto
    {
        $link = Cashier::stripe()->paymentLinks->create([
            'line_items' => [['price' => $priceId, 'quantity' => 1]],

            // 🔴 **I due campi che rendono attuabile tutto il resto.** Il
            // provisioning esige una ragione sociale e un nome referente, e
            // Stripe li raccoglie da sé: senza, servirebbero i `custom_fields`,
            // che sono modificabili dalla dashboard e quindi una superficie di
            // input in più su una strada che porta denaro.
            //
            // ⚠️ Nessun `optional`: il default è `false`, cioè obbligatori — ed
            // è il default giusto, perché un account non può nascere senza.
            'name_collection' => [
                'business' => ['enabled' => true],
                'individual' => ['enabled' => true],
            ],

            // ⚠️ **Chiesta ma non imposta** (`required` non è passato, quindi
            // vale il default «auto»): chi ha una partita IVA la mette e finisce
            // sull'Account, chi non ce l'ha — un privato, un ente — paga lo
            // stesso. Imporla bloccherebbe una vendita già decisa per un dato
            // che si può chiedere dopo, e questo modulo incassa **prima** di
            // consegnare: un rifiuto qui è denaro non preso, non un errore da
            // correggere.
            'tax_id_collection' => ['enabled' => true],

            // Chi ha appena pagato torna **da noi**. `{CHECKOUT_SESSION_ID}` lo
            // sostituisce Stripe: è ciò che permette alla pagina di conferma di
            // dire qualcosa di vero invece di un ringraziamento generico.
            'after_completion' => [
                'type' => 'redirect',
                'redirect' => ['url' => route('pagamento.ricevuto').'?sessione={CHECKOUT_SESSION_ID}'],
            ],

            // ⚠️ **Etichetta per la dashboard, e nient'altro.** Il codice non la
            // legge mai: il piano si risolve dal plink id sulla nostra
            // `prezzi_piano`, perché i metadata si riscrivono dalla dashboard e
            // un piano che arriva dal payload è un piano che si può regalare.
            'metadata' => ['easylab_piano' => $piano->codice],
        ], ['idempotency_key' => $chiaveIdempotenza]);

        return self::inPaymentLinkRemoto($link);
    }

    public function disattivaPaymentLink(string $plinkId): void
    {
        Cashier::stripe()->paymentLinks->update($plinkId, ['active' => false]);
    }

    public function leggiPaymentLink(string $plinkId): ?PaymentLinkRemoto
    {
        return self::inPaymentLinkRemoto(Cashier::stripe()->paymentLinks->retrieve($plinkId));
    }

    private static function inPaymentLinkRemoto(mixed $link): PaymentLinkRemoto
    {
        return new PaymentLinkRemoto(
            id: $link->id,
            url: (string) $link->url,
            attivo: (bool) $link->active,
        );
    }

    /**
     * La risposta di Stripe → il nostro dato.
     *
     * ⚠️ **`product` arriva come stringa o come oggetto**, a seconda che la
     * chiamata l'abbia espanso: leggerlo in un modo solo funzionerebbe finché
     * qualcuno non aggiunge un `expand`, e il sintomo sarebbe un product id
     * `null` — cioè una guardia di `agganciaPrezzo()` che si astiene in
     * silenzio.
     */
    private static function inPrezzoRemoto(mixed $prezzo): PrezzoRemoto
    {
        $prodotto = $prezzo->product ?? null;

        return new PrezzoRemoto(
            id: $prezzo->id,
            importoCent: (int) $prezzo->unit_amount,
            valuta: (string) $prezzo->currency,
            attivo: (bool) $prezzo->active,
            prodotto: is_string($prodotto) ? $prodotto : ($prodotto?->id ?? null),
        );
    }
}
