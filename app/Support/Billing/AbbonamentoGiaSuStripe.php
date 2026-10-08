<?php

namespace App\Support\Billing;

use RuntimeException;

/**
 * Stripe ha già un abbonamento in corso per questo customer, e qui non risulta
 * (🔗 ADR-045, ADR-013).
 *
 * Non è un errore del cliente: è lo **specchio locale rimasto indietro** — un
 * webhook in ritardo di qualche secondo dopo un pagamento, oppure webhook che
 * non arrivano affatto. Nel secondo caso ogni clic aprirebbe un checkout in
 * più, e ogni checkout pagato sarebbe una subscription in più fatturata allo
 * stesso cliente. Chi la riceve non apre niente e porta il caso a una persona.
 */
final class AbbonamentoGiaSuStripe extends RuntimeException
{
    public static function per(int|string $accountId, string $subscriptionId, string $stato): self
    {
        return new self(
            "L'account {$accountId} ha già su Stripe l'abbonamento {$subscriptionId} (stato «{$stato}») ".
            'che in locale non risulta in corso: webhook in ritardo o non consegnati. Nessun checkout aperto.'
        );
    }
}
