<?php

namespace App\Support\Listino;

/**
 * Il **Payment Link di Stripe** di ogni piano: il link da mandare a un prospect
 * perché paghi (🔗 ADR-039, ADR-012 il provisioning, ADR-035 il listino).
 *
 * ## 🔴 Perché ora un Payment Link, dopo aver scritto qui che non si poteva
 *
 * Fino al 5 Set 2026 questo file conteneva l'argomento opposto, ed era giusto
 * allora: un plink incassa e la sessione non porta nei metadata la riga
 * `registrazioni`, quindi `checkout.session.completed` non ha niente da
 * completare e nessun Account nasce. **Un pagamento senza consegna.**
 *
 * Tre cose hanno chiuso quel buco, e vanno lette insieme perché nessuna basta
 * da sola:
 *
 * 1. **`name_collection`**: Stripe raccoglie da sé ragione sociale e nome
 *    referente, cioè i due dati che il provisioning esige. Non servono
 *    `custom_fields` — che sarebbero modificabili dalla dashboard, cioè una
 *    superficie di input in più su una strada che porta denaro.
 * 2. **Il piano si risolve dal NOSTRO database**, da `session.payment_link` su
 *    `prezzi_piano` (`RegistrazioneDaPaymentLink`), mai dai metadata: quelli si
 *    riscrivono dalla dashboard, e un piano che arriva dal payload è un piano
 *    che si può regalare.
 * 3. **La password non serve**: l'Admin nasce **invitato**, e accettare il link
 *    firmato *è* la verifica della casella. È ciò che permette di non spegnere
 *    nessuna guardia — vedi il commento in `CompletaRegistrazione::nasci()`.
 *
 * ## Perché una classe e non un metodo del componente
 *
 * ⚠️ `AccessoRegistrazioniGuardrailTest` vieta a **ogni** componente Livewire di
 * nominare una `Registrazione`: le schermate sono autenticate, e `registrazioni`
 * è la tabella senza scope che l'esenzione di ADR-012 ha reso possibile. La
 * regola di vendibilità vive quindi qui, e la cabina chiama un nome che non
 * appartiene a quel namespace.
 */
final class LinkDiPagamento
{
    /**
     * I link di pagamento, per codice di piano — presi dalla riga **corrente**
     * di `prezzi_piano`, che è l'unica che vende oggi.
     *
     * ⚠️ **Nessuna chiamata di rete**: l'URL è a database apposta, perché questa
     * pagina deve restare leggibile con Stripe irraggiungibile — la stessa
     * disciplina che tiene il confronto con Stripe dietro un bottone.
     *
     * ⛔ **`registrazione.aperta` non entra qui**, e non è una dimenticanza: quel
     * flag chiude `/registrati`, che è un'altra porta. Un plink è pubblico e
     * permanente, e l'unico modo di smettere di vendere da un link già spedito è
     * `active: false` su Stripe — spegnerlo in questa pagina non lo chiuderebbe,
     * darebbe solo l'impressione di averlo fatto.
     *
     * @return array<string, string>
     */
    public static function perPiano(): array
    {
        $link = [];

        foreach (app(CatalogoPiani::class)->tutti() as $codice => $piano) {
            $corrente = $piano->prezzi->firstWhere('corrente', true);

            if ($corrente !== null && filled($corrente->stripe_payment_link_url)) {
                $link[$codice] = $corrente->stripe_payment_link_url;
            }
        }

        return $link;
    }
}
