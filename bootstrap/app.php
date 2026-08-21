<?php

use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\EnforceAccountLockout;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\VerificaFirmaWebhookStripe;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
