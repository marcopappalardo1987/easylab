<?php

namespace App\Support\Registrazione;

use RuntimeException;

/**
 * Il completamento è stato rifiutato **prima di scrivere qualunque cosa**.
 *
 * Stessa forma di `App\Support\Provisioning\ProvisioningRifiutato`, e per la
 * stessa ragione: due chiamanti — il ritorno da Stripe e il webhook — hanno due
 * modi diversi di raccontarlo (una pagina e un 200 muto con una riga di log) ma
 * la stessa identica regola.
 *
 * ⚠️ **Il messaggio non è un'API: si ramifica sul `codice`.** È la cicatrice già
 * pagata da `ProvisioningRifiutato`, dove un `str_contains()` sul testo spegneva
 * un suggerimento appena qualcuno riformulava una frase.
 *
 * ⛔ **E il messaggio non si mostra MAI a chi si è registrato.** Dice che
 * un'email appartiene già a un amministratore, o che un piano è sparito dal
 * listino: sono informazioni operative, e questa è una superficie pubblica. Chi
 * ha pagato legge «stiamo completando, ti scriviamo noi»; il testo vero va nel
 * log e nel registro di audit.
 */
class RegistrazioneRifiutata extends RuntimeException
{
    /** Il piano memorizzato non è (più) a listino: nessun account può nascere su di esso. */
    public const PIANO_FUORI_CATALOGO = 'piano_fuori_catalogo';

    /**
     * 🔴 La casella non è mai stata confermata.
     *
     * ⚠️ **Esiste perché la guardia non può vivere nel solo controller.** Il
     * ritorno del browser passa da `RegistrazionePubblica::versoStripe()`, che
     * la impone prima di aprire il checkout; il **webhook** chiama
     * `CompletaRegistrazione` direttamente, e su quella strada non c'è nessun
     * altro che guardi. Una regola presidiata in un punto solo dei due è una
     * regola che vale per metà del traffico.
     */
    public const NON_VERIFICATA = 'non_verificata';

    /**
     * Il provisioning ha detto di no — quasi sempre `GIA_AMMINISTRA`: fra il
     * modulo e il pagamento qualcuno ha creato un account con quella email.
     */
    public const PROVISIONING_RIFIUTATO = 'provisioning_rifiutato';

    /**
     * I dati raccolti dal Payment Link non bastano a far nascere un account
     * (🔗 ADR-039): ragione sociale, referente o email mancanti o inutilizzabili.
     *
     * ⚠️ **È un rifiuto e non un errore di programma**, e la differenza è dove
     * finisce: qui la traccia è quella di ogni rifiuto — issue nel tracker e
     * riga di audit — mentre lasciarlo diventare un'eccezione qualunque nel
     * webhook significherebbe un 500, cioè Stripe che ritenta per giorni e poi
     * **disabilita l'endpoint**, portandosi via anche i lockout per insoluto.
     */
    public const DATI_INSUFFICIENTI = 'dati_insufficienti';

    /**
     * Una seconda sessione pagata per una registrazione **già completata**: due
     * checkout aperti dalla stessa riga, entrambi pagati. L'account c'è, il
     * secondo abbonamento è orfano e va annullato e rimborsato a mano.
     */
    public const GIA_COMPLETATA = 'gia_completata';

    /**
     * Checkout chiuso **senza incasso** (`no_payment_required`: coupon al 100%
     * o prova). Nessun account nasce: se il caso vada onorato lo decide una
     * persona, e questo codice glielo porta nel tracker.
     */
    public const SENZA_PAGAMENTO = 'senza_pagamento';

    public function __construct(string $messaggio, public readonly string $codice)
    {
        parent::__construct($messaggio);
    }
}
