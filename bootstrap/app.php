<?php

use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\EnforceAccountLockout;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\RequireSameOriginNavigation;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerificaFirmaWebhookStripe;
use App\Support\Errori\CatturaErrori;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // 🔴 Webhook Stripe (ADR-013): l'innesco automatico del lockout.
            //
            // Registrata QUI e non in routes/web.php per una ragione misurata,
            // non estetica: tutto ciò che sta in web.php eredita il gruppo
            // `web`, quindi anche `ValidateCsrfToken` — e una POST che arriva
            // da Stripe non porta alcun token. Prima stesura fatta così, e
            // `route:list` ha mostrato `web` fra i middleware: in produzione
            // sarebbe stato un 419 a ogni evento, cioè un lockout che non
            // scatta mai, in silenzio. In `then:` la rotta nasce nuda, che è
            // esattamente come la registrava il pacchetto.
            //
            // `Cashier::ignoreRoutes()` (AppServiceProvider) spegne quelle del
            // pacchetto; questa le sostituisce con l'unica che ci serve, e con
            // la verifica della firma **dichiarata sulla rotta**: il controller
            // di Cashier la aggancia solo se il segreto è configurato —
            // fail-open — mentre qui è incondizionata e visibile in
            // `route:list`. Il middleware è il NOSTRO
            // (`VerificaFirmaWebhookStripe`) e non quello del pacchetto, perché
            // con un segreto vuoto l'HMAC di Stripe è pubblicamente calcolabile
            // e la verifica passerebbe comunque: il perché sta nel suo docblock.
            //
            // Niente `auth`, e niente `account.lockout`: quest'ultimo sarebbe
            // un cane che si morde la coda, perché è proprio l'evento di
            // pagamento riuscito a dover riaprire un account bloccato.
            Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
                ->middleware(VerificaFirmaWebhookStripe::class)
                ->name('cashier.webhook');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'account.lockout' => EnforceAccountLockout::class,
            'two-factor.enforce' => EnsureTwoFactorIsEnabled::class,
        ]);

        // `signed` fuori dalla lista di priorità girava DOPO `SubstituteBindings`
        // del gruppo web: un id inesistente dava 404 e uno esistente 403, cioè
        // le registrazioni si enumeravano. La firma cade prima dell'autenticazione
        // (e quindi del binding), come dichiarano le rotte QR e di registrazione.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ValidateSignature::class,
        );

        // La guardia di stessa origine gira PRIMA di `auth`: altrimenti uno
        // sloggato con un link esterno di impersonazione finiva in `url.intended`
        // e, dopo login e 2FA, il redirect same-origin la eseguiva (T1bA-1).
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: RequireSameOriginNavigation::class,
        );

        // Globale e non `web`: copre anche webhook, `/up` e pagine d'errore.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // 🔴 Error tracker interno (S6 — `App\Support\Errori\CatturaErrori`).
        //
        // Su Laravel Cloud `laravel.log` vive su un disco **effimero e
        // per-replica**, azzerato a ogni deploy e a ogni risveglio da
        // scale-to-zero: un errore visto da un cliente può non lasciare nulla di
        // consultabile. Questa riga lo fa arrivare anche a database, che è
        // l'unico store condiviso e persistente dell'ambiente.
        //
        // ⚠️ **Il type hint `Throwable` è obbligatorio, e la sua assenza non è
        // un guasto silenzioso.** `ReportableHandler::handles()` legge il tipo
        // del primo parametro con `firstClosureParameterTypes()`, che **lancia**
        // se non ne trova: senza `Throwable` ogni eccezione riportabile
        // crasherebbe **prima** di raggiungere il logger, cioè con zero righe in
        // `laravel.log`. Il caso davvero silenzioso è l'opposto — un hint più
        // stretto, che farebbe passare quasi tutto senza dire niente.
        //
        // ⚠️ **Closure a graffe e non arrow function**, e non è stile: un
        // callback che restituisce `false` **interrompe** `reportThrowable()` e
        // il logger di default non riceve più nulla. `cattura()` è `void`,
        // quindi anche una arrow function sarebbe innocua — ma il corpo
        // esplicito rende impossibile trasformare un ritorno in una soppressione
        // per distrazione.
        //
        // Il callback gira **dopo** `shouldntReport()`: la lista degli ignorati
        // del framework si eredita e non si ridichiara. Per la stessa ragione
        // qui non c'è nessun `$exceptions->throttle()` — quello sta *dentro*
        // `shouldntReport()` e strozzerebbe anche `laravel.log`.
        $exceptions->report(function (Throwable $e): void {
            CatturaErrori::cattura($e);
        });
    })->create();
