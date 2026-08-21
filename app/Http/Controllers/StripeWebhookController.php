<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Piani;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'innesco automatico del lockout per insoluto (🔗 ADR-013, ADR-032).
 *
 * 🔴 **Area rossa** della Policy di Code Review: webhook, stato abbonamento e
 * lockout in un solo file. Va letto riga per riga, e i suoi test negativi sono
 * stati scritti prima di questo codice.
 *
 * **Chi blocca e quando.** Il sollecito non lo facciamo noi: Stripe ritenta la
 * carta per giorni (dunning) e manda le proprie email. Noi chiudiamo la porta
 * solo quando Stripe si arrende — `unpaid`, `canceled`, o la subscription
 * cancellata — e la riapriamo appena torna sana. `past_due` NON blocca: è il
 * primo tentativo fallito, e chiudere lì significherebbe sbattere fuori un
 * cliente in regola che ha solo la carta scaduta. `invoice.payment_failed` non
 * è nemmeno fra gli eventi registrati (`config/cashier.php`).
 *
 * ⚠️ **Nessuno scope protegge questo percorso.** Non c'è utente autenticato, e
 * in contesto console/job i global scope si ritirano tutti — `TenantScope`,
 * `DepartmentScope` e anche `GaranziaRicambioPrivacyScope` (🔗 ADR-011 §292).
 * L'**unico** filtro fra l'evento e l'account è l'uguaglianza su `stripe_id`,
 * che per questo è UNIQUE a DB. Un test di non-trapelamento verifica che un
 * evento per l'account A non muova di un bit l'account B.
 *
 * ⚠️ **Sincrono, mai accodato.** Con la managed queue di Laravel Cloud lo
 * scale-to-zero può interrompere un job in corso, e un webhook già confermato
 * 200 non viene ripetuto: un lockout perso è un cliente moroso che continua a
 * lavorare. Il costo è due letture e una scrittura — e la lista degli eventi
 * registrati è corta apposta, per non ereditare gli handler del pacchetto che
 * fanno round-trip verso Stripe dentro la richiesta.
 *
 * **Idempotenza**, senza tabella di eventi: il parent scrive la riga
 * `subscriptions` con `firstOrNew` + `save()` sullo `stripe_id`, e i gesti di
 * `Account` sono no-op quando lo stato è già quello. Una doppia consegna
 * produce una riga sola e una riga di audit sola.
 *
 * *Rischio residuo dichiarato*: Stripe non garantisce l'**ordine** di consegna,
 * quindi un `unpaid` vecchio recapitato dopo un `active` nuovo richiuderebbe un
 * cliente in regola. Compensazione immediata (`easylab:lockout --sblocca`) e
 * diagnosi nella riga di audit, che dice quale evento ha bloccato. Una tabella
 * `stripe_events` è stata valutata e rimandata: costa uno schema e una pulizia
 * per un caso raro e reversibile.
 */
class StripeWebhookController extends CashierWebhookController
{
    /**
     * Gli stati in cui Stripe ha smesso di provarci: qui si chiude.
     */
    private const STATI_CHE_BLOCCANO = [
        StripeSubscription::STATUS_UNPAID,
        StripeSubscription::STATUS_CANCELED,
    ];

    /**
     * Gli stati di un rapporto sano: qui si riapre, e solo qui il piano si
     * riallinea a ciò che il cliente ha davvero comprato.
     */
    private const STATI_SANI = [
        StripeSubscription::STATUS_ACTIVE,
        StripeSubscription::STATUS_TRIALING,
    ];

    /**
     * Il costruttore del parent aggancia `VerifyWebhookSignature` **solo se**
     * `cashier.webhook.secret` è valorizzato: senza segreto l'endpoint
     * accetterebbe payload arbitrari, cioè chiunque ne conosca l'URL potrebbe
     * bloccare account altrui. Fail-open su un percorso che scrive `is_locked`.
     *
     * Da noi il middleware è dichiarato **sulla rotta** (routes/web.php),
     * incondizionato e visibile in `route:list`. Questo costruttore vuoto
     * esiste per non farlo agganciare due volte — e per lasciare scritto il
     * perché, che è l'unica difesa contro un «semplifichiamo» futuro.
     */
    public function __construct()
    {
        // Volutamente vuoto: niente parent::__construct().
    }

    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        // Il parent per primo: è lui a scrivere/aggiornare la riga
        // `subscriptions`, che è lo specchio locale dello stato su Stripe.
        parent::handleCustomerSubscriptionUpdated($payload);

        $dati = $payload['data']['object'] ?? [];
        $account = $this->accountDa($payload);

        if ($account === null) {
            return $this->successMethod();
        }

        $stato = $dati['status'] ?? null;

        if (in_array($stato, self::STATI_CHE_BLOCCANO, true)) {
            $account->bloccaPerStripe($this->motivo($payload, "abbonamento in stato «{$stato}»"));

            return $this->successMethod();
        }

        if (in_array($stato, self::STATI_SANI, true)) {
            $account->sbloccaPerStripe();

            // Il piano si riallinea **solo** su un abbonamento sano. Farlo su
            // ogni evento significherebbe promuovere a `saas` un account la cui
            // subscription non è mai partita (`incomplete`) o è appena scaduta
            // senza pagamento (`incomplete_expired`). `perPrice()` torna null
            // per un price fuori catalogo — creato a mano in dashboard, o di un
            // piano dismesso — e allora il piano non si tocca: un webhook non
            // deve poter scrivere un valore che il catalogo non conosce.
            $piano = Piani::perPrice($dati['items']['data'][0]['price']['id'] ?? null);

            if ($piano !== null) {
                $account->cambiaPiano($piano);
            }
        }

        // `past_due`, `incomplete`, `incomplete_expired`, `paused`: nessun
        // gesto. E il ritorno del parent non si propaga MAI — su
        // `incomplete_expired` il parent restituisce null dopo aver cancellato
        // la subscription, e null diventerebbe una risposta vuota che non dice
        // a Stripe di aver capito.
        return $this->successMethod();
    }

    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        parent::handleCustomerSubscriptionDeleted($payload);

        $account = $this->accountDa($payload);

        if ($account === null) {
            return $this->successMethod();
        }

        $account->bloccaPerStripe($this->motivo($payload, 'abbonamento cancellato'));

        // Il piano decade **alla cancellazione**, che nel flusso ordinario è
        // anche la fine del periodo già pagato: chi disdice resta `active` fino
        // a scadenza (Cashier valorizza `ends_at` e non blocca nulla), e solo
        // allora Stripe manda questo evento. Da qui la scelta di non
        // schedulare niente: il momento giusto ce lo dice Stripe.
        //
        // Le sedi eccedenti non si toccano (grandfathering, vedi
        // `Account::puoAggiungereEnte()`): decade il diritto ad aprirne di
        // nuove, non quelle che esistono.
        $account->cambiaPiano(Piani::predefinito());

        return $this->successMethod();
    }

    /**
     * L'account a cui l'evento si riferisce, o `null` se non lo si può toccare.
     *
     * **Customer sconosciuto → nessuna scrittura e 200.** Non è un caso
     * anomalo: chiavi test e live incrociate, customer creati a mano in
     * dashboard, account rimossi. E soprattutto un 404 o un 500 farebbe
     * ritentare Stripe per giorni e poi **disabilitare l'endpoint**, facendoci
     * perdere anche gli eventi buoni — il danno sarebbe molto peggiore
     * dell'evento ignorato.
     *
     * **Account cestinato → idem.** `Cashier::findBillable()` usa `withTrashed()`
     * sui model che soft-deletano, quindi lo trova; ma non c'è nulla da
     * sospendere, e riscrivere `is_locked` su una riga cestinata produrrebbe una
     * riga di audit per un gesto senza effetto.
     */
    private function accountDa(array $payload): ?Account
    {
        $stripeId = $payload['data']['object']['customer'] ?? null;

        /** @var Account|null $account */
        $account = $this->getUserByStripeId($stripeId);

        if ($account === null) {
            Log::channel(config('cashier.logger'))->warning(
                'Webhook Stripe per un customer sconosciuto: ignorato.',
                ['stripe_id' => $stripeId, 'evento' => $payload['id'] ?? null, 'tipo' => $payload['type'] ?? null]
            );

            return null;
        }

        if ($account->trashed()) {
            Log::channel(config('cashier.logger'))->warning(
                'Webhook Stripe per un account cestinato: ignorato.',
                ['account_id' => $account->id, 'evento' => $payload['id'] ?? null, 'tipo' => $payload['type'] ?? null]
            );

            return null;
        }

        return $account;
    }

    /**
     * L'annotazione interna che finisce in `stripe_lock_reason`: dice quale
     * evento ha chiuso la porta, così un sollecito o un reclamo si ricostruisce
     * senza aprire la dashboard di Stripe.
     *
     * ⚠️ Non si mostra mai al bloccato (ADR-013): `/bloccato` porta un messaggio
     * generico, e il motivo resta uno strumento operativo interno.
     */
    private function motivo(array $payload, string $causa): string
    {
        $subscription = $payload['data']['object']['id'] ?? '?';
        $evento = $payload['id'] ?? '?';

        return "Stripe: {$causa} ({$subscription}, evento {$evento}).";
    }
}
