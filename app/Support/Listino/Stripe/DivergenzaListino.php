<?php

namespace App\Support\Listino\Stripe;

/**
 * Una differenza fra ciò che dice il database e ciò che dice Stripe
 * (🔗 ADR-035).
 *
 * ⚠️ **Nessuna riparazione automatica, in nessuna delle due direzioni.** La
 * regola di chi vince è scritta e ristretta: il database locale è la verità per
 * il **dominio** (etichetta, tetto di Enti, ordine, offribilità), Stripe è la
 * verità per il **denaro** (esistenza del Product, `unit_amount`, `currency`,
 * `active`). Una divergenza si **mostra** coi due valori affiancati — la stessa
 * forma del marcatore «personalizzato» di `/piattaforma/ruoli` — e si risolve
 * con un gesto esplicito: «risincronizza», oppure «aggancia il price esistente».
 */
final class DivergenzaListino
{
    public function __construct(
        public string $codicePiano,
        public string $campo,
        public string $locale,
        public string $remoto,
    ) {}
}
