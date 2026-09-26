<?php

namespace App\Support\Billing;

use App\Models\Account;

/**
 * L'unica porta verso il Billing Portal ospitato di Stripe (🔗 ADR-032 per
 * l'intestatario del rapporto, ADR-013 per il lockout da cui questa pagina
 * dev'essere raggiungibile).
 *
 * **Perché una classe di una riga esiste.** È una cucitura, non un'astrazione:
 * `AperturaPortaleStripe` deve poter essere provato per intero — autorizzazione,
 * guardie, throttle, `returnUrl`, redirect — senza che la suite parli con
 * Stripe. Legare un finto di questa classe nel container (`$this->app->instance()`)
 * costa una riga; mockare `StripeClient` produrrebbe invece un test che verifica
 * il proprio mock, che è esattamente la ragione per cui `easylab:abbona` non ha
 * un test del percorso felice. La verifica vera resta su staging con le chiavi
 * test, come per il comando.
 *
 * ⛔ **`$returnUrl` è OBBLIGATORIO, e non è pignoleria.** Il default di Cashier
 * (`ManagesCustomer::billingPortalUrl()`) è `route('home')`, e in questa
 * applicazione **non esiste nessuna rotta chiamata `home`**: `config/fortify.php`
 * dichiara `'home' => '/dashboard'`, che è un PERCORSO, non un nome. Rendere il
 * parametro opzionale qui significherebbe che una chiamata distratta lancia
 * `RouteNotFoundException` — cioè un 500 sul bottone che il cliente clicca
 * proprio quando è in difficoltà. La firma è la guardia; un test la ripete.
 *
 * ⚠️ **Nessuna `configuration` fra le opzioni, di proposito.** La configurazione
 * del portale (quali sezioni sono attive: fatture, metodo di pagamento,
 * disdetta; e quale NON lo è: il cambio piano, che ADR-035 porta in una
 * schermata della piattaforma) vive nella dashboard di Stripe ed è **per
 * modalità**: quella di test e quella live sono due oggetti distinti, e
 * configurarne una non configura l'altra. Passarne una da qui vorrebbe dire
 * portare un id di ambiente dentro il codice; si usa la predefinita
 * dell'ambiente, e il come si configura sta in `Setup Repository e Ambienti.md`.
 *
 * Non `final` e senza costruttore: il container la risolve senza binding, e la
 * suite la estende con un finto.
 */
class PortaleStripe
{
    /**
     * L'URL di una sessione di portale per l'account, valida una volta sola.
     *
     * ⛔ Chiamata di RETE: chi la invoca ha già verificato `hasStripeId()` e la
     * presenza della chiave segreta. Senza customer, Cashier lancia
     * `InvalidCustomer::notYetCreated()` — e un account Free non ha customer
     * **per definizione** (ADR-002), quindi è un caso normale da intercettare
     * prima, non un'anomalia da lasciar esplodere.
     */
    public function url(Account $account, string $returnUrl): string
    {
        return $account->billingPortalUrl($returnUrl);
    }
}
