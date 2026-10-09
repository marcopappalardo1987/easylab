<?php

namespace App\Support\Notifiche;

use App\Models\Account;
use App\Notifications\AccountBloccato;
use App\Notifications\PianoCambiato;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Piani;

/**
 * Le due email che seguono un fatto sull'account: il blocco e il cambio di
 * piano (🔗 ADR-047; ADR-013 il lockout, ADR-045 il piano, ADR-032 l'Account).
 *
 * Le chiamano i metodi di dominio di `Account` (`blocca()`, `bloccaPerStripe()`,
 * `cambiaPiano()`), così ogni strada che produce il fatto — la cabina, il
 * webhook, un comando — produce anche l'avviso, senza che ciascuna debba
 * ricordarselo.
 *
 * Destinatari: i **membri dell'account** (`account_user`), cioè chi ne
 * amministra il rapporto con Easy Lab. Non i Tenant e non i tecnici: il piano
 * e il blocco non sono affar loro.
 *
 * ⚠️ Escono subito se l'email è spenta in piattaforma: nascono spente, e fino
 * ad allora questi due metodi non leggono altro.
 */
final class AvvisiAccount
{
    public static function bloccato(Account $account): void
    {
        if ($account->di_piattaforma || ! InterruttoriEmail::attiva(CatalogoEmail::ACCOUNT_BLOCCATO)) {
            return;
        }

        foreach ($account->membri()->get() as $membro) {
            $membro->notify(new AccountBloccato((string) $account->ragione_sociale));
        }
    }

    public static function pianoCambiato(Account $account, string $da): void
    {
        if ($account->di_piattaforma || ! InterruttoriEmail::attiva(CatalogoEmail::PIANO_CAMBIATO)) {
            return;
        }

        // Le etichette si risolvono ADESSO: fra l'accodamento e la consegna un
        // piano può essere archiviato, e il testo di un fatto già avvenuto non
        // deve cambiare.
        $etichetta = fn (string $codice) => Piani::esiste($codice) ? Piani::etichetta($codice) : $codice;

        foreach ($account->membri()->get() as $membro) {
            $membro->notify(new PianoCambiato(
                ragioneSociale: (string) $account->ragione_sociale,
                da: $etichetta($da),
                a: $etichetta((string) $account->piano),
            ));
        }
    }
}
