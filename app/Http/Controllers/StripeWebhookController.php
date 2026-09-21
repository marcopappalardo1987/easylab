<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Registrazione;
use App\Support\Piani;
use App\Support\Registrazione\CompletaRegistrazione;
use App\Support\Registrazione\EsitoCheckout;
use App\Support\Registrazione\RegistrazioneDaPaymentLink;
use App\Support\Registrazione\RegistrazioneRifiutata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
    /** Gli stati da cui una subscription di Stripe non torna mai indietro. */
    private const STATI_TERMINALI = [
        StripeSubscription::STATUS_CANCELED,
        StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
    ];

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
     * Da noi il middleware è dichiarato **sulla rotta** (bootstrap/app.php),
     * incondizionato e visibile in `route:list`. Questo costruttore vuoto
     * esiste per non farlo agganciare due volte — e per lasciare scritto il
     * perché, che è l'unica difesa contro un «semplifichiamo» futuro.
     */
    public function __construct()
    {
        // Volutamente vuoto: niente parent::__construct().
    }

    /**
     * Gli eventi che questo controller accetta di trattare sono **quelli
     * dichiarati in `config/cashier.php`**, e chiunque altro riceve un 200
     * senza che accada nulla.
     *
     * ⚠️ **Perché serve, benché la config già li elenchi.** Quella chiave dice
     * a `cashier:webhook` quali eventi *registrare su Stripe*: non filtra nulla
     * in ingresso. Se l'endpoint viene creato **a mano** dalla dashboard — come
     * era su staging, in ascolto su **241 tipi** invece di tre — la
     * restrizione è carta straccia, e arrivano anche gli eventi che gli handler
     * ereditati da Cashier gestiscono per conto loro: `customer.updated` e
     * `payment_method.automatically_updated` chiamano
     * `updateDefaultPaymentMethodFromStripe()`, cioè un round-trip verso Stripe
     * **dentro** la richiesta del webhook, mentre Stripe aspetta la risposta.
     * `customer.deleted` azzererebbe `stripe_id`, staccando l'account da ogni
     * futuro controllo sull'insoluto.
     *
     * La lezione è dove stava la guardia: in un comando che qualcuno può non
     * lanciare, invece che nel codice che riceve. Qui l'elenco vale comunque,
     * qualunque cosa sia stata configurata dall'altra parte.
     *
     * Un elenco **vuoto** non filtra: sarebbe un webhook che ignora tutto in
     * silenzio, cioè di nuovo un lockout che non scatta mai — e quel modo di
     * sbagliare questo blocco lo conosce già.
     */
    public function handleWebhook(Request $request): Response
    {
        $ammessi = config('cashier.webhook.events') ?: [];
        $tipo = json_decode($request->getContent(), true)['type'] ?? null;

        if ($ammessi !== [] && ! in_array($tipo, $ammessi, true)) {
            return $this->successMethod();
        }

        return parent::handleWebhook($request);
    }

    /**
     * 🔴 **La rete del self-signup: «ha pagato e ha chiuso la scheda»**
     * (🔗 ADR-012, ADR-032).
     *
     * Il ritorno del browser da Stripe **non è garantito** — una connessione
     * che cade, una scheda chiusa, un telefono che si spegne — e senza questo
     * handler quel cliente avrebbe pagato per niente: nessun account, nessun
     * utente, nessuna email, e una riga `registrazioni` che si pota da sola a
     * trenta giorni portandosi via la traccia. È la sola metà del percorso che
     * non dipende dal comportamento del browser.
     *
     * ⚠️ **Non chiama `parent::`, e non è una dimenticanza**: Cashier non ha un
     * `handleCheckoutSessionCompleted`, quindi `parent::` non esiste. Senza
     * questo metodo il dispatch finirebbe in `missingMethod()` con un **200
     * muto** — che è la forma di guasto peggiore per un webhook, perché Stripe
     * lo legge come «ricevuto e trattato» e non lo ripete mai più.
     *
     * ⚠️ **L'esito si legge dal PAYLOAD e non da una chiamata a Stripe.** Il
     * payload è già autenticato dall'HMAC verificato in
     * `VerificaFirmaWebhookStripe` (dichiarato sulla rotta, fail-closed), e un
     * `sessions->retrieve()` qui dentro sarebbe un round-trip verso Stripe
     * **mentre Stripe aspetta la nostra risposta** — precisamente ciò per cui
     * `config/cashier.php` tiene corta la lista degli eventi registrati.
     *
     * ## I due `successMethod()` che sembrano una resa e non lo sono
     *
     * **`registrazione_id` assente o inesistente → 200 e nessuna scrittura.**
     * Un 404 o un 500 farebbe ritentare Stripe per giorni e poi
     * **disabilitare l'endpoint**, facendoci perdere anche gli eventi buoni —
     * compresi i lockout per insoluto. È la stessa dottrina di `accountDa()`, e
     * il caso è normale: un checkout aperto a mano dalla dashboard di Stripe non
     * ha i nostri metadata.
     *
     * **`RegistrazioneRifiutata` → 200 e nessun ritentativo.** Il pagamento è
     * già incassato e il rifiuto è **definitivo** (l'email appartiene già a un
     * amministratore, il piano non è più a catalogo): ripetere l'evento darebbe
     * lo stesso esito mille volte, e far ritentare Stripe per giorni costerebbe
     * l'endpoint.
     *
     * 🔴 **Ma un 200 muto su un incasso che non è diventato un account sarebbe
     * un cliente perduto in silenzio**, ed è il difetto che questo blocco ha
     * pagato: l'unica traccia era `laravel.log`, disco effimero e per-replica.
     * Serve un intervento umano, quindi il caso finisce **nel tracker interno e
     * nel registro di audit** — lo scrive `CompletaRegistrazione`, in un posto
     * solo per tutti e due i chiamanti. Il log qui sotto porta in più il
     * messaggio per esteso, che nel registro non entra per privacy.
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        $sessione = $payload['data']['object'] ?? [];
        $registrazioneId = $sessione['metadata']['registrazione_id'] ?? null;

        // ⚠️ **Checkout chiuso senza incasso** (coupon al 100%, prova gratuita):
        // `EsitoCheckout` lo legge come «non pagato» e nessun account nasce, e
        // prima non lo sapeva nessuno. Se sia un caso da onorare lo decide una
        // persona: qui si lascia la traccia nel tracker, non si regala un account.
        if (($sessione['payment_status'] ?? null) === 'no_payment_required') {
            report(new RegistrazioneRifiutata(
                'La sessione '.($sessione['id'] ?? '?').' si è chiusa senza pagamento (no_payment_required): '.
                'nessun account è nato. Verificare coupon o prova sul Payment Link, e creare il tenant a mano se dovuto.',
                RegistrazioneRifiutata::SENZA_PAGAMENTO,
            ));

            Log::channel(config('cashier.logger'))->error(
                'Checkout completato senza pagamento: nessun account creato.',
                ['sessione' => $sessione['id'] ?? null, 'evento' => $payload['id'] ?? null]
            );

            return $this->successMethod();
        }

        if (! is_numeric($registrazioneId)) {
            // 🔴 **La seconda strada: il Payment Link** (ADR-039). Non ha e non
            // può avere `registrazione_id` — la riga non esiste ancora quando
            // qualcuno apre il link — quindi la si sintetizza dai dati che
            // Stripe ha raccolto, e da lì il percorso è identico.
            //
            // ⚠️ Dopo il ramo dei metadata e non prima: una sessione che porta
            // un `registrazione_id` viene dal modulo, e quella riga ha una
            // password scelta e una casella già verificata. Invertire l'ordine
            // ne creerebbe una seconda per lo stesso pagamento.
            return $this->completaDaPaymentLink($sessione, $payload);
        }

        $registrazione = Registrazione::query()->find((int) $registrazioneId);

        if ($registrazione === null) {
            Log::channel(config('cashier.logger'))->warning(
                'Webhook Stripe checkout.session.completed per una registrazione inesistente: ignorato.',
                ['registrazione_id' => $registrazioneId, 'evento' => $payload['id'] ?? null]
            );

            return $this->successMethod();
        }

        try {
            // ⚠️ Risolto dal container e non costruito qui: l'azione è la stessa
            // che usa il ritorno via browser, e due `new` sarebbero due gesti
            // che divergono al primo cambiamento.
            app(CompletaRegistrazione::class)->esegui(
                $registrazione,
                EsitoCheckout::daSessioneStripe($sessione),
            );
        } catch (RegistrazioneRifiutata $e) {
            // ⚠️ Come nel ritorno via browser: la traccia **durevole** (issue nel
            // tracker interno + riga di audit sulla registrazione) la scrive
            // `CompletaRegistrazione::registraIlRifiuto()`, in un posto solo per
            // tutti e due i chiamanti. Qui resta il log col messaggio per
            // esteso, che nel registro non entra per privacy.
            Log::channel(config('cashier.logger'))->error(
                'Registrazione pubblica rifiutata al completamento dal webhook.',
                [
                    'registrazione_id' => $registrazione->getKey(),
                    'codice' => $e->codice,
                    'motivo' => $e->getMessage(),
                    'evento' => $payload['id'] ?? null,
                ]
            );
        }

        return $this->successMethod();
    }

    /**
     * Un pagamento arrivato da un **Payment Link**: si sintetizza la riga e la
     * si consegna alla stessa azione di sempre (🔗 ADR-039).
     *
     * ## ⛔ Perché `catch (Throwable)` qui e non solo `RegistrazioneRifiutata`
     *
     * Perché questo ramo **scrive**, e scrive da testo raccolto su un dominio di
     * terzi: una violazione di lunghezza su Postgres, una collisione sull'unique
     * di `stripe_session_id` sotto doppia consegna simultanea, un guasto
     * qualunque. Ognuna di esse, non catturata, è un **500** — e un 500 qui non
     * è un test rosso: Stripe ritenta per giorni e poi **disabilita
     * l'endpoint**, portandosi via anche `customer.subscription.updated`, cioè i
     * lockout per insoluto. La dottrina di tutto questo controller è che
     * l'endpoint sopravviva; questo ramo la eredita.
     *
     * ⚠️ **Ma non è un `catch` muto**: `report()` porta il caso nel tracker
     * interno, che è l'unico posto da cui una persona può accorgersi di un
     * incasso che non è diventato un account. Il 200 dice a Stripe «ricevuto»,
     * non «andato bene».
     */
    /**
     * 🔴 **Il pagamento differito (SEPA e simili).** Con un metodo asincrono
     * `checkout.session.completed` arriva con `payment_status` «unpaid», e
     * l'incasso arriva dopo con questo evento: senza handler, un cliente che ha
     * pagato restava senza account. Il gesto è lo stesso di `completed`, che è
     * idempotente, e l'oggetto è la stessa `checkout.session` ora «paid».
     *
     * ⚠️ Serve anche l'evento in `config/cashier.php` (`webhook.events`), o
     * `handleWebhook()` lo scarta prima di arrivare qui.
     */
    protected function handleCheckoutSessionAsyncPaymentSucceeded(array $payload): Response
    {
        return $this->handleCheckoutSessionCompleted($payload);
    }

    protected function completaDaPaymentLink(array $sessione, array $payload): Response
    {
        try {
            $registrazione = app(RegistrazioneDaPaymentLink::class)->sintetizza($sessione);

            // Il plink non è nostro: qualcuno l'ha creato a mano dalla
            // dashboard. Caso legittimo, stessa risposta del `registrazione_id`
            // assente — un checkout che non ci riguarda.
            if ($registrazione === null) {
                return $this->successMethod();
            }

            app(CompletaRegistrazione::class)->esegui(
                $registrazione,
                EsitoCheckout::daSessioneStripe($sessione),
            );
        } catch (RegistrazioneRifiutata $e) {
            // ⛔ **`report()` anche qui, e non è un doppione.** Per i rifiuti che
            // nascono dentro `CompletaRegistrazione` la traccia durevole c'è
            // già; ma `DATI_INSUFFICIENTI` viene sollevato **prima che una riga
            // esista**, quindi `registraIlRifiuto()` non lo vede mai — e senza
            // questa riga un incasso senza account resterebbe nel solo
            // `laravel.log`, che è disco effimero e per-replica. Il tracker
            // raggruppa per impronta, quindi il caso già tracciato diventa
            // un'occorrenza in più della stessa issue, non una seconda.
            report($e);

            // Il messaggio per esteso, che nel registro di audit non entra per
            // privacy.
            Log::channel(config('cashier.logger'))->error(
                'Pagamento da Payment Link rifiutato al completamento.',
                [
                    'sessione' => $sessione['id'] ?? null,
                    'codice' => $e->codice,
                    'motivo' => $e->getMessage(),
                    'evento' => $payload['id'] ?? null,
                ]
            );
        } catch (Throwable $e) {
            // ⛔ L'incasso c'è e l'account no: è il caso che **richiede una
            // persona**, quindi va nel tracker e non solo nel log, che è disco
            // effimero e per-replica.
            report($e);

            Log::channel(config('cashier.logger'))->error(
                'Pagamento da Payment Link non completato per un guasto.',
                [
                    'sessione' => $sessione['id'] ?? null,
                    'motivo' => $e->getMessage(),
                    'evento' => $payload['id'] ?? null,
                ]
            );
        }

        return $this->successMethod();
    }

    /**
     * ⚠️ **`created` conta quanto `updated`, e scoprirlo è costato un giro su
     * staging.** Un cliente che aveva disdetto e torna a pagare produce un
     * `customer.subscription.created`, non un `updated`: senza questo handler
     * restava **bloccato e sul piano `free`** benché stesse pagando — cioè il
     * peggior errore possibile per questo blocco, un cliente in regola chiuso
     * fuori. Il difetto non era coperto da nessun test perché i test erano
     * scritti sul flusso che immaginavo, dove si disdice e basta.
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        if ($this->eventoSuperato($payload)) {
            return $this->successMethod();
        }

        parent::handleCustomerSubscriptionCreated($payload);

        $risposta = $this->applicaStato($payload);
        $this->ricordaEvento($payload);

        return $risposta;
    }

    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        // Il parent per primo: è lui a scrivere/aggiornare la riga
        // `subscriptions`, che è lo specchio locale dello stato su Stripe.
        // 🔴 Prima anche del parent: un evento superato non deve riscrivere
        // nemmeno lo specchio locale (`subscriptions.stripe_status`).
        if ($this->eventoSuperato($payload)) {
            return $this->successMethod();
        }

        parent::handleCustomerSubscriptionUpdated($payload);

        $risposta = $this->applicaStato($payload);
        $this->ricordaEvento($payload);

        return $risposta;
    }

    /**
     * 🔴 **Un evento più vecchio dell'ultimo applicato non si applica.** Stripe
     * non garantisce l'ordine e ritenta un evento fallito per giorni: un
     * «active» di ieri che arriva dopo l'«unpaid» di oggi riapriva un account
     * insoluto. Si confronta il `created` dell'evento (epoch di Stripe) con
     * quello dell'ultimo applicato alla stessa subscription.
     *
     * ⚠️ Strettamente minore: due eventi nello stesso secondo si applicano
     * entrambi, come prima — **tranne dopo una chiusura**. `canceled` e
     * `incomplete_expired` sono stati TERMINALI su Stripe (un cliente che torna
     * riceve una subscription nuova, con un id nuovo): un evento non-`deleted`
     * dello stesso secondo, recapitato dopo il `deleted`, è per forza più vecchio
     * della chiusura, e riaprirebbe un account chiuso (caccia T1bB-2, security
     * pass S7). Senza `created`, o senza storia sulla riga, si applica: è il
     * comportamento di sempre, e non si scarta ciò che non si sa datare.
     */
    private function eventoSuperato(array $payload): bool
    {
        $creato = $payload['created'] ?? null;
        $subscriptionId = $payload['data']['object']['id'] ?? null;

        if (! is_int($creato) || ! is_string($subscriptionId)) {
            return false;
        }

        $riga = Cashier::$subscriptionModel::query()
            ->where('stripe_id', $subscriptionId)
            ->first(['stripe_status', 'creazione_ultimo_evento_stripe']);
        $ultimo = $riga?->creazione_ultimo_evento_stripe;

        if ($ultimo === null || $creato > (int) $ultimo) {
            return false;
        }

        $pariDopoLaChiusura = $creato === (int) $ultimo
            && in_array($riga->stripe_status, self::STATI_TERMINALI, true)
            && ($payload['type'] ?? null) !== 'customer.subscription.deleted';

        if ($creato === (int) $ultimo && ! $pariDopoLaChiusura) {
            return false;
        }

        Log::channel(config('cashier.logger'))->warning(
            'Webhook Stripe più vecchio dell\'ultimo applicato alla subscription: ignorato.',
            ['subscription' => $subscriptionId, 'evento' => $payload['id'] ?? null, 'tipo' => $payload['type'] ?? null]
        );

        return true;
    }

    /**
     * Annota il `created` dell'evento appena applicato. Solo in avanti: il
     * `WHERE` impedisce a una consegna concorrente più vecchia di arretrarlo.
     */
    private function ricordaEvento(array $payload): void
    {
        $creato = $payload['created'] ?? null;
        $subscriptionId = $payload['data']['object']['id'] ?? null;

        if (! is_int($creato) || ! is_string($subscriptionId)) {
            return;
        }

        Cashier::$subscriptionModel::query()
            ->where('stripe_id', $subscriptionId)
            ->where(fn ($q) => $q->whereNull('creazione_ultimo_evento_stripe')
                ->orWhere('creazione_ultimo_evento_stripe', '<', $creato))
            ->update(['creazione_ultimo_evento_stripe' => $creato]);
    }

    /**
     * La mappa stato → gesto, condivisa da `created` e `updated`: è la stessa
     * domanda («questo abbonamento è sano o è finito?») e va risposta allo
     * stesso modo, o le due strade divergono al primo cambiamento.
     */
    private function applicaStato(array $payload): Response
    {
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

        // La cancellazione è definitiva e non si scarta mai; si annota, così un
        // «active» più vecchio consegnato dopo non la contraddice.
        $this->ricordaEvento($payload);

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
