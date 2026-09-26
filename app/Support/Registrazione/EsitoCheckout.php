<?php

namespace App\Support\Registrazione;

/**
 * Cosa dice Stripe di una sessione di Checkout (🔗 ADR-012, ADR-032).
 *
 * È il **valore** che separa «leggere Stripe» da «creare un account»: da una
 * parte `PortaleCheckout`, che parla con la rete; dall'altra
 * `CompletaRegistrazione`, che è la sola a scrivere. In mezzo questo oggetto,
 * che i test costruiscono a mano — ed è la ragione per cui il cuore della
 * feature ha test veri invece di un mock che verifica sé stesso.
 *
 * 🔴 **`pagato` è l'UNICA domanda che conta, e ha due condizioni.** Una sessione
 * `open` è un checkout aperto e mai concluso; una `complete` con
 * `payment_status` diverso da `paid` è un pagamento non incassato (bonifico in
 * attesa, autorizzazione non catturata). Guardare una sola delle due farebbe
 * nascere un account che non ha pagato — cioè esattamente ciò che la decisione
 * di prodotto vieta.
 *
 * ⚠️ **`piano` c'è ma NON si scrive.** Arriva dai `metadata` della sessione,
 * cioè da un payload che viaggia su un dominio di terzi: serve solo a
 * diagnosticare una divergenza. Il piano che finisce su `accounts.piano` è
 * quello memorizzato in `registrazioni.piano`, deciso da noi prima di
 * consegnare il browser a Stripe. Un test lo prova alterando il payload.
 */
final readonly class EsitoCheckout
{
    public function __construct(
        public bool $pagato,
        public ?string $sessionId = null,
        public ?string $customerId = null,
        public ?string $subscriptionId = null,
        public ?string $piano = null,
        /**
         * La partita IVA raccolta in checkout (`tax_id_collection`), se il
         * cliente ne ha dichiarata una.
         *
         * ⚠️ **`null` è un esito legittimo e frequente**, non un guasto: la
         * raccolta è facoltativa apposta, perché imporla bloccherebbe una
         * vendita già decisa per un dato che si può chiedere dopo (🔗 ADR-039).
         * Chi la consuma la tratta come mancante, non come errore.
         */
        public ?string $partitaIva = null,
    ) {}

    /**
     * L'esito letto dall'oggetto `checkout.session` di Stripe, nella forma in
     * cui arriva sia dall'API sia dal payload del webhook.
     *
     * ⚠️ `customer` e `subscription` sono **o una stringa o un oggetto
     * espanso**, a seconda di come la sessione è stata recuperata: entrambe le
     * forme si normalizzano qui, in un posto solo, invece che nei due
     * chiamanti.
     *
     * @param  array<string, mixed>  $sessione
     */
    public static function daSessioneStripe(array $sessione): self
    {
        return new self(
            pagato: ($sessione['status'] ?? null) === 'complete'
                && ($sessione['payment_status'] ?? null) === 'paid',
            sessionId: self::id($sessione['id'] ?? null),
            customerId: self::id($sessione['customer'] ?? null),
            subscriptionId: self::id($sessione['subscription'] ?? null),
            piano: is_string($sessione['metadata']['piano'] ?? null) ? $sessione['metadata']['piano'] : null,
            partitaIva: self::partitaIva($sessione),
        );
    }

    /**
     * La partita IVA dichiarata in checkout.
     *
     * ⚠️ **`customer_details.tax_ids` è una LISTA**, e ne arriva una sola voce
     * in pratica — ma la forma è quella, quindi si legge la prima invece di
     * assumere una chiave che non esiste. Un `value` vuoto significa che il
     * campo è stato mostrato e lasciato in bianco: è un «non dichiarata», non
     * una stringa vuota da scrivere a database.
     *
     * @param  array<string, mixed>  $sessione
     */
    private static function partitaIva(array $sessione): ?string
    {
        $taxIds = $sessione['customer_details']['tax_ids'] ?? null;

        if (! is_array($taxIds)) {
            return null;
        }

        foreach ($taxIds as $taxId) {
            $valore = is_array($taxId) ? ($taxId['value'] ?? null) : null;

            if (is_string($valore) && trim($valore) !== '') {
                return mb_substr(trim($valore), 0, 20);
            }
        }

        return null;
    }

    /** L'id di Stripe, che sia arrivato nudo o dentro un oggetto espanso. */
    private static function id(mixed $valore): ?string
    {
        if (is_string($valore) && $valore !== '') {
            return $valore;
        }

        if (is_array($valore) && is_string($valore['id'] ?? null)) {
            return $valore['id'];
        }

        return null;
    }
}
