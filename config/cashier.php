<?php

use Laravel\Cashier\Invoices\DompdfInvoiceRenderer;

// use Laravel\Cashier\Invoices\LaravelPdfInvoiceRenderer;

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe Keys
    |--------------------------------------------------------------------------
    |
    | The Stripe publishable key and secret key give you access to Stripe's
    | API. The "publishable" key is typically used when interacting with
    | Stripe.js while the "secret" key accesses private API endpoints.
    |
    */

    'key' => env('STRIPE_KEY'),

    'secret' => env('STRIPE_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Cashier Path
    |--------------------------------------------------------------------------
    |
    | This is the base URI path where Cashier's views, such as the payment
    | verification screen, will be available from. You're free to tweak
    | this path according to your preferences and application design.
    |
    */

    'path' => env('CASHIER_PATH', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Stripe Webhooks
    |--------------------------------------------------------------------------
    |
    | Your Stripe webhook secret is used to prevent unauthorized requests to
    | your Stripe webhook handling controllers. The tolerance setting will
    | check the drift between the current time and the signed request's.
    |
    */

    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),

        /*
         * I SOLI eventi che ci interessano, contro gli 8 di
         * `WebhookCommand::DEFAULT_EVENTS`. È l'unica ragione per cui questo
         * file è pubblicato: `cashier:webhook` non ha un'opzione `--events`,
         * quindi restringere l'elenco passa per forza di qui.
         *
         * Perché restringere. `cashier:webhook` registra su Stripe gli eventi
         * che legge da questa chiave, e ogni evento registrato arriva a un
         * handler del pacchetto che gira DENTRO la richiesta del webhook:
         * `handleCustomerUpdated` e `handlePaymentMethodAutomaticallyUpdated`
         * chiamano `updateDefaultPaymentMethodFromStripe()`, cioè un
         * round-trip verso Stripe mentre Stripe aspetta la nostra risposta.
         * L'handler resta sincrono per scelta (ADR-013: un lockout perso è un
         * cliente moroso che continua a lavorare, e un webhook già confermato
         * 200 non torna), e la contropartita di quella scelta è tenere corta
         * la lista di ciò che può arrivare.
         *
         * `invoice.payment_failed` non c'è, e non è una dimenticanza: il
         * sollecito è di Stripe (dunning), noi blocchiamo solo quando Stripe
         * si arrende — `unpaid`/`canceled` — che arriva come
         * `customer.subscription.updated`. Vedi StripeWebhookController.
         */
        'events' => [
            /*
             * 🔴 Il self-signup pubblico (ADR-012): la rete che chiude il caso
             * «ha pagato e ha chiuso la scheda».
             *
             * Il ritorno del browser da Stripe non e' garantito — una
             * connessione che cade, una scheda chiusa, un telefono che si
             * spegne — e senza questo evento quel cliente avrebbe **pagato per
             * niente**: nessun account, nessun utente, nessuna email, e una
             * riga `registrazioni` che si pota da sola a trenta giorni. Il
             * gesto e' lo stesso del ritorno via browser
             * (`App\Support\Registrazione\CompletaRegistrazione`) ed e'
             * idempotente, quindi le due strade non si pestano i piedi.
             *
             * ⚠️ **Cashier NON ha un `handleCheckoutSessionCompleted`**: il
             * dispatch del parent finirebbe in `missingMethod()` con un 200
             * muto. L'handler e' scritto nel NOSTRO `StripeWebhookController` e
             * non chiama `parent::` — non esiste.
             *
             * ⚠️ Aggiungerlo qui non basta sull'ambiente: `cashier:webhook` va
             * **rilanciato**, o l'endpoint registrato su Stripe continua ad
             * ascoltare i soli tre eventi di prima. Un endpoint creato a mano
             * in dashboard non si aggiorna da se'.
             */
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | This is the default currency that will be used when generating charges
    | from your application. Of course, you are welcome to use any of the
    | various world currencies that are currently supported via Stripe.
    |
    */

    'currency' => env('CASHIER_CURRENCY', 'usd'),

    /*
    |--------------------------------------------------------------------------
    | Currency Locale
    |--------------------------------------------------------------------------
    |
    | This is the default locale in which your money values are formatted in
    | for display. To utilize other locales besides the default en locale
    | verify you have the "intl" PHP extension installed on the system.
    |
    */

    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Payment Confirmation Notification
    |--------------------------------------------------------------------------
    |
    | If this setting is enabled, Cashier will automatically notify customers
    | whose payments require additional verification. You should listen to
    | Stripe's webhooks in order for this feature to function correctly.
    |
    */

    /*
     * `null` esplicito, e non `env('CASHIER_PAYMENT_NOTIFICATION')`: con una
     * notifica configurata, `handleInvoicePaymentActionRequired` farebbe un
     * `paymentIntents->retrieve()` dentro la richiesta del webhook. In V1 non
     * c'è alcun flusso on-session (l'unica sottoscrizione passa da
     * `easylab:abbona`, in console), quindi la notifica non avrebbe nemmeno
     * un destinatario sensato. Il giorno che servirà, sarà una decisione con
     * il suo ADR — non una variabile d'ambiente accesa per sbaglio.
     */
    'payment_notification' => null,

    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    |
    | The following options determine how Cashier invoices are converted from
    | HTML into PDFs. You're free to change the options based on the needs
    | of your application or your preferences regarding invoice styling.
    |
    */

    'invoices' => [
        // Supported: DompdfInvoiceRenderer::class, LaravelPdfInvoiceRenderer::class
        'renderer' => env('CASHIER_INVOICE_RENDERER', DompdfInvoiceRenderer::class),

        'options' => [
            // Supported: 'letter', 'legal', 'A4'
            'paper' => env('CASHIER_PAPER', 'letter'),

            'remote_enabled' => env('CASHIER_REMOTE_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Logger
    |--------------------------------------------------------------------------
    |
    | This setting defines which logging channel will be used by the Stripe
    | library to write log messages. You are free to specify any of your
    | logging channels listed inside the "logging" configuration file.
    |
    */

    'logger' => env('CASHIER_LOGGER'),

];
