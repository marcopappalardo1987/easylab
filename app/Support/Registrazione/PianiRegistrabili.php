<?php

namespace App\Support\Registrazione;

use App\Models\Piano;
use App\Support\Piani;

/**
 * Quali piani il **modulo pubblico** può davvero vendere (🔗 ADR-012, ADR-032,
 * ADR-035).
 *
 * Non è un secondo listino: è il listino visto dall'unica superficie del
 * progetto in cui *nessuno è autenticato* e il compratore decide da solo. Tre
 * filtri, e ciascuno chiude un guasto diverso.
 *
 * 1. **`offribili()`** — un piano archiviato (ADR-035) resta a catalogo per chi
 *    ci sta sopra, ma non si vende più. Filtrare qui e non in `Piani` è
 *    deliberato: `codici()` deve continuare a comprendere gli archiviati, o
 *    ogni cliente rimasto su un piano ritirato varrebbe 0 € nell'MRR.
 *
 * 2. 🔴 **Niente piani GRATUITI, e non è una restrizione estetica.** La
 *    decisione di prodotto di questa feature è una sola frase: *l'account nasce
 *    solo se il pagamento è andato a buon fine*. Un piano omaggiato non ha
 *    checkout da superare, quindi offrirlo qui sarebbe l'unica porta del
 *    progetto da cui chiunque, senza pagare e senza che nessuno lo autorizzi,
 *    si crea un Ente e un ruolo `Admin`. Il Free esiste (ADR-002) ma si
 *    concede: nasce da `easylab:provision-tenant` o dalla cabina, cioè da un
 *    gesto di qualcuno che risponde di quel contratto.
 *
 * 3. **Price di Stripe configurato** — ed è un **errore di deploy**, non un
 *    piano gratuito: `Piani::eGratuito()` è una *dichiarazione* del listino, e
 *    dedurre la gratuità dall'assenza del prezzo confonderebbe le due cose
 *    nascondendo un guasto dietro un messaggio rassicurante (è la dottrina che
 *    `easylab:abbona` ha già scritto). Quando il set esce vuoto per questa
 *    ragione il modulo dice «registrazioni chiuse» invece di regalare un
 *    account.
 *
 * ⚠️ **`blank()` e non `!== null`**: `phpunit.xml` azzera `STRIPE_PRICE_SAAS`,
 * quindi in suite `Piani::stripePrice()` può tornare la **stringa vuota**
 * invece di `null`. Un controllo sull'identità con `null` lascerebbe passare
 * `''` fino alla chiamata di rete, che fallirebbe con un errore dell'API
 * illeggibile a pagamento non ancora avvenuto.
 */
final class PianiRegistrabili
{
    /**
     * I codici acquistabili dal modulo pubblico, nell'ordine del listino.
     *
     * @return list<string>
     */
    public static function codici(): array
    {
        return collect(Piani::offribili())
            ->reject(fn (string $codice) => Piani::eGratuito($codice))
            ->filter(fn (string $codice) => filled(Piani::stripePrice($codice)))
            ->values()->all();
    }

    /**
     * Gli stessi piani come model, per la vista che ne mostra etichetta e
     * prezzo. Passa da `Piani::modello()` e non da una query propria: la porta
     * al listino resta una sola.
     *
     * @return list<Piano>
     */
    public static function modelli(): array
    {
        return array_map(fn (string $codice) => Piani::modello($codice), self::codici());
    }

    /** Il modulo ha qualcosa da vendere: se no, è chiuso per guasto. */
    public static function ceQualcosaDaVendere(): bool
    {
        return self::codici() !== [];
    }

    /**
     * ⛔ La domanda che si fa **prima del pagamento**, e due volte: sull'input
     * del form e di nuovo in `RegistrazionePubblica::versoStripe()`.
     *
     * Due volte e non una perché fra il modulo e il POST che apre il checkout
     * passa del tempo, e nel frattempo un piano può essere archiviato o
     * cancellato da `/piattaforma/piani`: aprire una sessione di pagamento su un
     * piano ritirato è vendere qualcosa che non è più in vetrina.
     *
     * ⚠️ **E NON è la domanda che si fa dopo l'incasso.** `CompletaRegistrazione`
     * ricontrolla il piano con `Piani::esiste()`, che è più largo — comprende
     * gli archiviati — e la differenza è deliberata: prima del pagamento
     * chiudere la porta non costa niente, dopo significherebbe aver incassato
     * senza consegnare. Il perché per esteso sta nel docblock di quella classe;
     * qui basti che le due domande sono **diverse apposta**, e che chi le
     * uniformasse per simmetria rifiuterebbe un account a chi ha già pagato.
     */
    public static function accetta(?string $piano): bool
    {
        return $piano !== null && in_array($piano, self::codici(), true);
    }
}
